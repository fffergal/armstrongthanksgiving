import { expect, test } from '@playwright/test';
import { logIn, logInAsAdmin } from './helpers/auth';

test.describe.configure({ mode: 'serial' });

test('a signed-in friend can use the gathering pages', async ({ page }) => {
  await logIn(page);

  const pages = [
    ['/', 'Thanksgiving'],
    ['/rsvp/', 'Will you join us?'],
    ['/albums/', 'Shared Albums'],
    ['/forum/', 'The Gathering'],
    ['/community/topic/say-hello/', 'Say hello']
  ] as const;

  for (const [path, heading] of pages) {
    const response = await page.goto(path);
    expect(response?.status(), path).toBeLessThan(500);
    await expect(page.getByRole('heading', { name: heading, exact: false })).toBeVisible();
  }

  await page.goto('/');
  await expect(page.getByRole('link', { name: 'Sign out' })).toBeVisible();
  await expect(page.locator('.at-date-card')).toContainText('12 December 2026');
  await expect(page.locator('.at-date-card')).toContainText('6:42 pm');
  await expect(page.locator('.at-date-card .at-event-address')).toHaveCount(0);
  await expect(page.locator('.at-event-address')).toContainText('123 Example Lane, Testville');

  await page.setViewportSize({ width: 390, height: 844 });
  await page.reload();
  await expect(page.locator('.at-event-address')).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(0);

  await page.goto('/forum/');
  expect(await page.locator('img.avatar[src*="turkey-"]').count()).toBeGreaterThan(0);

  await page.goto('/randomblah-signed-in-404');
  await expect(page.locator('body')).toHaveClass(/error404/);
  await expect(page.getByRole('heading', { name: 'That page wandered off.' })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Sign out' })).toBeVisible();
});

test('an administrator can save event details from Gathering RSVPs', async ({ page }) => {
  await logInAsAdmin(page);

  const adminPage = '/wp-admin/admin.php?page=at-gathering';
  const fixture = {
    date: '12 December 2026',
    time: '6:42 pm',
    address: '123 Example Lane, Testville',
  };
  const updated = {
    date: fixture.date,
    time: '7:15 pm',
    address: '456 Example Street, Testville',
  };
  const saveDetails = async (details: typeof fixture) => {
    await page.goto(adminPage);
    await page.getByLabel('Date').fill(details.date);
    await page.getByLabel('Time').fill(details.time);
    await page.getByLabel('Address').fill(details.address);
    await page.getByRole('button', { name: 'Save event details' }).click();
    await expect(page).toHaveURL(/at_event_details=saved/);
    await expect(page.getByText('Event details saved.')).toBeVisible();
  };

  try {
    await saveDetails(updated);
    await page.reload();
    await expect(page.getByLabel('Date')).toHaveValue(updated.date);
    await expect(page.getByLabel('Time')).toHaveValue(updated.time);
    await expect(page.getByLabel('Address')).toHaveValue(updated.address);

    await page.goto('/');
    await expect(page.locator('.at-date-card')).toContainText(updated.date);
    await expect(page.locator('.at-date-card')).toContainText(updated.time);
    await expect(page.locator('.at-event-address')).toContainText(updated.address);
  } finally {
    await saveDetails(fixture);
  }
});
