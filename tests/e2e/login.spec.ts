import { expect, test } from '@playwright/test';

test('login page explains sign-in and uses the standard form', async ({ page }) => {
  await page.goto('/wp-login.php');

  await expect(page.getByText('Sign in', { exact: true })).toBeVisible();
  await expect(page.getByText('password you chose when you signed up')).toBeVisible();
  await expect(page.getByRole('link', { name: 'Sign up' })).toHaveAttribute('href', /\/signup\/$/);
  await expect(page.locator('#wp-submit')).toBeVisible();
  await expect(page.locator('form p.submit')).toHaveCSS('padding-top', '20px');
  await expect(page.locator('#magic-login-button')).toHaveCount(0);
  await expect(page.getByRole('link', { name: 'Lost your password?' })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Go to home page' })).toHaveAttribute('href', /\/$/);
});
