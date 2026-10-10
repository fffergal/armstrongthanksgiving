import { expect, test } from '@playwright/test';
import { spawnSync } from 'node:child_process';
import path from 'node:path';
import { logInAsAdmin } from './helpers/auth';

const root = path.resolve(__dirname, '../..');

function runWpEval(code: string): string {
  const result = spawnSync(process.execPath, [path.join(root, 'scripts/wp-env.mjs'), 'run', 'cli', 'wp', 'eval', code], {
    cwd: root,
    encoding: 'utf8',
    stdio: 'pipe',
  });
  if (result.status !== 0) throw new Error(result.stderr || result.stdout || 'Could not update the invitation fixture.');
  return result.stdout;
}

function php(value: string): string {
  return JSON.stringify(value);
}

test('signup requests use the same public response and direct legacy posts cannot create accounts', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'rsvp', 'Run invitation coverage in the desktop RSVP project.');
  const suffix = `${testInfo.project.name.replace(/\W/g, '')}${Date.now()}`.toLowerCase();
  const invitedEmail = `invite-${suffix}@example.test`;
  const strangerEmail = `stranger-${suffix}@example.test`;
  runWpEval(`global $wpdb; $now = current_time('mysql', true); $wpdb->insert(at_gathering_roster_table(), array('email_normalized' => ${php(invitedEmail)}, 'display_name' => 'Invited Friend', 'claim_state' => 'invited', 'created_at' => $now, 'updated_at' => $now));`);

  const responses: string[] = [];
  for (const email of [invitedEmail, strangerEmail]) {
    await page.goto('/signup/');
    await page.getByLabel('Email').fill(email);
    await page.getByRole('button', { name: 'Request setup link' }).click();
    await expect(page.getByRole('status')).toContainText('If an invitation can be set up for that address');
    responses.push(await page.getByRole('status').innerText());
  }
  expect(responses[0]).toBe(responses[1]);

  await page.goto('/wp-admin/admin-post.php');
  const directPost = await page.request.post('/wp-admin/admin-post.php', {
    form: {
      action: 'at_signup',
      at_signup_nonce: 'legacy-direct-post',
      at_display_name: 'Uninvited Account',
      at_email: strangerEmail,
      at_password: 'cranberry-sauce-2026',
      at_password_confirm: 'cranberry-sauce-2026',
    },
    maxRedirects: 0,
  });
  expect(directPost.status()).toBe(302);
  const accountExists = runWpEval(`echo email_exists(${php(strangerEmail)}) ? 'yes' : 'no';`).trim();
  expect(accountExists).toBe('no');
  runWpEval(`global $wpdb; $wpdb->delete(at_gathering_roster_table(), array('email_normalized' => ${php(invitedEmail)}));`);
});

test('setup token creates one password account, is consumed once, and leaves existing accounts unchanged', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'rsvp', 'Run invitation coverage in the desktop RSVP project.');
  const suffix = `${testInfo.project.name.replace(/\W/g, '')}${Date.now()}`.toLowerCase();
  const newEmail = `claim-${suffix}@example.test`;
  const existingEmail = `existing-${suffix}@example.test`;
  const newToken = `${'a'.repeat(55)}${Date.now().toString(16)}`.padEnd(64, 'a').slice(0, 64);
  const existingToken = `${'b'.repeat(55)}${(Date.now() + 1).toString(16)}`.padEnd(64, 'b').slice(0, 64);
  runWpEval(`global $wpdb; $now = current_time('mysql', true); $expires = gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS); $wpdb->insert(at_gathering_roster_table(), array('email_normalized' => ${php(newEmail)}, 'display_name' => 'New Guest', 'claim_state' => 'invited', 'token_hash' => hash('sha256', ${php(newToken)}), 'token_expires' => $expires, 'created_at' => $now, 'updated_at' => $now)); $id = wp_insert_user(array('user_login' => at_gathering_unique_login(${php(existingEmail)}), 'user_pass' => 'existing-password-2026', 'user_email' => ${php(existingEmail)}, 'display_name' => 'Existing account', 'role' => 'editor')); if (is_wp_error($id)) { WP_CLI::error($id->get_error_message()); } $wpdb->insert(at_gathering_roster_table(), array('email_normalized' => ${php(existingEmail)}, 'display_name' => 'Existing Guest', 'claim_state' => 'invited', 'token_hash' => hash('sha256', ${php(existingToken)}), 'token_expires' => $expires, 'created_at' => $now, 'updated_at' => $now));`);

  const missingPassword = runWpEval(`$result = at_gathering_claim_guest(${php(newToken)}, 'New Guest', '', ''); echo is_wp_error($result) && 'at_claim_password_invalid' === $result->get_error_code() ? 'rejected' : 'accepted';`).trim();
  expect(missingPassword).toBe('rejected');

  await page.goto(`/signup/?at_setup=${newToken}`);
  await expect(page.getByRole('heading', { name: 'Set up your account' })).toBeVisible();
  await page.getByLabel('Display name').fill('New Guest');
  await page.getByLabel('Password', { exact: true }).fill('cranberry-sauce-2026');
  await page.getByLabel('Confirm password').fill('cranberry-sauce-2026');
  await page.getByRole('button', { name: 'Set up account' }).click();
  await expect(page.getByRole('status')).toContainText('Your account is ready');
  const created = runWpEval(`$user = get_user_by('email', ${php(newEmail)}); $guest = at_gathering_roster_guest_by_email(${php(newEmail)}); echo $user && $guest && (int) $guest->user_id === (int) $user->ID && 'claimed' === $guest->claim_state && '' === $guest->token_hash ? 'claimed' : 'failed';`).trim();
  expect(created).toBe('claimed');
  const secondClaim = runWpEval(`$result = at_gathering_claim_guest(${php(newToken)}, 'Again', 'cranberry-sauce-2026', 'cranberry-sauce-2026'); echo is_wp_error($result) && 'at_claim_invalid' === $result->get_error_code() ? 'rejected' : 'accepted';`).trim();
  expect(secondClaim).toBe('rejected');

  await page.goto(`/signup/?at_setup=${existingToken}`);
  await expect(page.getByText('Claiming the invitation will link it without changing its password.')).toBeVisible();
  await page.getByLabel('Display name').fill('Existing Guest');
  await page.getByRole('button', { name: 'Set up account' }).click();
  await expect(page.getByRole('status')).toContainText('Your account is ready');
  const preserved = runWpEval(`$user = get_user_by('email', ${php(existingEmail)}); $guest = at_gathering_roster_guest_by_email(${php(existingEmail)}); echo $user && $guest && (int) $guest->user_id === (int) $user->ID && 'editor' === $user->roles[0] && wp_check_password('existing-password-2026', $user->user_pass, $user->ID) ? 'preserved' : 'changed';`).trim();
  expect(preserved).toBe('preserved');

  runWpEval(`global $wpdb; $guest = at_gathering_roster_guest_by_email(${php(newEmail)}); if ($guest && $guest->user_id) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user((int) $guest->user_id); } $guest = at_gathering_roster_guest_by_email(${php(existingEmail)}); if ($guest && $guest->user_id) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user((int) $guest->user_id); } $wpdb->delete(at_gathering_roster_table(), array('email_normalized' => ${php(newEmail)})); $wpdb->delete(at_gathering_roster_table(), array('email_normalized' => ${php(existingEmail)}));`);
});

test('host invitations can be sent and resent with the earlier link invalidated', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'rsvp', 'Run invitation management coverage in the desktop RSVP project.');
  const email = `host-invite-${Date.now()}@example.test`;
  await logInAsAdmin(page);
  await page.goto('/wp-admin/admin.php?page=at-gathering');
  await page.getByLabel('Name', { exact: true }).fill('Host Invited Guest');
  await page.getByLabel('Email', { exact: true }).fill(email);
  await page.getByRole('button', { name: 'Save and send invitation' }).click();
  await expect(page).toHaveURL(/at_invitation=sent/);
  const firstMail = JSON.parse(runWpEval('echo wp_json_encode(get_transient("at_gathering_last_test_mail"));')) as { to: string; message: string };
  expect(firstMail.to).toBe(email);
  const firstToken = firstMail.message.match(/at_setup=([a-f0-9]{64})/)?.[1];
  expect(firstToken).toBeTruthy();

  const invitationRow = page.getByRole('row').filter({ hasText: email });
  await invitationRow.getByRole('button', { name: 'Resend' }).click();
  await expect(page).toHaveURL(/at_invitation=sent/);
  const secondMail = JSON.parse(runWpEval('echo wp_json_encode(get_transient("at_gathering_last_test_mail"));')) as { to: string; message: string };
  expect(secondMail.to).toBe(email);
  const secondToken = secondMail.message.match(/at_setup=([a-f0-9]{64})/)?.[1];
  expect(secondToken).toBeTruthy();
  expect(secondToken).not.toBe(firstToken);
  const oldTokenResult = runWpEval(`$result = at_gathering_claim_guest(${php(firstToken!)}, 'Guest', 'cranberry-sauce-2026', 'cranberry-sauce-2026'); echo is_wp_error($result) && 'at_claim_invalid' === $result->get_error_code() ? 'rejected' : 'accepted';`).trim();
  expect(oldTokenResult).toBe('rejected');
  runWpEval(`global $wpdb; $wpdb->delete(at_gathering_roster_table(), array('email_normalized' => ${php(email)}));`);
});
