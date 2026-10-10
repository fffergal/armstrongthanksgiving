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
  if (result.status !== 0) throw new Error(result.stderr || result.stdout || 'Could not update the local RSVP fixture.');
  return result.stdout;
}

test.afterEach(({}, testInfo) => {
  const cleanupStartedAt = performance.now();
  const cleanup = spawnSync(process.execPath, [path.join(root, 'scripts/reset-rsvp-test-data.mjs')], {
    cwd: root,
    encoding: 'utf8',
    stdio: 'pipe',
  });
  const cleanupDuration = ((performance.now() - cleanupStartedAt) / 1000).toFixed(2);
  if (cleanup.status !== 0) {
    throw new Error(cleanup.stderr || cleanup.stdout || 'Could not reset local RSVP test data.');
  }
  console.log(`[cleanup][${testInfo.project.name}] RSVP data reset for "${testInfo.title}" in ${cleanupDuration}s`);
});

test('schema upgrades preserve legacy RSVPs and link an owner only after host email confirmation', async ({}, testInfo) => {
  test.skip(testInfo.project.name !== 'rsvp', 'Run database upgrade coverage in the desktop RSVP project.');

  runWpEval("global $wpdb; $user = get_user_by('login', 'guest'); $admin = get_user_by('login', 'admin'); if (! $user || ! $admin) { WP_CLI::error('The account upgrade fixture is missing.'); } delete_option('at_gathering_party_reconciliation_complete'); delete_option('at_gathering_party_reconciliation_reviewed_at'); $table = at_gathering_table(); if ($wpdb->get_var(\"SHOW COLUMNS FROM {$table} LIKE 'children_count'\")) { $wpdb->query(\"ALTER TABLE {$table} DROP COLUMN children_count\"); } $now = current_time('mysql'); $saved = $wpdb->replace($table, array('user_id' => $user->ID, 'status' => 'maybe', 'guest_count' => 3, 'guest_names' => 'Legacy Casey and Morgan', 'dietary' => 'Legacy dietary note', 'foods' => '[]', 'food_amounts' => '{}', 'custom_food' => '', 'notes' => 'Legacy host note', 'created_at' => $now, 'updated_at' => $now)); if (! $saved) { WP_CLI::error('Could not create the legacy RSVP fixture.'); } $roster = at_gathering_roster_table(); $assignments = at_gathering_assignments_table(); $wpdb->delete($roster, array('email_normalized' => 'guest@example.test')); $wpdb->delete($assignments, array('rsvp_user_id' => $user->ID)); $wpdb->insert($roster, array('email_normalized' => 'guest@example.test', 'display_name' => 'Legacy RSVP owner', 'claim_state' => 'invited', 'created_at' => $now, 'updated_at' => $now)); $guest_id = (int) $wpdb->insert_id; at_gathering_ensure_schema(); at_gathering_ensure_schema(); $row = at_gathering_get_rsvp($user->ID); if (! $row || (int) $row->guest_count !== 3 || $row->guest_names !== 'Legacy Casey and Morgan' || $row->dietary !== 'Legacy dietary note' || (int) $row->children_count !== 0) { WP_CLI::error('Repeatable upgrade did not preserve the RSVP or add the child count.'); } if (at_gathering_party_rsvps_enabled()) { WP_CLI::error('Legacy party names did not close the reconciliation gate.'); } wp_set_current_user($admin->ID); $before = $user->user_pass; $roles = (array) $user->roles; $mismatch = at_gathering_link_legacy_rsvp_owner($user->ID, $guest_id, 'other@example.test'); if (! is_wp_error($mismatch) || 'at_owner_email_mismatch' !== $mismatch->get_error_code()) { WP_CLI::error('The host owner-link API accepted a non-matching email.'); } $linked = at_gathering_link_legacy_rsvp_owner($user->ID, $guest_id, ' Guest@Example.Test '); if (is_wp_error($linked)) { WP_CLI::error($linked->get_error_message()); } $user = get_user_by('id', $user->ID); $owner = at_gathering_roster_guest_by_user($user->ID); $assignment = at_gathering_party_assignment_for_guest($guest_id); if (! $owner || (int) $owner->id !== $guest_id || ! $assignment || ! (int) $assignment->is_owner || $before !== $user->user_pass || $roles !== (array) $user->roles) { WP_CLI::error('Host owner linking changed account state or did not create the owner assignment.'); } echo 'repeatable upgrade and host owner-link checks passed';");
});

test('removing an RSVP releases every guest assignment', async ({}, testInfo) => {
  test.skip(testInfo.project.name !== 'rsvp', 'Run database cleanup coverage in the desktop RSVP project.');

  runWpEval("global $wpdb; $user = get_user_by('login', 'guest'); if (! $user) { WP_CLI::error('The account removal fixture is missing.'); } $now = current_time('mysql'); $wpdb->replace(at_gathering_table(), array('user_id' => $user->ID, 'status' => 'yes', 'guest_count' => 1, 'guest_names' => 'Legacy owner', 'children_count' => 0, 'dietary' => '', 'foods' => '[]', 'food_amounts' => '{}', 'custom_food' => '', 'notes' => '', 'created_at' => $now, 'updated_at' => $now)); $wpdb->insert(at_gathering_roster_table(), array('email_normalized' => 'guest@example.test', 'display_name' => 'Legacy owner', 'user_id' => $user->ID, 'claim_state' => 'claimed', 'created_at' => $now, 'updated_at' => $now)); $guest_id = (int) $wpdb->insert_id; $wpdb->insert(at_gathering_assignments_table(), array('rsvp_user_id' => $user->ID, 'guest_id' => $guest_id, 'is_owner' => 1, 'created_at' => $now)); $removed = at_gathering_remove_rsvp_records($user->ID); if (is_wp_error($removed) || ! $removed || at_gathering_get_rsvp($user->ID) || at_gathering_party_assignment_for_guest($guest_id)) { WP_CLI::error('RSVP removal left the RSVP or its guest assignments behind.'); } echo 'RSVP and party assignments removed together';");
});

test('host reconciliation rejects legacy-party changes after the review page loaded', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'rsvp', 'Run party reconciliation coverage in the desktop RSVP project.');

  runWpEval("global $wpdb; $user = get_user_by('login', 'guest'); if (! $user) { WP_CLI::error('The account reconciliation fixture is missing.'); } $now = current_time('mysql'); $wpdb->replace(at_gathering_table(), array('user_id' => $user->ID, 'status' => 'yes', 'guest_count' => 2, 'guest_names' => 'Legacy Casey and Morgan', 'children_count' => 0, 'dietary' => '', 'foods' => '[]', 'food_amounts' => '{}', 'custom_food' => '', 'notes' => '', 'created_at' => $now, 'updated_at' => $now)); delete_option('at_gathering_party_reconciliation_complete'); delete_option('at_gathering_party_reconciliation_review_hash');");
  await logInAsAdmin(page);
  await page.goto('/wp-admin/admin.php?page=at-gathering');
  const reviewHash = await page.locator('[name="at_party_reconcile_hash"]').inputValue();
  expect(reviewHash).toMatch(/^[a-f0-9]{64}$/);

  runWpEval("global $wpdb; $user = get_user_by('login', 'guest'); $wpdb->update(at_gathering_table(), array('guest_names' => 'Legacy Casey, Morgan, and Taylor'), array('user_id' => $user->ID));");
  await page.getByRole('checkbox', { name: /I reviewed the legacy names/ }).check({ force: true });
  await page.getByRole('button', { name: 'Confirm legacy party review' }).click();

  await expect(page.getByText('The legacy party list changed while you were reviewing it. Please review the current names and confirm again.')).toBeVisible();
  expect(reviewHash).not.toBe(await page.locator('[name="at_party_reconcile_hash"]').inputValue());
});

test('a signed-in friend gets the RSVP and potluck form', async ({ page }) => {
  await logIn(page);
  await page.goto('/rsvp/');

  await expect(page.getByRole('heading', { name: 'Will you join us?' })).toBeVisible();
  await expect(page.getByRole('group', { name: 'Attendance' })).toBeVisible();
  await expect(page.getByRole('group', { name: 'What could you bring?' })).toBeVisible();
  await expect(page.getByLabel('Turkey or vegetarian centrepiece')).toHaveCount(0);
  const potluckItems = [
    'Stuffing — vegetarian',
    'Stuffing — non-vegetarian',
    'Mashed potato',
    'Gravy — vegetarian',
    'Gravy — non-vegetarian',
    'Cranberry sauce',
    'Green bean casserole',
    'Sweet potato casserole',
    'Rolls',
    'Carrots + beetroot',
    'Pumpkin pie',
    'Pecan pie',
    'Apple pie',
    'Sweet potato pie',
    'Nut roast',
    'Ham hock',
    'Cheese ball + crackers',
    '7-layer jalapeño dip',
  ];
  for (const item of potluckItems) await expect(page.getByLabel(item, { exact: true })).toBeVisible();
  await expect(page.locator('input[name^="at_food_amounts["]')).toHaveCount(potluckItems.length);
  await expect(page.getByLabel('Turkey')).toHaveCount(0);
  await expect(page.getByRole('group', { name: 'Create your account' })).toHaveCount(0);
  await expect(page.getByText('52 Priestfield Crescent')).toHaveCount(0);
  await expect(page.getByText('One quick form')).toHaveCount(0);
  await expect(page.getByRole('button', { name: /RSVP/ })).toBeVisible();
  await expect(page.getByRole('button', { name: /RSVP/ })).toHaveCSS('background-color', 'rgb(179, 63, 49)');
  await expect(page.getByRole('button', { name: /RSVP/ })).toHaveCSS('-webkit-appearance', 'none');
  const foodAmount = page.locator('input[name^="at_food_amounts["]').first();
  await expect(foodAmount).toHaveAttribute('type', 'number');
  await expect(foodAmount).toHaveAttribute('min', '0');
  const firstFoodRow = page.locator('.at-food-choice').first();
  const bringing = firstFoodRow.getByRole('checkbox', { name: 'Bringing Stuffing — vegetarian' });
  await expect(bringing).toBeVisible();
  await expect(bringing).toHaveAccessibleName('Bringing Stuffing — vegetarian');
  await expect(firstFoodRow.getByText('How many?', { exact: true })).toBeVisible();
  await expect(foodAmount).toBeVisible();
  await bringing.check();
  await expect(foodAmount).toHaveValue('1');
  await bringing.uncheck();
  await expect(foodAmount).toHaveValue('0');
});

test('a host can set a food goal and guests see offered amount over goal', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'rsvp', 'Run the admin workflow once in the desktop RSVP project.');

  await logInAsAdmin(page);
  await page.goto('/wp-admin/admin.php?page=at-gathering');

  const goal = page.getByLabel('Goal for Mashed potato');
  await expect(goal).toBeVisible();
  const originalGoal = await goal.inputValue();
  try {
    await goal.fill('7');
    await page.getByRole('button', { name: 'Save food goals' }).click();
    await expect(page.getByLabel('Goal for Mashed potato')).toHaveValue('7');
    await page.goto('/rsvp/');
    const summary = page.getByLabel('Mashed potato', { exact: true }).locator('..').locator('..').locator('small');
    await expect(summary).toContainText('Claimed: 0');
    await expect(summary).toContainText('Goal: 7');
  } finally {
    await page.goto('/wp-admin/admin.php?page=at-gathering');
    await page.getByLabel('Goal for Mashed potato').fill(originalGoal);
    await page.getByRole('button', { name: 'Save food goals' }).click();
  }
});

test('a host can remove an RSVP without deleting the member account', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'rsvp', 'Run the admin workflow once in the desktop RSVP project.');

  runWpEval("global $wpdb; $user = get_user_by('login', 'guest'); if (! $user) { WP_CLI::error('The seeded guest account is missing.'); } $now = current_time('mysql'); $saved = $wpdb->replace(at_gathering_table(), array('user_id' => $user->ID, 'status' => 'yes', 'guest_count' => 1, 'guest_names' => 'Beta cleanup test RSVP', 'dietary' => '', 'foods' => '[]', 'food_amounts' => '{}', 'custom_food' => '', 'notes' => '', 'created_at' => $now, 'updated_at' => $now)); if (! $saved) { WP_CLI::error('Could not create the RSVP removal fixture.'); }");

  await logInAsAdmin(page);
  await page.goto('/wp-admin/admin.php?page=at-gathering');

  const rsvpRow = page.locator('.at-rsvp-admin-list').getByRole('row').filter({ hasText: 'Beta cleanup test RSVP' });
  await expect(rsvpRow).toBeVisible();
  await rsvpRow.scrollIntoViewIfNeeded();
  await page.screenshot({ path: testInfo.outputPath('admin-rsvp-removal.png') });
  page.once('dialog', (dialog) => dialog.accept());
  await rsvpRow.getByRole('button', { name: 'Remove RSVP' }).click();

  await expect(page.getByText('RSVP removed.')).toBeVisible();
  await expect(page.getByText('Beta cleanup test RSVP')).toHaveCount(0);
  runWpEval("if (! get_user_by('login', 'guest')) { WP_CLI::error('Removing an RSVP also removed the member account.'); }");
});

test('food contributor names are shown to claimed signed-in guests only', async ({ page, browser }) => {
  runWpEval("global $wpdb; $user = get_user_by('login', 'guest'); $now = current_time('mysql'); $wpdb->query('DELETE FROM ' . at_gathering_assignments_table()); $wpdb->delete(at_gathering_roster_table(), array('email_normalized' => $user->user_email)); $wpdb->insert(at_gathering_roster_table(), array('email_normalized' => $user->user_email, 'display_name' => 'RSVP owner', 'user_id' => $user->ID, 'claim_state' => 'claimed', 'created_at' => $now, 'updated_at' => $now)); update_option('at_gathering_party_reconciliation_complete', true); update_option('at_gathering_party_reconciliation_review_hash', at_gathering_party_legacy_names_fingerprint());");
  await logIn(page);
  await page.goto('/rsvp/');
  await page.getByLabel('Gravy — vegetarian', { exact: true }).fill('2');
  await page.getByRole('button', { name: 'Save my RSVP' }).click();
  await page.waitForURL(/\/rsvp-confirmation\/\?at_rsvp=saved/);
  const contributorName = runWpEval("$user = get_user_by('login', 'guest'); echo esc_html($user->display_name);").trim();
  const observerContext = await browser.newContext();
  try {
    const observer = await observerContext.newPage();
    await observer.goto('/rsvp/');
    const summary = observer.getByLabel('Gravy — vegetarian', { exact: true }).locator('..').locator('..').locator('small');
    await expect(summary).toContainText('Claimed: 2');
    await expect(summary).toContainText('Sign in to see who is bringing it');
    await expect(observer.locator('.at-rsvp-app')).not.toContainText(contributorName);
  } finally { await observerContext.close(); }
  await page.goto('/rsvp/');
  const summary = page.getByLabel('Gravy — vegetarian', { exact: true }).locator('..').locator('..').locator('small');
  await expect(summary).toContainText(contributorName);
  await expect(summary).not.toContainText('×');
});

test('the RSVP form remains usable on a phone', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await logIn(page);
  await page.goto('/rsvp/');
  await expect(page.locator('.at-rsvp-app')).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(0);
  const submit = page.getByRole('button', { name: /RSVP/ });
  await expect(submit).toHaveCSS('background-color', 'rgb(179, 63, 49)');
  await expect(submit).toHaveCSS('-webkit-appearance', 'none');
  await submit.scrollIntoViewIfNeeded();
  await expect(submit).toBeInViewport();
});

test('form controls only show the brown focus ring during keyboard navigation', async ({ page }) => {
  await logIn(page);
  await page.goto('/rsvp/');

  const children = page.getByLabel('Children (ages 0–17)');
  await children.click();
  await expect(children).toHaveCSS('outline-style', 'none');

  await page.keyboard.press('Tab');
  await expect(page.locator(':focus')).toHaveCSS('outline-style', 'solid');
  await expect(page.locator(':focus')).toHaveCSS('outline-color', 'rgb(76, 37, 24)');

  const coming = page.getByLabel('I’m coming');
  await coming.click();
  await coming.press('ArrowRight');
  const maybe = page.getByLabel('Maybe');
  await expect(maybe).toBeFocused();
  await expect(maybe).toHaveCSS('outline-style', 'solid');
  await expect(maybe).toHaveCSS('outline-width', '3px');
  await expect(maybe).toHaveCSS('outline-color', 'rgb(76, 37, 24)');

  const signOut = page.getByRole('link', { name: 'Sign out' });
  await signOut.focus();
  await page.keyboard.press('Tab');
  await signOut.focus();
  await expect(signOut).toBeFocused();
  await expect(signOut).toHaveCSS('outline-style', 'solid');
  await expect(signOut).toHaveCSS('outline-width', '3px');
  await expect(signOut).toHaveCSS('outline-color', 'rgb(246, 237, 225)');
});


test('the RSVP form has no overflow at iPad width and hides subscriber admin UI', async ({ page }) => {
  await page.setViewportSize({ width: 768, height: 1024 });
  await logIn(page);
  await page.goto('/rsvp/');
  expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(0);
  await expect(page.locator('#wpadminbar')).toHaveCount(0);
});



test('a standalone signup requests setup without creating an account', async ({ page }, testInfo) => {
  const unique = `${testInfo.project.name.replace(/\W/g, '')}${Date.now()}`.toLowerCase();
  const email = `signup-${unique}@example.test`;
  await page.goto('/signup/');

  await expect(page.getByRole('heading', { name: 'Set up your gathering account' })).toBeVisible();
  await page.getByLabel('Email').fill(email);
  await page.getByRole('button', { name: 'Request setup link' }).click();
  await expect(page.getByRole('status')).toContainText('If an invitation can be set up for that address');
  const accountExists = runWpEval(`echo email_exists('${email}') ? 'yes' : 'no';`).trim();
  expect(accountExists).toBe('no');
});




test('private album access redirects signed-out visitors', async ({ browser }) => {
  const context = await browser.newContext();
  const page = await context.newPage();
  await page.goto('/albums/');
  await expect(page).toHaveURL(/wp-login\.php/);
  await context.close();
});



test('food totals add the offered amount and an RSVP update does not double-count it', async ({ page }) => {
  runWpEval("global $wpdb; $user = get_user_by('login', 'guest'); $now = current_time('mysql'); $wpdb->query('DELETE FROM ' . at_gathering_assignments_table()); $wpdb->delete(at_gathering_roster_table(), array('email_normalized' => $user->user_email)); $wpdb->insert(at_gathering_roster_table(), array('email_normalized' => $user->user_email, 'display_name' => 'RSVP owner', 'user_id' => $user->ID, 'claim_state' => 'claimed', 'created_at' => $now, 'updated_at' => $now)); update_option('at_gathering_party_reconciliation_complete', true); update_option('at_gathering_party_reconciliation_review_hash', at_gathering_party_legacy_names_fingerprint());");
  await logIn(page);
  await page.goto('/rsvp/');
  const gravySummary = page.getByLabel('Gravy — vegetarian', { exact: true }).locator('..').locator('..').locator('small');
  const before = Number.parseInt((await gravySummary.innerText()).match(/Claimed: (\d+)/)?.[1] ?? '0', 10);
  await page.getByLabel('Gravy — vegetarian', { exact: true }).fill('4');
  await page.getByRole('button', { name: 'Save my RSVP' }).click();
  await page.waitForURL(/\/rsvp-confirmation\/\?at_rsvp=saved/);
  await page.goto('/rsvp/');
  await expect(gravySummary).toContainText(`Claimed: ${before + 4}`);
  await page.getByLabel('Gravy — vegetarian', { exact: true }).fill('4');
  await page.getByRole('button', { name: 'Update my RSVP' }).click();
  await page.waitForURL(/\/rsvp-confirmation\/\?at_rsvp=saved/);
  await page.goto('/rsvp/');
  await expect(gravySummary).toContainText(`Claimed: ${before + 4}`);
});
