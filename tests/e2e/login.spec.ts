import { expect, test } from '@playwright/test';

test('login page explains invited access and offers a login link', async ({ page }) => {
  await page.goto('/wp-login.php');

  await expect(page.getByText('Friends’ access')).toBeVisible();
  await expect(page.getByText('Use the email address you were invited with')).toBeVisible();
  await expect(page.locator('#magic-login-button')).toBeVisible();
});

test('unknown email receives an explicit no-account response', async ({ page }) => {
  // Keep this request local: it deliberately exercises the plugin's account
  // lookup and must not probe the production membership list.
  test.skip(!new URL(process.env.BASE_URL ?? 'http://localhost:8888').hostname.includes('localhost'));

  await page.goto('/wp-login.php');
  await page.locator('#user_login').fill('unknown-login-test@example.test');
  await page.locator('#magic-login-button').click();
  await page.waitForURL(/action=magic_login/);

  await expect(page.getByText('There is no account with that username or email address.')).toBeVisible();
});

test('known local account enters the magic-login state without an account error', async ({ page }) => {
  test.skip(!new URL(process.env.BASE_URL ?? 'http://localhost:8888').hostname.includes('localhost'));

  await page.goto('/wp-login.php');
  await page.locator('#user_login').fill('guest@example.test');
  await page.locator('#magic-login-button').click();
  await page.waitForURL(/action=magic_login/);

  await expect(page.getByText('There is no account with that username or email address.')).toHaveCount(0);
  await expect(page.locator('#magic-login-button')).toHaveCount(0);
});
