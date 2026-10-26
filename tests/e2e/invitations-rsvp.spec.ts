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

function clearInvitationFixtures(prefixes: string[]): void {
  const prefixList = `[${prefixes.map(php).join(', ')}]`;
  runWpEval(`global $wpdb; $prefixes = ${prefixList}; $conditions = implode(' OR ', array_fill(0, count($prefixes), 'email_normalized LIKE %s')); $values = array_map(static function ($prefix) { return $prefix . '%'; }, $prefixes); $ids = $wpdb->get_col($wpdb->prepare('SELECT id FROM ' . at_gathering_roster_table() . ' WHERE ' . $conditions, $values)); foreach ($ids as $id) { $wpdb->delete(at_gathering_invitation_sends_table(), array('guest_id' => (int) $id)); } $wpdb->query($wpdb->prepare('DELETE FROM ' . at_gathering_roster_table() . ' WHERE ' . $conditions, $values));`);
}

function clearInvitationRateLimits(): void {
  runWpEval('global $wpdb; $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE \'at_invite_rate_%\'");');
}

test('signup requests use the same public response and direct legacy posts cannot create accounts', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'rsvp', 'Run invitation coverage in the desktop RSVP project.');
  const suffix = `${testInfo.project.name.replace(/\W/g, '')}${Date.now()}`.toLowerCase();
  const invitedEmail = `invite-${suffix}@example.test`;
  const strangerEmail = `stranger-${suffix}@example.test`;
  clearInvitationRateLimits();
  runWpEval(`global $wpdb; $now = current_time('mysql', true); $wpdb->insert(at_gathering_roster_table(), array('email_normalized' => ${php(invitedEmail)}, 'display_name' => 'Invited Friend', 'claim_state' => 'invited', 'created_at' => $now, 'updated_at' => $now));`);

  const responses: string[] = [];
  for (const email of [invitedEmail, strangerEmail]) {
    await page.goto('/signup/');
    await page.getByLabel('Email').fill(email);
    await page.getByRole('button', { name: 'Request setup link' }).click();
    await expect(page.getByRole('status')).toContainText('If you don’t get an email straight away, we’ll send you one soon.');
    responses.push(await page.getByRole('status').innerText());
  }
  expect(responses[0]).toBe(responses[1]);
  const adminMail = JSON.parse(runWpEval('echo wp_json_encode(get_transient("at_gathering_last_test_mail"));')) as { message: string };
  expect(adminMail.message).toContain('Yes, add them to the invite list and send an invitation');
  expect(adminMail.message).toContain('No, decline this request');

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
  runWpEval(`global $wpdb; $wpdb->delete(at_gathering_roster_table(), array('email_normalized' => ${php(invitedEmail)})); $wpdb->delete(at_gathering_roster_table(), array('email_normalized' => ${php(strangerEmail)}));`);
});

test('unlisted signup requests give admins tokenized approve and decline links', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'rsvp', 'Run invitation review coverage in the desktop RSVP project.');
  const suffix = `review${Date.now()}`.toLowerCase();
  const approvedEmail = `approved-${suffix}@example.test`;
  const declinedEmail = `declined-${suffix}@example.test`;
  clearInvitationFixtures(['approved-review', 'declined-review']);
  clearInvitationRateLimits();
  const request = async (email: string) => {
    runWpEval('delete_transient("at_gathering_last_test_mail");');
    await page.goto('/signup/');
    await page.getByLabel('Email').fill(email);
    await page.getByRole('button', { name: 'Request setup link' }).click();
    await expect(page.getByRole('status')).toContainText('If you don’t get an email straight away, we’ll send you one soon.');
    return JSON.parse(runWpEval('echo wp_json_encode(get_transient("at_gathering_last_test_mail"));')) as { to: string | string[]; message: string };
  };
  const linkFor = (mail: { message: string }, decision: 'approve' | 'decline') => {
    const href = mail.message.match(new RegExp(`href="([^"]*decision=${decision}[^"]*)"`))?.[1];
    expect(href).toBeTruthy();
    return href!.replace(/&(?:amp|#0*38|#x0*26);/gi, '&');
  };

  const approvalMail = await request(approvedEmail);
  const approvalLink = linkFor(approvalMail, 'approve');
  expect(approvalMail.message).toContain('expire in 24 hours');
  expect(approvalMail.message).toContain('Yes, add them to the invite list and send an invitation');
  expect(approvalMail.message).toContain('No, decline this request');
  await page.goto(approvalLink);
  await expect(page.getByRole('button', { name: 'Yes, add them to the invite list and send an invitation' })).toBeVisible();
  expect(runWpEval(`$guest = at_gathering_roster_guest_by_email(${php(approvedEmail)}); echo $guest && 'requested' === $guest->claim_state ? 'requested' : 'missing';`).trim()).toBe('requested');
  await page.getByRole('button', { name: 'Yes, add them to the invite list and send an invitation' }).click();
  await expect(page.locator('body')).toContainText('The address was added to the invite list and an invitation email was sent.');
  const approvalState = runWpEval(`global $wpdb; $guest = at_gathering_roster_guest_by_email(${php(approvedEmail)}); $send = $guest ? (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . at_gathering_invitation_sends_table() . ' WHERE guest_id = %d AND sent_at = %s', (int) $guest->id, $guest->invited_at)) : 0; echo $guest && 'invited' === $guest->claim_state && '' === $guest->display_name && '' !== $guest->token_hash && 1 === $send ? 'approved-and-sent' : 'failed';`).trim();
  expect(approvalState).toBe('approved-and-sent');
  const invitationMail = JSON.parse(runWpEval('echo wp_json_encode(get_transient("at_gathering_last_test_mail"));')) as { to: string; message: string };
  expect(invitationMail.to).toBe(approvedEmail);
  expect(invitationMail.message).toContain('at_setup=');
  await logInAsAdmin(page);
  await page.goto('/wp-admin/admin.php?page=at-gathering');
  const approvedRow = page.getByRole('row').filter({ hasText: approvedEmail });
  await expect(approvedRow.getByRole('button', { name: 'Send reminder' })).toBeVisible();
  await approvedRow.getByRole('button', { name: 'Send reminder' }).click();
  await expect(page).toHaveURL(/at_invitation=sent/);
  const resendMail = JSON.parse(runWpEval('echo wp_json_encode(get_transient("at_gathering_last_test_mail"));')) as { to: string; message: string };
  expect(resendMail.to).toBe(approvedEmail);
  expect(resendMail.message).toContain('at_setup=');
  await page.goto(approvalLink);
  await expect(page.locator('body')).toContainText('invalid or has expired');

  const declineMail = await request(declinedEmail);
  const declineLink = linkFor(declineMail, 'decline');
  await page.goto(declineLink);
  await expect(page.getByRole('button', { name: 'No, decline this request' })).toBeVisible();
  expect(runWpEval(`$guest = at_gathering_roster_guest_by_email(${php(declinedEmail)}); echo $guest && 'requested' === $guest->claim_state ? 'requested' : 'missing';`).trim()).toBe('requested');
  await page.getByRole('button', { name: 'No, decline this request' }).click();
  await expect(page.locator('body')).toContainText('The invitation request was declined. The address was not added to the invite list.');
  const declineState = runWpEval(`echo ! at_gathering_roster_guest_by_email(${php(declinedEmail)}) ? 'declined' : 'failed';`).trim();
  expect(declineState).toBe('declined');
  runWpEval(`global $wpdb; $wpdb->delete(at_gathering_roster_table(), array('email_normalized' => ${php(approvedEmail)})); $wpdb->delete(at_gathering_roster_table(), array('email_normalized' => ${php(declinedEmail)}));`);
});

test('invitation rate limits enforce the per-email boundary', async ({}, testInfo) => {
  test.skip(testInfo.project.name !== 'rsvp', 'Run invitation coverage in the desktop RSVP project.');
  const email = `rate-limit-${Date.now()}@example.test`;
  const ip = '198.51.100.42';
  const results = runWpEval(`global $wpdb; $_SERVER['REMOTE_ADDR'] = ${php(ip)}; $email_key = 'at_invite_rate_email_' . hash('sha256', at_gathering_normalize_email(${php(email)})); $ip_key = 'at_invite_rate_ip_' . hash('sha256', ${php(ip)}); $results = array(); for ($i = 0; $i < 4; $i++) { $results[] = at_gathering_invitation_rate_limited(${php(email)}) ? 'limited' : 'allowed'; } $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name IN (%s, %s)", $email_key, $ip_key)); echo implode(',', $results);`).trim();
  expect(results).toBe('allowed,allowed,allowed,limited');
});

test('invitation requests reject formtests.info addresses and filled honeypot fields', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'rsvp', 'Run invitation coverage in the desktop RSVP project.');
  const spamEmail = `request-${Date.now()}@formtests.info`;
  const honeypotEmail = `trap-${Date.now()}@example.test`;
  clearInvitationRateLimits();
  runWpEval('delete_transient("at_gathering_last_test_mail");');

  await page.goto('/signup/');
  await page.getByLabel('Email').fill(spamEmail);
  await page.getByRole('button', { name: 'Request setup link' }).click();
  await expect(page.getByRole('status')).toBeVisible();
  expect(runWpEval(`echo at_gathering_roster_guest_by_email(${php(spamEmail)}) ? 'stored' : 'not-stored';`).trim()).toBe('not-stored');
  expect(runWpEval('echo get_transient("at_gathering_last_test_mail") ? "sent" : "none";').trim()).toBe('none');

  await page.goto('/signup/');
  await page.getByLabel('Email').fill(honeypotEmail);
  await page.locator('input[name="at_website"]').fill('automated submission');
  await page.getByRole('button', { name: 'Request setup link' }).click();
  await expect(page.getByRole('status')).toBeVisible();
  expect(runWpEval(`echo at_gathering_roster_guest_by_email(${php(honeypotEmail)}) ? 'stored' : 'not-stored';`).trim()).toBe('not-stored');
  expect(runWpEval('echo get_transient("at_gathering_last_test_mail") ? "sent" : "none";').trim()).toBe('none');
});

test('existing users become claimed invitees and earlier email invites are linked in place', async ({}, testInfo) => {
  test.skip(testInfo.project.name !== 'rsvp', 'Run invitation migration coverage in the desktop RSVP project.');
  const suffix = `${testInfo.project.name.replace(/\W/g, '')}${Date.now()}`.toLowerCase();
  const preInvitedEmail = `pre-invited-${suffix}@example.test`;
  const existingEmail = `existing-member-${suffix}@example.test`;
  const setupToken = `${'d'.repeat(55)}${Date.now().toString(16)}`.padEnd(64, 'd').slice(0, 64);
  runWpEval(`global $wpdb; $now = current_time('mysql', true); $expires = gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS); $pre_invited_id = wp_insert_user(array('user_login' => at_gathering_unique_login(${php(preInvitedEmail)}), 'user_pass' => 'existing-password-2026', 'user_email' => ${php(preInvitedEmail)}, 'display_name' => 'Pre-invited Member', 'role' => 'subscriber')); $existing_id = wp_insert_user(array('user_login' => at_gathering_unique_login(${php(existingEmail)}), 'user_pass' => 'existing-password-2026', 'user_email' => ${php(existingEmail)}, 'display_name' => 'Existing Member', 'role' => 'subscriber')); if (is_wp_error($pre_invited_id) || is_wp_error($existing_id)) { WP_CLI::error('Could not create migration fixtures.'); } $wpdb->insert(at_gathering_roster_table(), array('email_normalized' => ${php(preInvitedEmail)}, 'display_name' => 'Host supplied name', 'claim_state' => 'invited', 'token_hash' => hash('sha256', ${php(setupToken)}), 'token_expires' => $expires, 'created_at' => $now, 'updated_at' => $now)); $pre_invited_guest_id = (int) $wpdb->insert_id; $wpdb->insert(at_gathering_invitation_sends_table(), array('guest_id' => $pre_invited_guest_id, 'sent_at' => $now)); at_gathering_migrate_existing_users_to_roster();`);

  const migrated = runWpEval(`global $wpdb; $pre = at_gathering_roster_guest_by_email(${php(preInvitedEmail)}); $existing = at_gathering_roster_guest_by_email(${php(existingEmail)}); $pre_user = get_user_by('email', ${php(preInvitedEmail)}); $existing_user = get_user_by('email', ${php(existingEmail)}); $send_count = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . at_gathering_invitation_sends_table() . ' WHERE guest_id = %d', (int) $pre->id)); $guest_count = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . at_gathering_roster_table() . ' WHERE email_normalized = %s', ${php(preInvitedEmail)})); echo $pre && $existing && (int) $pre->user_id === (int) $pre_user->ID && (int) $existing->user_id === (int) $existing_user->ID && 'claimed' === $pre->claim_state && 'claimed' === $existing->claim_state && '' === $pre->token_hash && null === $pre->token_expires && 'Host supplied name' === $pre->display_name && 1 === $send_count && 1 === $guest_count ? 'linked' : 'failed';`).trim();
  expect(migrated).toBe('linked');
  const idempotent = runWpEval(`at_gathering_migrate_existing_users_to_roster(); global $wpdb; echo (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . at_gathering_roster_table() . ' WHERE email_normalized IN (%s, %s)', ${php(preInvitedEmail)}, ${php(existingEmail)}));`).trim();
  expect(idempotent).toBe('2');

  runWpEval(`global $wpdb; foreach (array(${php(preInvitedEmail)}, ${php(existingEmail)}) as $email) { $guest = at_gathering_roster_guest_by_email($email); if ($guest && $guest->user_id) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user((int) $guest->user_id); } if ($guest) { $wpdb->delete(at_gathering_invitation_sends_table(), array('guest_id' => (int) $guest->id)); $wpdb->delete(at_gathering_roster_table(), array('id' => (int) $guest->id)); } }`);
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
  await expect(page.getByRole('status')).toContainText('If you don’t get an email straight away, we’ll send you one soon.');
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
  clearInvitationFixtures(['host-invite-']);
  runWpEval('delete_transient("at_gathering_last_test_mail");');
  await page.getByLabel('Name (optional)', { exact: true }).fill('Host Invited Guest');
  await page.getByLabel('Email', { exact: true }).fill(email);
  await page.getByRole('button', { name: 'Add to invite list' }).click();
  await expect(page).toHaveURL(/at_invitation=saved/);
  expect(runWpEval('echo get_transient("at_gathering_last_test_mail") ? "sent" : "not-sent";').trim()).toBe('not-sent');
  await page.setViewportSize({ width: 390, height: 844 });
  const invitationRow = page.getByRole('row').filter({ hasText: email });
  const invitationActions = invitationRow.locator('td[data-label="Actions"]');
  const invitationName = invitationRow.locator('td[data-label="Name"]');
  await expect(invitationActions.getByRole('button', { name: 'Send invitation' })).toBeVisible();
  expect(await invitationActions.evaluate((cell) => cell.getBoundingClientRect().top)).toBeLessThan(await invitationName.evaluate((cell) => cell.getBoundingClientRect().top));
  expect(await page.locator('.at-invitation-admin-list').evaluate((table) => table.scrollWidth <= table.clientWidth)).toBe(true);
  await invitationRow.getByRole('button', { name: 'Send invitation' }).click();
  await expect(page).toHaveURL(/at_invitation=sent/);
  const firstMail = JSON.parse(runWpEval('echo wp_json_encode(get_transient("at_gathering_last_test_mail"));')) as { to: string; message: string };
  expect(firstMail.to).toBe(email);
  const firstToken = firstMail.message.match(/at_setup=([a-f0-9]{64})/)?.[1];
  expect(firstToken).toBeTruthy();

  await invitationRow.getByRole('button', { name: 'Send reminder' }).click();
  await expect(page).toHaveURL(/at_invitation=sent/);
  const secondMail = JSON.parse(runWpEval('echo wp_json_encode(get_transient("at_gathering_last_test_mail"));')) as { to: string; message: string };
  expect(secondMail.to).toBe(email);
  const secondToken = secondMail.message.match(/at_setup=([a-f0-9]{64})/)?.[1];
  expect(secondToken).toBeTruthy();
  expect(secondToken).not.toBe(firstToken);
  expect(runWpEval(`$guest = at_gathering_roster_guest_by_email(${php(email)}); echo (int) $GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare('SELECT COUNT(*) FROM ' . at_gathering_invitation_sends_table() . ' WHERE guest_id = %d', (int) $guest->id));`).trim()).toBe('2');
  const oldTokenResult = runWpEval(`$result = at_gathering_claim_guest(${php(firstToken!)}, 'Guest', 'cranberry-sauce-2026', 'cranberry-sauce-2026'); echo is_wp_error($result) && 'at_claim_invalid' === $result->get_error_code() ? 'rejected' : 'accepted';`).trim();
  expect(oldTokenResult).toBe('rejected');
  const guestId = Number(runWpEval(`$guest = at_gathering_roster_guest_by_email(${php(email)}); echo (int) $guest->id;`).trim());
  expect(runWpEval(`$admins = get_users(array('role' => 'administrator', 'number' => 1)); $claimed_guest = at_gathering_roster_guest_by_user((int) $admins[0]->ID); echo $claimed_guest && 'claimed' === $claimed_guest->claim_state && ! at_gathering_delete_pending_invitation((int) $claimed_guest->id) ? 'protected' : 'deleted';`).trim()).toBe('protected');
  await invitationRow.getByRole('button', { name: 'Delete invite' }).click();
  await expect(page).toHaveURL(/at_invitation=deleted/);
  await expect(page.getByRole('row').filter({ hasText: email })).toHaveCount(0);
  expect(runWpEval(`global $wpdb; echo (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . at_gathering_invitation_sends_table() . ' WHERE guest_id = %d', ${guestId}));`).trim()).toBe('0');
  clearInvitationFixtures(['host-invite-']);
});

test('host can bulk add email lines and send to everyone who has not signed up', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'rsvp', 'Run invitation management coverage in the desktop RSVP project.');
  const suffix = Date.now();
  const emails = [`bulk-one-${suffix}@example.test`, `bulk-two-${suffix}@example.test`];
  await logInAsAdmin(page);
  await page.goto('/wp-admin/admin.php?page=at-gathering');
  clearInvitationFixtures(['bulk-one-', 'bulk-two-']);
  runWpEval('delete_transient("at_gathering_last_test_mail");');
  await page.getByLabel('Paste guest names and emails').fill(`Bulk One\n${emails[0]}\n${emails[1]}\nbad@`);
  await page.getByRole('button', { name: 'Add emails to invite list' }).click();
  await expect(page).toHaveURL(/at_invitation=bulk_added/);
  await expect(page.getByText('2 guests added. 1 lines skipped. No emails were sent.')).toBeVisible();
  expect(runWpEval('echo get_transient("at_gathering_last_test_mail") ? "sent" : "not-sent";').trim()).toBe('not-sent');
  for (const email of emails) {
    await expect(page.getByRole('row').filter({ hasText: email })).toBeVisible();
  }
  expect(runWpEval(`echo at_gathering_send_claim_link(${php(emails[1])}) ? "sent" : "not-sent";`).trim()).toBe('not-sent');
  expect(runWpEval('echo get_transient("at_gathering_last_test_mail") ? "sent" : "not-sent";').trim()).toBe('not-sent');
  const eligibleCount = Number(runWpEval("global $wpdb; echo $wpdb->get_var('SELECT COUNT(*) FROM ' . at_gathering_roster_table() . \" WHERE claim_state = 'invited' AND display_name <> ''\");").trim());
  await page.getByRole('button', { name: 'Send to everyone on the invite list who hasn’t signed up' }).click();
  await expect(page).toHaveURL(/at_invitation=all_sent/);
  await expect(page.getByText(`Invitation emails sent to ${eligibleCount} of ${eligibleCount} guests who have not signed up.`)).toBeVisible();
  expect(runWpEval(`global $wpdb; $guest = at_gathering_roster_guest_by_email(${php(emails[0])}); echo (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . at_gathering_invitation_sends_table() . ' WHERE guest_id = %d', (int) $guest->id));`).trim()).toBe('1');
  expect(runWpEval(`global $wpdb; $guest = at_gathering_roster_guest_by_email(${php(emails[1])}); echo (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . at_gathering_invitation_sends_table() . ' WHERE guest_id = %d', (int) $guest->id));`).trim()).toBe('0');
  const unnamedRow = page.getByRole('row').filter({ hasText: emails[1] });
  await unnamedRow.getByLabel('Name', { exact: true }).fill('Bulk Two');
  await unnamedRow.getByRole('button', { name: 'Save name' }).click();
  const namedRow = page.getByRole('row').filter({ hasText: emails[1] });
  await namedRow.getByRole('button', { name: 'Send invitation' }).click();
  await expect(page).toHaveURL(/at_invitation=sent/);
  await expect(namedRow.locator('td').nth(4)).toContainText(/\d{4}-\d{2}-\d{2}/);
  clearInvitationFixtures(['bulk-one-', 'bulk-two-']);
});
