import { expect, test } from '@playwright/test';
import { spawnSync } from 'node:child_process';
import path from 'node:path';
import { logIn, logInAsAdmin } from './helpers/auth';

const root = path.resolve(__dirname, '../..');

function runWpEval(code: string): void {
  const result = spawnSync(process.execPath, [path.join(root, 'scripts/wp-env.mjs'), 'run', 'cli', 'wp', 'eval', code], {
    cwd: root,
    encoding: 'utf8',
    stdio: 'pipe',
  });
  if (result.status !== 0) throw new Error(result.stderr || result.stdout || 'Could not update the local RSVP fixture.');
}

test.afterEach(() => {
  const cleanup = spawnSync(process.execPath, [path.join(root, 'scripts/reset-rsvp-test-data.mjs')], {
    cwd: root,
    encoding: 'utf8',
    stdio: 'pipe',
  });
  if (cleanup.status !== 0) {
    throw new Error(cleanup.stderr || cleanup.stdout || 'Could not reset local RSVP test data.');
  }
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

  const rsvpRow = page.getByRole('row').filter({ hasText: 'Beta cleanup test RSVP' });
  await expect(rsvpRow).toBeVisible();
  await rsvpRow.scrollIntoViewIfNeeded();
  await page.screenshot({ path: testInfo.outputPath('admin-rsvp-removal.png') });
  page.once('dialog', (dialog) => dialog.accept());
  await rsvpRow.getByRole('button', { name: 'Remove RSVP' }).click();

  await expect(page.getByText('RSVP removed.')).toBeVisible();
  await expect(page.getByText('Beta cleanup test RSVP')).toHaveCount(0);
  runWpEval("if (! get_user_by('login', 'guest')) { WP_CLI::error('Removing an RSVP also removed the member account.'); }");
});

test('food contributor names are shown to signed-in guests only', async ({ page, browser }, testInfo) => {
  const unique = `${testInfo.project.name.replace(/\W/g, '')}${Date.now()}`.toLowerCase();
  const customFood = `Test cider ${unique}`;
  const guestNames = 'Taylor Test Guest, Casey Test Guest';
  await page.goto('/rsvp/');
  await page.getByLabel('Names', { exact: true }).fill(guestNames);
  await page.getByLabel('Something else?').fill(customFood);
  await page.getByLabel('Amount of something else').fill('3');
  await page.getByLabel('Gravy — vegetarian', { exact: true }).fill('2');
  await page.getByLabel('Display name').fill('Food RSVP Account Owner');
  await page.getByLabel('Email').fill(`custom-food-${unique}@example.test`);
  await page.getByLabel('Password', { exact: true }).fill('cranberry-sauce-2026');
  await page.getByLabel('Confirm password').fill('cranberry-sauce-2026');
  await page.getByRole('button', { name: 'Save my RSVP' }).click();
  await page.waitForURL(/\/rsvp-confirmation\/\?at_rsvp=saved/);

  const observerContext = await browser.newContext();
  try {
    const observer = await observerContext.newPage();
    await observer.goto('/rsvp/');
    await observer.screenshot({ path: testInfo.outputPath('rsvp-sign-in-and-food-desktop.png'), fullPage: true });
    const customFoodList = observer.locator('.at-custom-food-list');
    await expect(customFoodList).toBeVisible();
    const customFoodRow = customFoodList.getByRole('listitem').filter({ hasText: customFood });
    await expect(customFoodRow.getByText(customFood)).toBeVisible();
    await expect(customFoodRow.locator('small')).toHaveText('3 · Sign in to see who');
    await expect(customFoodRow.getByRole('button', { name: 'Sign in to see who' })).toHaveAttribute('name', 'action');
    await expect(customFoodRow.getByRole('button', { name: 'Sign in to see who' })).toHaveAttribute('value', 'at_rsvp_signin');
    await expect(customFoodList).not.toContainText('Food RSVP Account Owner');
    await expect(customFoodList).not.toContainText(guestNames);
    const gravySummary = observer.getByLabel('Gravy — vegetarian', { exact: true }).locator('..').locator('..').locator('small');
    await expect(gravySummary).toContainText('Claimed: 2');
    await expect(gravySummary).toContainText('Goal: not set');
    await expect(gravySummary).toContainText('Sign in to see who is bringing it');
    await expect(gravySummary).not.toContainText('Food RSVP Account Owner');
    await expect(observer.locator('.at-rsvp-app')).not.toContainText(guestNames);
    const gravyRow = observer.locator('.at-food-choice').filter({ hasText: 'Gravy — vegetarian' });
    await gravyRow.scrollIntoViewIfNeeded();
    await observer.screenshot({ path: testInfo.outputPath('rsvp-listed-food-contributor-desktop.png') });
    await customFoodList.scrollIntoViewIfNeeded();
    await observer.screenshot({ path: testInfo.outputPath('rsvp-custom-food-desktop.png') });
    await observer.setViewportSize({ width: 768, height: 1024 });
    await gravyRow.scrollIntoViewIfNeeded();
    await observer.screenshot({ path: testInfo.outputPath('rsvp-listed-food-contributor-tablet.png') });
    await customFoodList.scrollIntoViewIfNeeded();
    await observer.screenshot({ path: testInfo.outputPath('rsvp-custom-food-tablet.png') });
    await observer.setViewportSize({ width: 390, height: 844 });
    await gravyRow.scrollIntoViewIfNeeded();
    await observer.screenshot({ path: testInfo.outputPath('rsvp-listed-food-contributor-mobile.png') });
    await customFoodList.scrollIntoViewIfNeeded();
    await observer.screenshot({ path: testInfo.outputPath('rsvp-custom-food-mobile.png') });

    const signInEmail = `names-handoff-${unique}@example.test`;
    const signInPassword = 'cranberry-sauce-2026';
    await observer.goto('/signup/');
    await observer.getByLabel('Display name').fill('Names Link Friend');
    await observer.getByLabel('Email').fill(signInEmail);
    await observer.getByLabel('Password', { exact: true }).fill(signInPassword);
    await observer.getByLabel('Confirm password').fill(signInPassword);
    await observer.getByRole('button', { name: 'Sign up' }).click();
    await observer.waitForURL(/at_signup=saved/);
    await observer.context().clearCookies();
    await observer.goto('/rsvp/');

    await observer.getByLabel('Names', { exact: true }).fill('Draft from names link');
    await observer.getByLabel('Something else?').fill('Draft cider');
    await observer.getByLabel('Amount of something else').fill('5');
    await customFoodRow.getByRole('button', { name: 'Sign in to see who' }).click();
    await expect(observer).toHaveURL(/wp-login\.php/);
    await observer.locator('#user_login').fill(signInEmail);
    await observer.locator('#user_pass').fill(signInPassword);
    await observer.locator('#wp-submit').click();
    await observer.waitForURL(/\/rsvp\//);
    await expect(observer.getByLabel('Names', { exact: true })).toHaveValue('Draft from names link');
    await expect(observer.getByLabel('Something else?')).toHaveValue('Draft cider');
    await expect(observer.getByLabel('Amount of something else')).toHaveValue('5');
  } finally {
    await observerContext.close();
  }

  const signedInContext = await browser.newContext();
  try {
    const signedIn = await signedInContext.newPage();
    await logIn(signedIn);
    await signedIn.goto('/rsvp/');
    const customFoodList = signedIn.locator('.at-custom-food-list');
    const customFoodRow = customFoodList.getByRole('listitem').filter({ hasText: customFood });
    await expect(customFoodRow.locator('small')).toHaveText(`3 from ${guestNames}`);
    const gravySummary = signedIn.getByLabel('Gravy — vegetarian', { exact: true }).locator('..').locator('..').locator('small');
    await expect(gravySummary).toContainText(`Bringing: ${guestNames}`);
    await expect(gravySummary).not.toContainText('×');
    await expect(signedIn.locator('.at-rsvp-app')).toContainText(guestNames);
    await gravySummary.scrollIntoViewIfNeeded();
    await signedIn.screenshot({ path: testInfo.outputPath('rsvp-listed-food-contributors-desktop.png') });
    await customFoodList.scrollIntoViewIfNeeded();
    await signedIn.screenshot({ path: testInfo.outputPath('rsvp-signed-in-contributors-desktop.png') });
    await signedIn.setViewportSize({ width: 768, height: 1024 });
    await gravySummary.scrollIntoViewIfNeeded();
    await signedIn.screenshot({ path: testInfo.outputPath('rsvp-listed-food-contributors-tablet.png') });
    await customFoodList.scrollIntoViewIfNeeded();
    await signedIn.screenshot({ path: testInfo.outputPath('rsvp-signed-in-contributors-tablet.png') });
    await signedIn.setViewportSize({ width: 390, height: 844 });
    await gravySummary.scrollIntoViewIfNeeded();
    await signedIn.screenshot({ path: testInfo.outputPath('rsvp-listed-food-contributors-mobile.png') });
    await customFoodList.scrollIntoViewIfNeeded();
    await signedIn.screenshot({ path: testInfo.outputPath('rsvp-signed-in-contributors-mobile.png') });
  } finally {
    await signedInContext.close();
  }
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

  const names = page.getByLabel('Names', { exact: true });
  await names.click();
  await expect(names).toHaveCSS('outline-style', 'none');

  await page.keyboard.press('Tab');
  await expect(page.locator(':focus')).toHaveCSS('outline-style', 'solid');
  await expect(page.locator(':focus')).toHaveCSS('outline-color', 'rgb(76, 37, 24)');

  const people = page.getByLabel('How many people are coming?');
  await people.click();
  await expect(people).toHaveCSS('outline-style', 'none');

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

test('a group RSVP explains that other people can sign up separately', async ({ page }) => {
  await page.goto('/rsvp/');
  const hint = page.locator('[data-at-rsvp-group-hint]');

  await expect(hint).toBeHidden();
  await page.getByLabel('How many people are coming?').selectOption('2');
  await expect(hint).toBeVisible();
  await expect(hint).toContainText('sign up');
  await expect(hint).toContainText('double-counts what they’re bringing');

  await page.getByLabel('How many people are coming?').selectOption('1');
  await page.getByLabel('Names', { exact: true }).fill('Fergal and Alex');
  await expect(hint).toBeVisible();
  await page.getByLabel('Names', { exact: true }).fill('Fergal');
  await expect(hint).toBeHidden();
});

test('the RSVP form has no overflow at iPad width and hides subscriber admin UI', async ({ page }) => {
  await page.setViewportSize({ width: 768, height: 1024 });
  await logIn(page);
  await page.goto('/rsvp/');
  expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(0);
  await expect(page.locator('#wpadminbar')).toHaveCount(0);
});

test('an RSVP saves and is still present after reload', async ({ page }) => {
  await logIn(page);
  await page.goto('/rsvp/');

  await page.getByLabel('I’m coming').check();
  await page.getByLabel('How many people are coming?').selectOption('2');
  await page.getByLabel('Names', { exact: true }).fill('Two test friends');
  await page.getByLabel('Stuffing — vegetarian', { exact: true }).fill('1');
  const submit = page.getByRole('button', { name: /RSVP/ });
  await submit.scrollIntoViewIfNeeded();
  await submit.click();
  await page.waitForURL(/\/rsvp-confirmation\/\?at_rsvp=saved/);
  await expect(page.getByRole('heading', { name: 'RSVP confirmation' })).toBeVisible();
  await expect(page.getByRole('status')).toContainText('on the list');
  await page.goto('/rsvp/');
  await page.reload();

  await expect(page.getByLabel('How many people are coming?')).toHaveValue('2');
  await expect(page.getByLabel('Stuffing — vegetarian', { exact: true })).toHaveValue('1');
  await page.goto('/');
  await expect(page.getByRole('link', { name: 'Update RSVP', exact: true })).toHaveAttribute('href', /\/rsvp\/$/);
  await expect(page.getByRole('link', { name: 'Sign up without RSVP' })).toHaveAttribute('href', /\/signup\/$/);
  if (process.env.SIGNED_IN_HOME_WITH_RSVP_SCREENSHOT) {
    await page.screenshot({ path: process.env.SIGNED_IN_HOME_WITH_RSVP_SCREENSHOT, fullPage: true });
  }
});

test('a signed-in RSVP has no login prompt, emails its full payload, and repopulates every field when edited', async ({ page }) => {
  await logIn(page);
  await page.goto('/rsvp/');

  await expect(page.getByRole('group', { name: 'Create your account' })).toHaveCount(0);
  await expect(page.getByRole('button', { name: 'Sign in first' })).toHaveCount(0);

  await page.getByLabel('I’m coming').check();
  await page.getByLabel('How many people are coming?').selectOption('3');
  await page.getByLabel('Names', { exact: true }).fill('Signed-in Friend, Alex, Sam');
  await page.getByLabel('Dietary notes (optional)').fill('Vegetarian; no walnuts');
  const existingFoods = page.locator('input[name^="at_food_amounts["]');
  for (let i = 0; i < await existingFoods.count(); i++) await existingFoods.nth(i).fill('0');
  await page.getByLabel('Gravy — vegetarian', { exact: true }).fill('2');
  await page.getByLabel('Pumpkin pie', { exact: true }).fill('1');
  await page.getByLabel('Something else?').fill('Mulled cider');
  await page.getByLabel('Amount of something else').fill('3');
  await page.getByLabel('Anything else for the hosts? (optional)').fill('Please put us near the window.');
  await page.locator('.at-rsvp-submit-actions button[type="submit"]').click();
  await page.waitForURL(/\/rsvp-confirmation\/\?at_rsvp=saved/);

  const firstMail = await page.request.get('/wp-admin/admin-ajax.php?action=at_gathering_last_test_mail');
  const firstMailPayload = (await firstMail.json()).data;
  expect(firstMailPayload.to).toBe('guest@example.test');
  expect(firstMailPayload.subject).toBe('Your Armstrong Thanksgiving RSVP');
  for (const value of [
    'Attendance: Coming',
    'People: 3',
    'Names: Signed-in Friend, Alex, Sam',
    'Food: Gravy — vegetarian × 2, Pumpkin pie × 1, Mulled cider × 3',
    'Dietary notes: Vegetarian; no walnuts',
    'Note for the hosts: Please put us near the window.',
  ]) expect(firstMailPayload.message).toContain(value);
  expect(firstMailPayload.message).toMatch(/12 December 2026 · 6:42 pm<br\s*\/?>(?:\s|\n)*123 Example Lane, Testville/);

  await page.goto('/rsvp/');
  await expect(page.getByLabel('I’m coming')).toBeChecked();
  await expect(page.getByLabel('How many people are coming?')).toHaveValue('3');
  await expect(page.getByLabel('Names', { exact: true })).toHaveValue('Signed-in Friend, Alex, Sam');
  await expect(page.getByLabel('Dietary notes (optional)')).toHaveValue('Vegetarian; no walnuts');
  await expect(page.getByLabel('Gravy — vegetarian', { exact: true })).toHaveValue('2');
  await expect(page.getByLabel('Pumpkin pie', { exact: true })).toHaveValue('1');
  await expect(page.getByLabel('Something else?')).toHaveValue('Mulled cider');
  await expect(page.getByLabel('Amount of something else')).toHaveValue('3');
  await expect(page.getByLabel('Anything else for the hosts? (optional)')).toHaveValue('Please put us near the window.');

  await page.getByLabel('Maybe').check();
  await page.getByLabel('How many people are coming?').selectOption('1');
  await page.getByLabel('Names', { exact: true }).fill('Signed-in Friend');
  await page.getByLabel('Dietary notes (optional)').fill('No walnuts');
  await page.getByLabel('Gravy — vegetarian', { exact: true }).fill('0');
  await page.getByLabel('Pumpkin pie', { exact: true }).fill('0');
  await page.getByLabel('Cranberry sauce', { exact: true }).fill('4');
  await page.getByLabel('Something else?').fill('Sparkling cider');
  await page.getByLabel('Amount of something else').fill('1');
  await page.getByLabel('Anything else for the hosts? (optional)').fill('Updated note for the hosts.');
  await page.getByRole('button', { name: 'Update my RSVP' }).click();
  await page.waitForURL(/\/rsvp-confirmation\/\?at_rsvp=saved/);

  const editedMail = await page.request.get('/wp-admin/admin-ajax.php?action=at_gathering_last_test_mail');
  const editedMailPayload = (await editedMail.json()).data;
  for (const value of [
    'Attendance: Maybe',
    'People: 1',
    'Names: Signed-in Friend',
    'Food: Cranberry sauce × 4, Sparkling cider × 1',
    'Dietary notes: No walnuts',
    'Note for the hosts: Updated note for the hosts.',
  ]) expect(editedMailPayload.message).toContain(value);
  expect(editedMailPayload.message).not.toContain('Signed-in Friend, Alex, Sam');
  expect(editedMailPayload.message).not.toContain('Mulled cider');

  await page.goto('/rsvp/');
  await expect(page.getByLabel('Maybe')).toBeChecked();
  await expect(page.getByLabel('How many people are coming?')).toHaveValue('1');
  await expect(page.getByLabel('Names', { exact: true })).toHaveValue('Signed-in Friend');
  await expect(page.getByLabel('Dietary notes (optional)')).toHaveValue('No walnuts');
  await expect(page.getByLabel('Cranberry sauce', { exact: true })).toHaveValue('4');
  await expect(page.getByLabel('Gravy — vegetarian', { exact: true })).toHaveValue('0');
  await expect(page.getByLabel('Pumpkin pie', { exact: true })).toHaveValue('0');
  await expect(page.getByLabel('Something else?')).toHaveValue('Sparkling cider');
  await expect(page.getByLabel('Anything else for the hosts? (optional)')).toHaveValue('Updated note for the hosts.');

  runWpEval("global $wpdb; $rows = $wpdb->update(at_gathering_table(), array('foods' => wp_json_encode(array('Cranberry sauce', 'Sparkling cider')), 'food_amounts' => '{}'), array('custom_food' => 'Sparkling cider')); if ( false === $rows || 0 === $rows ) { WP_CLI::error('Could not create the legacy RSVP test fixture.'); }");
  await page.reload();
  await expect(page.getByLabel('Amount of something else')).toHaveValue('1');
  await expect(page.locator('.at-custom-food-list').getByRole('listitem').filter({ hasText: 'Sparkling cider' }).locator('small')).toHaveText('1 from Signed-in Friend');

  await page.getByLabel('Amount of something else').fill('0');
  await page.getByRole('button', { name: 'Update my RSVP' }).click();
  await page.waitForURL(/\/rsvp-confirmation\/\?at_rsvp=saved/);
  await page.goto('/rsvp/');
  await expect(page.getByLabel('Something else?')).toHaveValue('Sparkling cider');
  await expect(page.getByLabel('Amount of something else')).toHaveValue('0');
  await expect(page.locator('.at-custom-food-list')).toHaveCount(0);
});

test('a standalone signup creates a member without an RSVP', async ({ page }, testInfo) => {
  const unique = `${testInfo.project.name.replace(/\W/g, '')}${Date.now()}`.toLowerCase();
  await page.goto('/signup/');

  await expect(page.getByRole('heading', { name: 'Create your account' })).toBeVisible();
  await expect(page.getByText('before, during, or after Thanksgiving')).toBeVisible();
  await page.getByLabel('Display name').fill('Forum Friend');
  await page.getByLabel('Email').fill(`signup-${unique}@example.test`);
  await page.getByLabel('Password', { exact: true }).fill('cranberry-sauce-2026');
  await page.getByLabel('Confirm password').fill('cranberry-sauce-2026');
  await page.getByRole('button', { name: 'Sign up' }).click();
  await page.waitForURL(/at_signup=saved/);

  await expect(page.getByRole('status')).toContainText('You’re signed up');
  await expect(page.getByRole('link', { name: 'gathering forum' })).toBeVisible();
  await expect(page.getByRole('group', { name: 'Your details' })).toHaveCount(0);
});

test('a new friend creates an account as the last step of RSVP', async ({ page }, testInfo) => {
  const unique = `${testInfo.project.name.replace(/\W/g, '')}${Date.now()}`.toLowerCase();
  await page.goto('/rsvp/');

  await expect(page.getByRole('group', { name: 'Create your account' })).toBeVisible();
  await page.getByLabel('How many people are coming?').selectOption('2');
  await page.getByLabel('Names', { exact: true }).fill('New Friend and Alex');
  await page.getByLabel('Stuffing — vegetarian', { exact: true }).fill('1');
  await page.getByLabel('Display name').fill('New Friend');
  await page.getByLabel('Email').fill(`friend-${unique}@example.test`);
  await page.getByLabel('Password', { exact: true }).fill('cranberry-sauce-2026');
  await page.getByLabel('Confirm password').fill('cranberry-sauce-2026');
  await page.getByRole('button', { name: 'Save my RSVP' }).click();
  await page.waitForURL(/\/rsvp-confirmation\/\?at_rsvp=saved/);

  await expect(page.getByRole('status')).toContainText('on the list');
  await page.goto('/rsvp/');
  await expect(page.getByRole('group', { name: 'Create your account' })).toHaveCount(0);
  await expect(page.getByLabel('Names', { exact: true })).toHaveValue('New Friend and Alex');
  await expect(page.getByLabel('Stuffing — vegetarian', { exact: true })).toHaveValue('1');

  const mail = await page.request.get('/wp-admin/admin-ajax.php?action=at_gathering_last_test_mail');
  const payload = await mail.json();
  expect(payload.data.subject).toBe('Your Armstrong Thanksgiving RSVP');
  expect(payload.data.message).toContain('data-at-email-theme="armstrong-thanksgiving"');
  expect(payload.data.message).toContain('Names: New Friend and Alex');
  expect(payload.data.message).toContain('/signup/');
  expect(payload.data.message).not.toContain('Friends-only');
  expect(payload.data.message).not.toMatch(/See you there!.*The hosts/s);
  expect(payload.data.message).toContain('See you there!');
});

test('the ordinary RSVP URL is public and explains account access', async ({ page }) => {
  await page.goto('/rsvp/');
  await expect(page.getByRole('group', { name: 'Create your account' })).toBeVisible();
  await expect(page.getByText('gathering forum and shared photos')).toBeVisible();
  await page.goto('/');
  await expect(page.locator('.at-date-card')).toContainText('12 December 2026');
  await expect(page.locator('.at-date-card')).not.toContainText('6:42 pm');
  await expect(page.locator('.at-event-address')).toHaveCount(0);
});

test.describe('RSVP sign-in handoff', () => {
  test('an account’s saved RSVP takes precedence over a signed-out form', async ({ page }) => {
    await logIn(page);
    await page.goto('/rsvp/');
    await page.getByLabel('I can’t make it').check();
    await page.getByLabel('Names', { exact: true }).fill('Saved RSVP baseline');
    await page.getByLabel('Dietary notes (optional)').fill('Saved dietary note');
    await page.getByLabel('Cranberry sauce', { exact: true }).fill('1');
    await page.getByLabel('Something else?').fill('Saved cider');
    await page.getByLabel('Anything else for the hosts? (optional)').fill('Saved host note');
    await page.locator('.at-rsvp-submit-actions button[type="submit"]').click();
    await page.waitForURL(/\/rsvp-confirmation\/\?at_rsvp=saved/);
    await page.context().clearCookies();

    await page.goto('/rsvp/');
    const note = page.locator('.at-form-login-note-top');
    const account = page.getByRole('group', { name: 'Create your account' });
    await expect(note).toBeVisible();
    expect(await note.evaluate((element, target) => Boolean(element.compareDocumentPosition(target) & Node.DOCUMENT_POSITION_FOLLOWING), await account.elementHandle())).toBe(true);
    await page.getByLabel('Something else?').fill('Draft mulled cider');
    await page.getByRole('button', { name: 'Sign in first' }).click();
    await expect(page).toHaveURL(/wp-login\.php/);
    await page.locator('#user_login').fill(process.env.WP_TEST_USER ?? 'guest');
    await page.locator('#user_pass').fill(process.env.WP_TEST_PASSWORD ?? 'password');
    await page.locator('#wp-submit').click();
    await page.waitForURL(/\/rsvp\//);
    await expect(page.getByLabel('Names', { exact: true })).toHaveValue('Saved RSVP baseline');
    await expect(page.getByLabel('I can’t make it')).toBeChecked();
    await expect(page.getByLabel('How many people are coming?')).toHaveValue('0');
    await expect(page.getByLabel('Dietary notes (optional)')).toHaveValue('Saved dietary note');
    await expect(page.getByLabel('Cranberry sauce', { exact: true })).toHaveValue('1');
    await expect(page.getByLabel('Something else?')).toHaveValue('Saved cider');
    await expect(page.getByLabel('Anything else for the hosts? (optional)')).toHaveValue('Saved host note');
    await page.reload();
    await expect(page.getByLabel('Names', { exact: true })).toHaveValue('Saved RSVP baseline');
    await expect(page.getByLabel('I can’t make it')).toBeChecked();
    await expect(page.getByLabel('How many people are coming?')).toHaveValue('0');
    await expect(page.getByLabel('Dietary notes (optional)')).toHaveValue('Saved dietary note');
    await expect(page.getByLabel('Cranberry sauce', { exact: true })).toHaveValue('1');
    await expect(page.getByLabel('Something else?')).toHaveValue('Saved cider');
    await expect(page.getByLabel('Anything else for the hosts? (optional)')).toHaveValue('Saved host note');
  });

  test('a new account restores the submitted RSVP once after sign-in', async ({ page }, testInfo) => {
    const unique = `${testInfo.project.name.replace(/\W/g, '')}${Date.now()}`.toLowerCase();
    const email = `handoff-${unique}@example.test`;
    const password = 'cranberry-sauce-2026';
    await page.goto('/signup/');
    await page.getByLabel('Display name').fill('Handoff Test Friend');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password', { exact: true }).fill(password);
    await page.getByLabel('Confirm password').fill(password);
    await page.getByRole('button', { name: 'Sign up' }).click();
    await page.waitForURL(/at_signup=saved/);

    await page.context().clearCookies();
    await page.goto('/rsvp/');
    await page.getByLabel('Maybe').check();
    await page.getByLabel('I’m coming').check();
    await page.getByLabel('How many people are coming?').selectOption('2');
    await page.getByLabel('How many people are coming?').selectOption('1');
    await page.getByLabel('Names', { exact: true }).fill('Handoff Guest');
    await page.getByLabel('Cranberry sauce', { exact: true }).fill('1');
    await page.getByLabel('Something else?').fill('Handoff mulled cider');
    await page.getByRole('button', { name: 'Sign in first' }).click();
    await expect(page).toHaveURL(/wp-login\.php/);
    await page.locator('#user_login').fill(email);
    await page.locator('#user_pass').fill(password);
    await page.locator('#wp-submit').click();
    await page.waitForURL(/\/rsvp\//);
    await expect(page.getByLabel('I’m coming')).toBeChecked();
    await expect(page.getByLabel('How many people are coming?')).toHaveValue('1');
    await expect(page.getByLabel('Names', { exact: true })).toHaveValue('Handoff Guest');
    await expect(page.getByLabel('Cranberry sauce', { exact: true })).toHaveValue('1');
    await expect(page.getByLabel('Something else?')).toHaveValue('Handoff mulled cider');

    await page.reload();
    await expect(page.getByLabel('Names', { exact: true })).toHaveValue('');
    await expect(page.getByLabel('I’m coming')).toBeChecked();
    await expect(page.getByLabel('How many people are coming?')).toHaveValue('1');
    await expect(page.getByLabel('Cranberry sauce', { exact: true })).toHaveValue('0');
    await expect(page.getByLabel('Something else?')).toHaveValue('');
  });

  test('an untouched sign-in handoff keeps the account’s existing RSVP', async ({ page }, testInfo) => {
    const email = `untouched-handoff-${testInfo.project.name.replace(/\W/g, '')}-${Date.now()}@example.test`.toLowerCase();
    const password = 'cranberry-sauce-2026';
    await page.goto('/rsvp/');
    await page.getByLabel('I can’t make it').check();
    await page.getByLabel('Display name').fill('Untouched handoff test');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password', { exact: true }).fill(password);
    await page.getByLabel('Confirm password').fill(password);
    await page.getByRole('button', { name: 'Save my RSVP' }).click();
    await page.waitForURL(/\/rsvp-confirmation\/\?at_rsvp=saved/);

    await page.context().clearCookies();
    await page.goto('/rsvp/');
    await page.getByRole('button', { name: 'Sign in first' }).click();
    await expect(page).toHaveURL(/wp-login\.php/);
    await page.locator('#user_login').fill(email);
    await page.locator('#user_pass').fill(password);
    await page.locator('#wp-submit').click();
    await page.waitForURL(/\/rsvp\//);
    await expect(page.getByLabel('I can’t make it')).toBeChecked();
  });

  test('a stale signed-out tab does not replace the signed-in RSVP', async ({ page }) => {
    await page.goto('/rsvp/');
    await page.getByLabel('Names', { exact: true }).fill('Late-tab draft guest');
    await page.getByLabel('Stuffing — vegetarian', { exact: true }).fill('1');
    const signedInTab = await page.context().newPage();
    await logIn(signedInTab);
    await signedInTab.goto('/rsvp/');
    await signedInTab.getByLabel('Names', { exact: true }).fill('Saved stale-tab baseline');
    await signedInTab.getByLabel('Cranberry sauce', { exact: true }).fill('1');
    await signedInTab.locator('.at-rsvp-submit-actions button[type="submit"]').click();
    await signedInTab.waitForURL(/\/rsvp-confirmation\/\?at_rsvp=saved/);
    await page.getByRole('button', { name: 'Sign in first' }).click();
    await page.waitForURL(/\/rsvp\//);
    await expect(page.getByRole('heading', { name: 'Will you join us?' })).toBeVisible();
    await expect(page.getByLabel('Names', { exact: true })).toHaveValue('Saved stale-tab baseline');
    await expect(page.getByLabel('Cranberry sauce', { exact: true })).toHaveValue('1');
    await expect(page.getByLabel('Stuffing — vegetarian', { exact: true })).toHaveValue('0');
    await signedInTab.close();
  });

  test('pressing Enter in an RSVP field submits the RSVP instead of starting sign-in', async ({ page }, testInfo) => {
    const email = `enter-submit-${testInfo.project.name.replace(/\W/g, '')}-${Date.now()}@example.test`.toLowerCase();
    await page.goto('/rsvp/');
    const account = page.getByRole('group', { name: 'Create your account' });
    const saveActions = page.locator('.at-rsvp-submit-actions');
    expect(await account.evaluate((element, target) => Boolean(element.compareDocumentPosition(target) & Node.DOCUMENT_POSITION_FOLLOWING), await saveActions.elementHandle())).toBe(true);
    await expect(page.locator('.at-default-submit')).toHaveAttribute('tabindex', '-1');
    await page.getByLabel('Names', { exact: true }).fill('Enter key test guest');
    await page.getByLabel('Display name').fill('Enter key test guest');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password', { exact: true }).fill('cranberry-sauce-2026');
    await page.getByLabel('Confirm password').fill('cranberry-sauce-2026');
    await page.getByLabel('Names', { exact: true }).press('Enter');
    await page.waitForURL(/\/rsvp-confirmation\/\?at_rsvp=saved/);
    await expect(page.getByRole('heading', { name: 'RSVP confirmation' })).toBeVisible();
  });
});

test('private album access redirects signed-out visitors', async ({ browser }) => {
  const context = await browser.newContext();
  const page = await context.newPage();
  await page.goto('/albums/');
  await expect(page).toHaveURL(/wp-login\.php/);
  await context.close();
});

test('duplicate email returns a useful error without saving an RSVP', async ({ page }) => {
  await page.goto('/rsvp/');
  await page.getByLabel('Names', { exact: true }).fill('Existing Friend');
  await page.getByLabel('Display name').fill('Existing Friend');
  await page.getByLabel('Email').fill('guest@example.test');
  await page.getByLabel('Password', { exact: true }).fill('cranberry-sauce-2026');
  await page.getByLabel('Confirm password').fill('cranberry-sauce-2026');
  await page.getByRole('button', { name: 'Save my RSVP' }).click();
  await page.waitForURL(/at_rsvp=error/);

  await expect(page.getByRole('alert')).toContainText('already an account for that email');
  await expect(page.getByRole('group', { name: 'Create your account' })).toBeVisible();
  await expect(page.getByLabel('Names', { exact: true })).toHaveValue('Existing Friend');
  await expect(page.getByLabel('Display name')).toHaveValue('Existing Friend');
  await expect(page.getByLabel('Email')).toHaveValue('guest@example.test');
});

test('server-side RSVP validation does not leave an orphan account', async ({ page }, testInfo) => {
  const unique = `orphan${testInfo.project.name.replace(/\W/g, '')}${Date.now()}`.toLowerCase();
  await page.goto('/rsvp/');
  await page.getByLabel('Display name').fill('Should Not Exist');
  await page.getByLabel('Email').fill(`${unique}@example.test`);
  await page.getByLabel('Password', { exact: true }).fill('cranberry-sauce-2026');
  await page.getByLabel('Confirm password').fill('cranberry-sauce-2026');
  await page.getByRole('button', { name: 'Save my RSVP' }).click();
  await page.waitForURL(/at_rsvp=error/);
  await expect(page.getByRole('alert')).toContainText('Please add the names');

  await page.goto('/wp-login.php');
  await page.evaluate(({ username, password }) => {
    const usernameInput = document.querySelector<HTMLInputElement>('#user_login');
    const passwordInput = document.querySelector<HTMLInputElement>('#user_pass');
    if (!usernameInput || !passwordInput) throw new Error('WordPress login fields not found');
    usernameInput.value = username;
    passwordInput.value = password;
    for (const input of [usernameInput, passwordInput]) {
      input.dispatchEvent(new Event('input', { bubbles: true }));
      input.dispatchEvent(new Event('change', { bubbles: true }));
    }
  }, { username: unique, password: 'cranberry-sauce-2026' });
  await page.locator('#wp-submit').click();
  await expect(page.locator('#login_error')).toBeVisible();
});

test('food totals add the offered amount and an RSVP update does not double-count it', async ({ page }, testInfo) => {
  const unique = `counter${testInfo.project.name.replace(/\W/g, '')}${Date.now()}`.toLowerCase();
  await page.goto('/rsvp/');
  const gravy = page.getByLabel('Gravy — vegetarian', { exact: true }).locator('..').locator('..');
  const gravySummary = gravy.locator('small');
  const before = Number.parseInt((await gravySummary.innerText()).match(/\d+/)?.[0] ?? '0', 10);
  await page.getByLabel('Names', { exact: true }).fill('Count Test Friend');
  await page.getByLabel('Gravy — vegetarian', { exact: true }).fill('4');
  await page.getByLabel('Display name').fill('Count Test Friend');
  await page.getByLabel('Email').fill(`${unique}@example.test`);
  await page.getByLabel('Password', { exact: true }).fill('cranberry-sauce-2026');
  await page.getByLabel('Confirm password').fill('cranberry-sauce-2026');
  await page.getByRole('button', { name: 'Save my RSVP' }).click();
  await page.waitForURL(/\/rsvp-confirmation\/\?at_rsvp=saved/);
  await page.goto('/rsvp/');
  await expect(gravySummary).toContainText(`Claimed: ${before + 4}`);
  await expect(gravySummary).toContainText('Count Test Friend');

  await page.getByLabel('Names', { exact: true }).fill('Count Test Friend Updated');
  await page.getByRole('button', { name: 'Update my RSVP' }).click();
  await page.waitForURL(/\/rsvp-confirmation\/\?at_rsvp=saved/);
  await page.goto('/rsvp/');
  await expect(gravySummary).toContainText(`Claimed: ${before + 4}`);
  await expect(gravySummary).toContainText('Count Test Friend Updated');
});
