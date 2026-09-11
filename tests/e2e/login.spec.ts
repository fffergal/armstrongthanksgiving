import { expect, test } from '@playwright/test';

test('login page explains friend access and uses the standard sign-in form', async ({ page }) => {
  await page.goto('/wp-login.php');

  await expect(page.getByText('Friends’ sign in')).toBeVisible();
  await expect(page.getByText('password you chose with your RSVP')).toBeVisible();
  await expect(page.getByText('private RSVP link')).toBeVisible();
  await expect(page.locator('#wp-submit')).toBeVisible();
  await expect(page.locator('#magic-login-button')).toHaveCount(0);
  await expect(page.getByRole('link', { name: 'Lost your password?' })).toBeVisible();
});
