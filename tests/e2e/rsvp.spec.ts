import { expect, test } from '@playwright/test';
import { logIn } from './helpers/auth';

test('a signed-in friend gets the RSVP and potluck form', async ({ page }) => {
  await logIn(page);
  await page.goto('/rsvp/');

  await expect(page.getByRole('heading', { name: 'Will you join us?' })).toBeVisible();
  await expect(page.getByRole('group', { name: 'Attendance' })).toBeVisible();
  await expect(page.getByRole('group', { name: 'What could you bring?' })).toBeVisible();
  await expect(page.getByLabel('Turkey or vegetarian centrepiece')).toBeVisible();
  await expect(page.getByText('Your account is also your key to the friends-only forum and shared photos.')).toBeVisible();
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

test('an RSVP saves and is still present after reload', async ({ page }) => {
  await logIn(page);
  await page.goto('/rsvp/');

  await page.getByLabel('How many people are coming?').selectOption('2');
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
