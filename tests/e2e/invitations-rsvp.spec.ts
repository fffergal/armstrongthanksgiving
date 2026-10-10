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

test('invitation rate limits enforce the per-email boundary', async ({}, testInfo) => {
  test.skip(testInfo.project.name !== 'rsvp', 'Run invitation coverage in the desktop RSVP project.');
  const email = `rate-limit-${Date.now()}@example.test`;
  const ip = '198.51.100.42';
  const results = runWpEval(`global $wpdb; $_SERVER['REMOTE_ADDR'] = ${php(ip)}; $email_key = 'at_invite_rate_email_' . hash('sha256', at_gathering_normalize_email(${php(email)})); $ip_key = 'at_invite_rate_ip_' . hash('sha256', ${php(ip)}); $results = array(); for ($i = 0; $i < 4; $i++) { $results[] = at_gathering_invitation_rate_limited(${php(email)}) ? 'limited' : 'allowed'; } $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name IN (%s, %s)", $email_key, $ip_key)); echo implode(',', $results);`).trim();
  expect(results).toBe('allowed,allowed,allowed,limited');
});

test('setup token creates one password account, is consumed once, and leaves existing accounts unchanged', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'rsvp', 'Run invitation coverage in the desktop RSVP project.');
  const suffix = `${testInfo.project.name.replace(/\W/g, '')}${Date.now()}`.toLowerCase();
  const newEmail = `claim-${suffix}@example.test`;
  const existingEmail = `existing-${suffix}@example.test`;
  const newToken = `${'a'.repeat(55)}${Date.now().toString(16)}`.padEnd(64, 'a').slice(0, 64);
  const existingToken = `${'b'.repeat(55)}${(Date.now() + 1).toString(16)}`.padEnd(64, 'b').slice(0, 64);
  const existingDraftToken = 'f'.repeat(32);
  runWpEval(`global $wpdb; $now = current_time('mysql', true); $expires = gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS); $wpdb->insert(at_gathering_roster_table(), array('email_normalized' => ${php(newEmail)}, 'display_name' => 'New Guest', 'claim_state' => 'invited', 'token_hash' => hash('sha256', ${php(newToken)}), 'token_expires' => $expires, 'created_at' => $now, 'updated_at' => $now)); $id = wp_insert_user(array('user_login' => at_gathering_unique_login(${php(existingEmail)}), 'user_pass' => 'existing-password-2026', 'user_email' => ${php(existingEmail)}, 'display_name' => 'Existing account', 'role' => 'editor')); if (is_wp_error($id)) { WP_CLI::error($id->get_error_message()); } $wpdb->insert(at_gathering_roster_table(), array('email_normalized' => ${php(existingEmail)}, 'display_name' => 'Existing Guest', 'claim_state' => 'invited', 'token_hash' => hash('sha256', ${php(existingToken)}), 'token_expires' => $expires, 'created_at' => $now, 'updated_at' => $now)); set_transient('at_gathering_rsvp_draft_' . ${php(existingDraftToken)}, array('status' => 'yes', 'guest_count' => 1), 30 * MINUTE_IN_SECONDS);`);

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
  const claimedLoginHref = await page.getByRole('link', { name: 'Sign in with your password' }).getAttribute('href');
  expect(decodeURIComponent(claimedLoginHref || '')).toContain('redirect_to=');
  expect(decodeURIComponent(claimedLoginHref || '')).not.toContain('redirect_to=/wp-admin');
  const secondClaim = runWpEval(`$result = at_gathering_claim_guest(${php(newToken)}, 'Again', 'cranberry-sauce-2026', 'cranberry-sauce-2026'); echo is_wp_error($result) && 'at_claim_invalid' === $result->get_error_code() ? 'rejected' : 'accepted';`).trim();
  expect(secondClaim).toBe('rejected');

  await page.goto(`/signup/?at_setup=${existingToken}&at_rsvp_draft=${existingDraftToken}`);
  await expect(page.getByText('Confirm your details to link your existing account to the invitation.')).toBeVisible();
  await expect(page.getByText('Set up the invitation to link it without changing your password or access.')).toBeVisible();
  await expect(page.getByRole('link', { name: 'Use your existing password to sign in' })).toHaveCount(0);
  await page.getByLabel('Display name').fill('Existing Guest');
  await page.getByRole('button', { name: 'Set up account' }).click();
  await expect(page.getByRole('status')).toContainText('Your account is ready');
  const existingClaimLoginHref = await page.getByRole('link', { name: 'Sign in with your password' }).getAttribute('href');
  expect(decodeURIComponent(existingClaimLoginHref || '')).toContain(`/rsvp/?at_rsvp_draft=${existingDraftToken}`);
  const preserved = runWpEval(`$user = get_user_by('email', ${php(existingEmail)}); $guest = at_gathering_roster_guest_by_email(${php(existingEmail)}); echo $user && $guest && (int) $guest->user_id === (int) $user->ID && 'editor' === $user->roles[0] && wp_check_password('existing-password-2026', $user->user_pass, $user->ID) ? 'preserved' : 'changed';`).trim();
  expect(preserved).toBe('preserved');

  runWpEval(`global $wpdb; $guest = at_gathering_roster_guest_by_email(${php(newEmail)}); if ($guest && $guest->user_id) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user((int) $guest->user_id); } $guest = at_gathering_roster_guest_by_email(${php(existingEmail)}); if ($guest && $guest->user_id) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user((int) $guest->user_id); } $wpdb->delete(at_gathering_roster_table(), array('email_normalized' => ${php(newEmail)})); $wpdb->delete(at_gathering_roster_table(), array('email_normalized' => ${php(existingEmail)})); delete_transient('at_gathering_rsvp_draft_' . ${php(existingDraftToken)});`);
});

test('RSVP draft survives invitation request and new-account setup', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'rsvp', 'Run invitation coverage in the desktop RSVP project.');
  const suffix = `${testInfo.project.name.replace(/\W/g, '')}${Date.now()}`.toLowerCase();
  const email = `handoff-${suffix}@example.test`;
  const draftToken = `${Date.now().toString(36)}${'c'.repeat(32)}`.slice(0, 32);
  runWpEval(`global $wpdb; $now = current_time('mysql', true); delete_transient('at_gathering_last_test_mail'); $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'at_invite_rate_%' OR option_name LIKE '_transient_at_invite_rate_%' OR option_name LIKE '_transient_timeout_at_invite_rate_%'"); $wpdb->insert(at_gathering_roster_table(), array('email_normalized' => ${php(email)}, 'display_name' => 'RSVP Handoff Guest', 'claim_state' => 'invited', 'created_at' => $now, 'updated_at' => $now)); set_transient('at_gathering_rsvp_draft_' . ${php(draftToken)}, array('status' => 'yes', 'children_count' => 2, 'party_guest_ids' => array(), 'dietary' => 'No walnuts', 'food_amounts' => array('Cranberry sauce' => 1), 'custom_food' => 'Handoff mulled cider', 'custom_food_amount' => 1, 'notes' => 'Saved host note'), 30 * MINUTE_IN_SECONDS);`);

  await page.goto(`/signup/?at_rsvp_draft=${draftToken}`);
  const requestLoginHref = await page.getByRole('link', { name: 'Sign in' }).getAttribute('href');
  expect(decodeURIComponent(requestLoginHref || '')).toContain(`/rsvp/?at_rsvp_draft=${draftToken}`);
  await page.getByLabel('Email').fill(email);
  await page.getByRole('button', { name: 'Request setup link' }).click();
  await expect(page.getByRole('status')).toContainText('If an invitation can be set up for that address');
  const capturedMail = JSON.parse(runWpEval('echo wp_json_encode(get_transient("at_gathering_last_test_mail"));')) as { to: string; message: string };
  expect(capturedMail.to).toBe(email);
  expect(capturedMail.message).toContain(`at_rsvp_draft=${draftToken}`);
  const setupToken = capturedMail.message.match(/at_setup=([a-f0-9]{64})/)?.[1];
  expect(setupToken).toBeTruthy();

  await page.goto(`/signup/?at_setup=${setupToken}&at_rsvp_draft=${draftToken}`);
  await page.getByLabel('Display name').fill('RSVP Handoff Guest');
  await page.getByLabel('Password', { exact: true }).fill('first-password-attempt');
  await page.getByLabel('Confirm password').fill('different-password-attempt');
  await page.getByRole('button', { name: 'Set up account' }).click();
  await expect(page).toHaveURL(new RegExp(`/signup/\\?at_setup=${setupToken}.*at_rsvp_draft=${draftToken}`));
  await expect(page.getByRole('alert')).toContainText('We could not finish setting up your account');

  await page.getByLabel('Password', { exact: true }).fill('cranberry-sauce-2026');
  await page.getByLabel('Confirm password').fill('cranberry-sauce-2026');
  await page.getByRole('button', { name: 'Set up account' }).click();
  await expect(page).toHaveURL(new RegExp(`/rsvp/\\?at_rsvp_draft=${draftToken}`));
  await expect(page.getByRole('heading', { name: 'Will you join us?' })).toBeVisible();
  await expect(page.getByLabel('I’m coming')).toBeChecked();
  await expect(page.getByLabel('Children (ages 0–17)')).toHaveValue('2');
  await expect(page.getByLabel('Dietary notes (optional)')).toHaveValue('No walnuts');
  await expect(page.getByLabel('Cranberry sauce', { exact: true })).toHaveValue('1');
  await expect(page.getByLabel('Something else?')).toHaveValue('Handoff mulled cider');
  await expect(page.getByLabel('Amount of something else')).toHaveValue('1');
  await expect(page.getByLabel('Anything else for the hosts? (optional)')).toHaveValue('Saved host note');

  await page.reload();
  await expect(page.getByLabel('I’m coming')).toBeChecked();
  await expect(page.getByLabel('Children (ages 0–17)')).toHaveValue('0');
  await expect(page.getByLabel('Dietary notes (optional)')).toHaveValue('');
  await expect(page.getByLabel('Cranberry sauce', { exact: true })).toHaveValue('0');
  await expect(page.getByLabel('Something else?')).toHaveValue('');
  await expect(page.getByLabel('Amount of something else')).toHaveValue('0');
  await expect(page.getByLabel('Anything else for the hosts? (optional)')).toHaveValue('');

  runWpEval(`global $wpdb; $guest = at_gathering_roster_guest_by_email(${php(email)}); if ($guest && $guest->user_id) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user((int) $guest->user_id); } $wpdb->delete(at_gathering_roster_table(), array('email_normalized' => ${php(email)})); delete_transient('at_gathering_rsvp_draft_' . ${php(draftToken)});`);
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
