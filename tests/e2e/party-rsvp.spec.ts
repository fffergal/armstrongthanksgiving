import { expect, test } from '@playwright/test';
import { spawnSync } from 'node:child_process';
import path from 'node:path';
import { logIn, logInAsAdmin } from './helpers/auth';

const root = path.resolve(__dirname, '../..');

function runWpEval(code: string): string {
  const result = spawnSync(process.execPath, [path.join(root, 'scripts/wp-env.mjs'), 'run', 'cli', 'wp', 'eval', code], {
    cwd: root,
    encoding: 'utf8',
    stdio: 'pipe',
  });
  if (result.status !== 0) throw new Error(result.stderr || result.stdout || 'Could not prepare the party RSVP fixture.');
  return result.stdout;
}

test.describe('party RSVP assignments', () => {
  test.beforeEach(({}, testInfo) => {
    test.skip(testInfo.project.name !== 'rsvp', 'Run party RSVP workflows in the desktop RSVP project.');
  });

  test.afterEach(() => {
    const cleanup = spawnSync(process.execPath, [path.join(root, 'scripts/reset-rsvp-test-data.mjs')], {
      cwd: root,
      encoding: 'utf8',
      stdio: 'pipe',
    });
    if (cleanup.status !== 0) throw new Error(cleanup.stderr || cleanup.stdout || 'Could not reset the local RSVP fixture.');
  });

  test('anonymous visitors do not receive roster choices from page markup or the selector endpoint', async ({ page }) => {
    runWpEval("global $wpdb; $now = current_time('mysql'); $wpdb->delete(at_gathering_roster_table(), array('email_normalized' => 'private-roster@example.test')); $wpdb->insert(at_gathering_roster_table(), array('email_normalized' => 'private-roster@example.test', 'display_name' => 'Private roster choice', 'claim_state' => 'claimed', 'created_at' => $now, 'updated_at' => $now)); update_option('at_gathering_party_reconciliation_complete', true); update_option('at_gathering_party_reconciliation_review_hash', at_gathering_party_legacy_names_fingerprint());");
    await page.goto('/rsvp/');
    await expect(page.locator('.at-party-roster')).toHaveCount(0);
    await expect(page.locator('#at-rsvp-form')).not.toContainText('Private roster choice');
    const nonce = await page.locator('#at-rsvp-form').getAttribute('data-party-roster-nonce');
    const response = await page.request.get(`/wp-admin/admin-ajax.php?action=at_gathering_party_roster_choices&nonce=${nonce}`);
    expect(response.status()).toBe(403);
    expect(await response.text()).not.toContain('Private roster choice');
  });

  test('only claimed guests receive roster choices and the owner is reserved with the party', async ({ page }) => {
    runWpEval("global $wpdb; $owner = get_user_by('login', 'guest'); $other = get_user_by('login', 'admin'); $now = current_time('mysql'); $wpdb->query('DELETE FROM ' . at_gathering_assignments_table()); $wpdb->query('DELETE FROM ' . at_gathering_roster_table()); foreach (array(array($owner, 'RSVP owner'), array($other, 'Party friend')) as $entry) { $wpdb->insert(at_gathering_roster_table(), array('email_normalized' => $entry[0]->user_email, 'display_name' => $entry[1], 'user_id' => $entry[0]->ID, 'claim_state' => 'claimed', 'created_at' => $now, 'updated_at' => $now)); } update_option('at_gathering_party_reconciliation_complete', true); update_option('at_gathering_party_reconciliation_review_hash', at_gathering_party_legacy_names_fingerprint());");
    await logIn(page);
    await page.goto('/rsvp/');
    await expect(page.getByRole('group', { name: 'Adults in your party' })).toBeVisible();
    const rosterNonce = await page.locator('#at-rsvp-form').getAttribute('data-party-roster-nonce');
    const rosterResponse = await page.request.get(`/wp-admin/admin-ajax.php?action=at_gathering_party_roster_choices&nonce=${rosterNonce}`);
    expect(rosterResponse.status()).toBe(200);
    const rosterPayload = await rosterResponse.text();
    expect(rosterPayload).toContain('Party friend');
    expect(rosterPayload).not.toContain('@');
    await expect(page.getByLabel('RSVP owner (you)')).toBeDisabled();
    await expect(page.getByLabel('Party friend')).toBeVisible();
    await page.getByLabel('Party friend').check();
    await page.getByLabel('Children (ages 0–17)').selectOption('2');
    await page.getByRole('radio', { name: 'Maybe' }).check();
    await page.getByRole('button', { name: 'Save my RSVP' }).click();
    await page.waitForURL(/\/rsvp-confirmation\/\?at_rsvp=saved/);
    await expect(page.getByRole('status')).toContainText('Attendance: Maybe');
    await expect(page.getByRole('status')).toContainText('Adults reserved: RSVP owner, Party friend');
    await expect(page.getByRole('status')).toContainText('Children: 2');
    runWpEval("global $wpdb; $owner = get_user_by('login', 'guest'); $rsvp = at_gathering_get_rsvp($owner->ID); $assignments = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . at_gathering_assignments_table() . ' WHERE rsvp_user_id = %d', $owner->ID)); if (!$rsvp || $rsvp->status !== 'maybe' || (int) $rsvp->children_count !== 2 || count($assignments) !== 2) { WP_CLI::error('The saved party did not retain status, children, and both adult reservations.'); } $owner_assignment = array_filter($assignments, function($row) { return (int) $row->is_owner === 1; }); if (count($owner_assignment) !== 1) { WP_CLI::error('The RSVP owner is missing from the unique assignment relation.'); }");
    await page.goto('/rsvp/');
    await expect(page.getByRole('radio', { name: 'Maybe' })).toBeChecked();
    await expect(page.getByLabel('Party friend')).toBeChecked();
    await expect(page.getByLabel('Children (ages 0–17)')).toHaveValue('2');
  });

  test('an unchanged host party save keeps the selected adults reserved', async ({ page }) => {
    runWpEval("global $wpdb; $owner = get_user_by('login', 'guest'); $friend = get_user_by('login', 'admin'); $now = current_time('mysql'); $wpdb->query('DELETE FROM ' . at_gathering_assignments_table()); $wpdb->query('DELETE FROM ' . at_gathering_roster_table()); foreach (array(array($owner, 'RSVP owner'), array($friend, 'Party friend')) as $entry) { $wpdb->insert(at_gathering_roster_table(), array('email_normalized' => $entry[0]->user_email, 'display_name' => $entry[1], 'user_id' => $entry[0]->ID, 'claim_state' => 'claimed', 'created_at' => $now, 'updated_at' => $now)); } $wpdb->replace(at_gathering_table(), array('user_id' => $owner->ID, 'status' => 'yes', 'guest_count' => 2, 'guest_names' => '', 'children_count' => 0, 'dietary' => '', 'foods' => '[]', 'food_amounts' => '{}', 'custom_food' => '', 'notes' => '', 'created_at' => $now, 'updated_at' => $now)); $owner_guest = at_gathering_roster_guest_by_user($owner->ID); $friend_guest = at_gathering_roster_guest_by_user($friend->ID); foreach (array(array($owner_guest->id, 1), array($friend_guest->id, 0)) as $assignment) { $wpdb->insert(at_gathering_assignments_table(), array('rsvp_user_id' => $owner->ID, 'guest_id' => $assignment[0], 'is_owner' => $assignment[1], 'created_at' => $now)); } update_option('at_gathering_party_reconciliation_complete', true); update_option('at_gathering_party_reconciliation_review_hash', at_gathering_party_legacy_names_fingerprint());");
    await logInAsAdmin(page);
    await page.goto('/wp-admin/admin.php?page=at-gathering');
    const row = page.locator('.at-rsvp-admin-list tr').filter({ hasText: 'guest@example.test' });
    const party = row.getByLabel('Adults assigned to this RSVP');
    await expect(party.locator('option:checked')).toHaveText('Party friend');
    await row.getByRole('button', { name: 'Save party' }).click();
    await expect(page.getByText('Party assignments updated.')).toBeVisible();
    runWpEval("global $wpdb; $owner = get_user_by('login', 'guest'); $assignments = $wpdb->get_col($wpdb->prepare('SELECT guest_id FROM ' . at_gathering_assignments_table() . ' WHERE rsvp_user_id = %d ORDER BY guest_id', $owner->ID)); if (count($assignments) !== 2) { WP_CLI::error('An unchanged host save removed an existing adult assignment.'); }");
  });

  test('an unclaimed signed-in guest is handed to setup with the RSVP draft intact', async ({ page }) => {
    test.setTimeout(90000);
    runWpEval("global $wpdb; $owner = get_user_by('login', 'guest'); $wpdb->query('DELETE FROM ' . at_gathering_assignments_table()); $wpdb->delete(at_gathering_roster_table(), array('email_normalized' => $owner->user_email)); $now = current_time('mysql', true); $wpdb->insert(at_gathering_roster_table(), array('email_normalized' => $owner->user_email, 'display_name' => 'RSVP owner', 'claim_state' => 'invited', 'created_at' => $now, 'updated_at' => $now)); update_option('at_gathering_party_reconciliation_complete', true); update_option('at_gathering_party_reconciliation_review_hash', at_gathering_party_legacy_names_fingerprint());");
    await logIn(page);
    await page.goto('/rsvp/');
    await expect(page.locator('.at-party-roster')).toHaveCount(0);
    const rosterNonce = await page.locator('#at-rsvp-form').getAttribute('data-party-roster-nonce');
    const rosterResponse = await page.request.get(`/wp-admin/admin-ajax.php?action=at_gathering_party_roster_choices&nonce=${rosterNonce}`);
    expect(rosterResponse.status()).toBe(403);
    await page.getByLabel('Children (ages 0–17)').selectOption('3');
    await page.getByRole('button', { name: 'Save my RSVP' }).click();
    await page.waitForURL(/\/signup\/\?.*at_setup_result=requested.*at_rsvp_draft=[a-z0-9]+/);
    const draft = new URL(page.url()).searchParams.get('at_rsvp_draft');
    expect(draft).toMatch(/^[a-z0-9]+$/);
    const capturedMail = JSON.parse(runWpEval('echo wp_json_encode(get_transient("at_gathering_last_test_mail"));')) as { to: string; message: string };
    expect(capturedMail.to).toBe('guest@example.test');
    const setupToken = capturedMail.message.match(/at_setup=([a-f0-9]{64})/)?.[1];
    expect(setupToken).toBeTruthy();

    await page.goto(`/signup/?at_setup=${setupToken}&at_rsvp_draft=${draft}`);
    await expect(page.getByText('Confirm your details to link your existing account to the invitation.')).toBeVisible();
    await page.getByLabel('Display name').fill('RSVP owner');
    await page.getByRole('button', { name: 'Set up account' }).click();
    await expect(page.getByRole('status')).toContainText('Your account is ready');
    const claimed = runWpEval("$owner = get_user_by('login', 'guest'); $guest = at_gathering_roster_guest_by_user($owner->ID); echo $guest && 'claimed' === $guest->claim_state ? 'claimed' : 'unclaimed';").trim();
    expect(claimed).toBe('claimed');

    await page.goto(`/rsvp/?at_rsvp_draft=${draft}`);
    await expect(page.getByLabel('Children (ages 0–17)')).toHaveValue('3');
  });

  test('assignment conflict rejects the full update', async ({ page }) => {
    runWpEval("global $wpdb; $owner = get_user_by('login', 'guest'); $admin = get_user_by('login', 'admin'); $now = current_time('mysql'); $wpdb->query('DELETE FROM ' . at_gathering_assignments_table()); $wpdb->query('DELETE FROM ' . at_gathering_roster_table()); foreach (array(array($owner, 'RSVP owner'), array($admin, 'Reserved adult')) as $entry) { $wpdb->insert(at_gathering_roster_table(), array('email_normalized' => $entry[0]->user_email, 'display_name' => $entry[1], 'user_id' => $entry[0]->ID, 'claim_state' => 'claimed', 'created_at' => $now, 'updated_at' => $now)); } $reserved_guest_id = (int) at_gathering_roster_guest_by_user($admin->ID)->id; update_option('at_gathering_party_reconciliation_complete', true); update_option('at_gathering_party_reconciliation_review_hash', at_gathering_party_legacy_names_fingerprint()); $wpdb->insert(at_gathering_assignments_table(), array('rsvp_user_id' => 999999, 'guest_id' => $reserved_guest_id, 'is_owner' => 0, 'created_at' => $now));");
    await logIn(page);
    await page.goto('/rsvp/');
    await page.getByLabel('Reserved adult').check();
    await page.getByLabel('Children (ages 0–17)').selectOption('4');
    await page.getByRole('button', { name: 'Save my RSVP' }).click();
    await expect(page.getByRole('alert')).toContainText('already assigned to another party');
    runWpEval("global $wpdb; $owner = get_user_by('login', 'guest'); if (at_gathering_get_rsvp($owner->ID) || $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . at_gathering_assignments_table() . ' WHERE rsvp_user_id = %d', $owner->ID))) { WP_CLI::error('A conflicting party update partially saved.'); }");
  });

  test('a rejected party conflict restores exactly the attempted guest selection', async ({ page }) => {
    runWpEval("global $wpdb; $owner = get_user_by('login', 'guest'); $now = current_time('mysql'); $wpdb->query('DELETE FROM ' . at_gathering_assignments_table()); $wpdb->query('DELETE FROM ' . at_gathering_roster_table()); $wpdb->insert(at_gathering_roster_table(), array('email_normalized' => $owner->user_email, 'display_name' => 'RSVP owner', 'user_id' => $owner->ID, 'claim_state' => 'claimed', 'created_at' => $now, 'updated_at' => $now)); foreach (array(array('party-friend@example.test', 'Party friend'), array('reserved-adult@example.test', 'Reserved adult')) as $entry) { $wpdb->insert(at_gathering_roster_table(), array('email_normalized' => $entry[0], 'display_name' => $entry[1], 'claim_state' => 'claimed', 'created_at' => $now, 'updated_at' => $now)); } $owner_guest = at_gathering_roster_guest_by_user($owner->ID); $friend = at_gathering_roster_guest_by_email('party-friend@example.test'); $reserved = at_gathering_roster_guest_by_email('reserved-adult@example.test'); $wpdb->replace(at_gathering_table(), array('user_id' => $owner->ID, 'status' => 'yes', 'guest_count' => 2, 'guest_names' => '', 'children_count' => 0, 'dietary' => '', 'foods' => '[]', 'food_amounts' => '{}', 'custom_food' => '', 'notes' => '', 'created_at' => $now, 'updated_at' => $now)); foreach (array(array($owner_guest->id, $owner->ID, 1), array($friend->id, $owner->ID, 0), array($reserved->id, 999999, 0)) as $assignment) { $wpdb->insert(at_gathering_assignments_table(), array('guest_id' => $assignment[0], 'rsvp_user_id' => $assignment[1], 'is_owner' => $assignment[2], 'created_at' => $now)); } update_option('at_gathering_party_reconciliation_complete', true); update_option('at_gathering_party_reconciliation_review_hash', at_gathering_party_legacy_names_fingerprint());");
    await logIn(page);
    await page.goto('/rsvp/');
    await page.getByLabel('Party friend').uncheck();
    await page.getByLabel('Reserved adult').check();
    await page.getByRole('button', { name: 'Update my RSVP' }).click();
    await expect(page.getByRole('alert')).toContainText('already assigned to another party');
    await expect(page.getByLabel('Party friend')).not.toBeChecked();
    await expect(page.getByLabel('Reserved adult')).toBeChecked();
    runWpEval("global $wpdb; $owner = get_user_by('login', 'guest'); $friend = at_gathering_roster_guest_by_email('party-friend@example.test'); $reserved = at_gathering_roster_guest_by_email('reserved-adult@example.test'); $party_ids = array_map('intval', $wpdb->get_col($wpdb->prepare('SELECT guest_id FROM ' . at_gathering_assignments_table() . ' WHERE rsvp_user_id = %d', $owner->ID))); if (count($party_ids) !== 2 || ! in_array((int) $friend->id, $party_ids, true) || in_array((int) $reserved->id, $party_ids, true)) { WP_CLI::error('A rejected edit changed the saved party assignments.'); }");
  });

  test('no responses keep adult reservations and save zero children', async ({ page }) => {
    test.setTimeout(90000);
    runWpEval("global $wpdb; $owner = get_user_by('login', 'guest'); $now = current_time('mysql'); $wpdb->query('DELETE FROM ' . at_gathering_assignments_table()); $wpdb->query('DELETE FROM ' . at_gathering_roster_table()); $wpdb->insert(at_gathering_roster_table(), array('email_normalized' => $owner->user_email, 'display_name' => 'RSVP owner', 'user_id' => $owner->ID, 'claim_state' => 'claimed', 'created_at' => $now, 'updated_at' => $now)); update_option('at_gathering_party_reconciliation_complete', true); update_option('at_gathering_party_reconciliation_review_hash', at_gathering_party_legacy_names_fingerprint());");
    await logIn(page);
    await page.goto('/rsvp/');
    await page.getByRole('radio', { name: 'I can’t make it' }).check();
    await page.getByRole('button', { name: 'Save my RSVP' }).click();
    await page.waitForURL(/\/rsvp-confirmation\/\?at_rsvp=saved/);
    runWpEval("global $wpdb; $owner = get_user_by('login', 'guest'); $rsvp = at_gathering_get_rsvp($owner->ID); $assignment = at_gathering_party_assignment_for_guest((int) at_gathering_roster_guest_by_user($owner->ID)->id); if (!$rsvp || $rsvp->status !== 'no' || (int) $rsvp->children_count !== 0 || !$assignment || (int) $assignment->rsvp_user_id !== (int) $owner->ID) { WP_CLI::error('A no response released its owner or retained children.'); }");
    await page.goto('/rsvp/');
    await expect(page.getByLabel('Children (ages 0–17)')).toBeEnabled();
  });

  test('expected attendees include yes parties only and report maybe parties separately', async ({ page }) => {
    runWpEval("global $wpdb; $owner = get_user_by('login', 'guest'); $maybe_owner = get_user_by('login', 'admin'); $yes_adult_id = wp_insert_user(array('user_login' => 'party-yes-adult', 'user_pass' => wp_generate_password(), 'user_email' => 'party-yes-adult@example.test', 'display_name' => 'Yes party adult', 'role' => 'subscriber')); $no_owner_id = wp_insert_user(array('user_login' => 'party-no-owner', 'user_pass' => wp_generate_password(), 'user_email' => 'party-no-owner@example.test', 'display_name' => 'No party owner', 'role' => 'subscriber')); if (is_wp_error($yes_adult_id) || is_wp_error($no_owner_id)) { WP_CLI::error('Could not create the party count fixtures.'); } $yes_adult_user = get_user_by('id', $yes_adult_id); $no_owner = get_user_by('id', $no_owner_id); $now = current_time('mysql'); $wpdb->query('DELETE FROM ' . at_gathering_assignments_table()); $wpdb->query('DELETE FROM ' . at_gathering_roster_table()); foreach (array(array($owner, 'Yes RSVP owner'), array($yes_adult_user, 'Yes party adult'), array($maybe_owner, 'Maybe RSVP owner'), array($no_owner, 'No RSVP owner')) as $entry) { $wpdb->insert(at_gathering_roster_table(), array('email_normalized' => $entry[0]->user_email, 'display_name' => $entry[1], 'user_id' => $entry[0]->ID, 'claim_state' => 'claimed', 'created_at' => $now, 'updated_at' => $now)); } $yes_guest = at_gathering_roster_guest_by_user($owner->ID); $yes_adult = at_gathering_roster_guest_by_user($yes_adult_user->ID); $maybe_guest = at_gathering_roster_guest_by_user($maybe_owner->ID); $no_guest = at_gathering_roster_guest_by_user($no_owner->ID); foreach (array(array($owner->ID, 'yes', 2), array($maybe_owner->ID, 'maybe', 5), array($no_owner->ID, 'no', 0)) as $party) { $wpdb->replace(at_gathering_table(), array('user_id' => $party[0], 'status' => $party[1], 'guest_count' => 1, 'guest_names' => '', 'children_count' => $party[2], 'dietary' => '', 'foods' => '[]', 'food_amounts' => '{}', 'custom_food' => '', 'notes' => '', 'created_at' => $now, 'updated_at' => $now)); } foreach (array(array($owner->ID, $yes_guest->id, 1), array($owner->ID, $yes_adult->id, 0), array($maybe_owner->ID, $maybe_guest->id, 1), array($no_owner->ID, $no_guest->id, 1)) as $assignment) { $wpdb->insert(at_gathering_assignments_table(), array('rsvp_user_id' => $assignment[0], 'guest_id' => $assignment[1], 'is_owner' => $assignment[2], 'created_at' => $now)); } update_option('at_gathering_party_reconciliation_complete', true); update_option('at_gathering_party_reconciliation_review_hash', at_gathering_party_legacy_names_fingerprint());");
    await logInAsAdmin(page);
    await page.goto('/wp-admin/admin.php?page=at-gathering');
    await expect(page.locator('.at-party-totals')).toHaveText('4 expected attendees · 1 maybe parties · 1 no parties');
    runWpEval("global $wpdb; $no_owner = get_user_by('login', 'party-no-owner'); $guest = at_gathering_roster_guest_by_user($no_owner->ID); if (!$guest || !at_gathering_party_assignment_for_guest((int) $guest->id)) { WP_CLI::error('A no RSVP did not retain its adult assignment.'); }");
  });
});
