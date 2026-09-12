import { expect, test } from '@playwright/test';
import { logIn } from './helpers/auth';

test('a signed-in friend gets the RSVP and potluck form', async ({ page }) => {
  await logIn(page);
  await page.goto('/rsvp/');

  await expect(page.getByRole('heading', { name: 'Will you join us?' })).toBeVisible();
  await expect(page.getByRole('group', { name: 'Attendance' })).toBeVisible();
  await expect(page.getByRole('group', { name: 'What could you bring?' })).toBeVisible();
  await expect(page.getByLabel('Turkey or vegetarian centrepiece')).toHaveCount(0);
  await expect(page.getByRole('group', { name: 'Create your account' })).toHaveCount(0);
  await expect(page.getByText('52 Priestfield Crescent')).toHaveCount(0);
  await expect(page.getByText('One quick form')).toHaveCount(0);
  await expect(page.getByRole('button', { name: /RSVP/ })).toBeVisible();
});

test('the RSVP form remains usable on a phone', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await logIn(page);
  await page.goto('/rsvp/');
  await expect(page.locator('.at-rsvp-app')).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(0);
  const submit = page.getByRole('button', { name: /RSVP/ });
  await submit.scrollIntoViewIfNeeded();
  await expect(submit).toBeInViewport();
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

  await page.getByLabel('How many people are coming?').selectOption('2');
  await page.getByLabel('Names').fill('Two test friends');
  await page.getByLabel('Stuffing').check();
  const submit = page.getByRole('button', { name: /RSVP/ });
  await submit.scrollIntoViewIfNeeded();
  await submit.click();
  await page.waitForURL(/at_rsvp=saved/);
  await page.reload();

  await expect(page.getByLabel('How many people are coming?')).toHaveValue('2');
  await expect(page.getByLabel('Stuffing')).toBeChecked();
  await expect(page.getByRole('status')).toContainText('on the list');
});

test('a new friend creates an account as the last step of RSVP', async ({ page }, testInfo) => {
  const unique = `${testInfo.project.name.replace(/\W/g, '')}${Date.now()}`.toLowerCase();
  await page.goto('/rsvp/');

  await expect(page.getByRole('group', { name: 'Create your account' })).toBeVisible();
  await page.getByLabel('Names').fill('New Friend');
  await page.getByLabel('Stuffing').check();
  await page.getByLabel('Display name').fill('New Friend');
  await page.getByLabel('Email').fill(`friend-${unique}@example.test`);
  await page.getByLabel('Password', { exact: true }).fill('cranberry-sauce-2026');
  await page.getByLabel('Confirm password').fill('cranberry-sauce-2026');
  await page.getByRole('button', { name: 'Save my RSVP' }).click();
  await page.waitForURL(/at_rsvp=saved/);

  await expect(page.getByRole('status')).toContainText('on the list');
  await expect(page.getByRole('group', { name: 'Create your account' })).toHaveCount(0);
  await expect(page.getByLabel('Names')).toHaveValue('New Friend');
  await expect(page.getByLabel('Stuffing')).toBeChecked();

  const mail = await page.request.get('/wp-admin/admin-ajax.php?action=at_gathering_last_test_mail');
  const payload = await mail.json();
  expect(payload.data.subject).toBe('Your Armstrong Thanksgiving RSVP');
  expect(payload.data.message).toContain('data-at-email-theme="armstrong-thanksgiving"');
  expect(payload.data.message).toContain('Names: New Friend');
  expect(payload.data.message).toContain('The hosts');
});

test('the ordinary RSVP URL is public and explains account access', async ({ page }) => {
  await page.goto('/rsvp/');
  await expect(page.getByRole('group', { name: 'Create your account' })).toBeVisible();
  await expect(page.getByText('gathering forum and shared photos')).toBeVisible();
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
  await page.getByLabel('Names').fill('Existing Friend');
  await page.getByLabel('Display name').fill('Existing Friend');
  await page.getByLabel('Email').fill('guest@example.test');
  await page.getByLabel('Password', { exact: true }).fill('cranberry-sauce-2026');
  await page.getByLabel('Confirm password').fill('cranberry-sauce-2026');
  await page.getByRole('button', { name: 'Save my RSVP' }).click();
  await page.waitForURL(/at_rsvp=error/);

  await expect(page.getByRole('alert')).toContainText('already an account for that email');
  await expect(page.getByRole('group', { name: 'Create your account' })).toBeVisible();
  await expect(page.getByLabel('Names')).toHaveValue('Existing Friend');
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
  await page.locator('#user_login').fill(unique.split('@')[0]);
  await page.locator('#user_pass').fill('cranberry-sauce-2026');
  await page.locator('#wp-submit').click();
  await expect(page.locator('#login_error')).toBeVisible();
});

test('a food count increments once and an RSVP update does not double-count it', async ({ page }, testInfo) => {
  const unique = `counter${testInfo.project.name.replace(/\W/g, '')}${Date.now()}`.toLowerCase();
  await page.goto('/rsvp/');
  const gravy = page.getByLabel('Gravy').locator('..');
  const before = Number.parseInt((await gravy.locator('small').innerText()).match(/\d+/)?.[0] ?? '0', 10);
  await page.getByLabel('Names').fill('Count Test Friend');
  await page.getByLabel('Gravy').check();
  await page.getByLabel('Display name').fill('Count Test Friend');
  await page.getByLabel('Email').fill(`${unique}@example.test`);
  await page.getByLabel('Password', { exact: true }).fill('cranberry-sauce-2026');
  await page.getByLabel('Confirm password').fill('cranberry-sauce-2026');
  await page.getByRole('button', { name: 'Save my RSVP' }).click();
  await page.waitForURL(/at_rsvp=saved/);
  await expect(page.getByLabel('Gravy').locator('..').locator('small')).toHaveText(`${before + 1} bringing`);

  await page.getByLabel('Names').fill('Count Test Friend Updated');
  await page.getByRole('button', { name: 'Update my RSVP' }).click();
  await page.waitForURL(/at_rsvp=saved/);
  await expect(page.getByLabel('Gravy').locator('..').locator('small')).toHaveText(`${before + 1} bringing`);
});
