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

test('login fields only show the focus ring during keyboard navigation', async ({ page }) => {
  await page.goto('/wp-login.php');
  await page.waitForTimeout(300);

  const username = page.locator('#user_login');
  await username.click();
  await expect(username).toHaveCSS('outline-style', 'none');

  await username.press('Tab');
  const password = page.locator('#user_pass');
  await expect(password).toBeFocused();
  await expect(password).toHaveCSS('outline-style', 'solid');
  await expect(password).toHaveCSS('outline-width', '3px');
  await expect(password).toHaveCSS('outline-color', 'rgb(76, 37, 24)');

  await password.press('Tab');
  const showPassword = page.getByRole('button', { name: /show password/i });
  await expect(showPassword).toBeFocused();
  await expect(showPassword).toHaveCSS('outline-style', 'solid');
  await expect(showPassword).toHaveCSS('outline-width', '3px');
  await expect(showPassword).toHaveCSS('outline-color', 'rgb(76, 37, 24)');

  await showPassword.press('Tab');
  const remember = page.locator('#rememberme');
  await expect(remember).toBeFocused();
  await expect(remember).toHaveCSS('outline-style', 'solid');
  await expect(remember).toHaveCSS('outline-width', '3px');
  await expect(remember).toHaveCSS('outline-color', 'rgb(76, 37, 24)');

  await remember.press('Tab');
  const submit = page.locator('#wp-submit');
  await expect(submit).toBeFocused();
  await expect(submit).toHaveCSS('outline-style', 'solid');
  await expect(submit).toHaveCSS('outline-width', '3px');
  await expect(submit).toHaveCSS('outline-color', 'rgb(76, 37, 24)');

  await submit.press('Tab');
  const lostPassword = page.getByRole('link', { name: 'Lost your password?' });
  await expect(lostPassword).toBeFocused();
  await expect(lostPassword).toHaveCSS('outline-style', 'solid');
  await expect(lostPassword).toHaveCSS('outline-width', '3px');
  await expect(lostPassword).toHaveCSS('outline-color', 'rgb(76, 37, 24)');

  await lostPassword.press('Tab');
  const homeLink = page.getByRole('link', { name: 'Go to home page' });
  await expect(homeLink).toBeFocused();
  await expect(homeLink).toHaveCSS('outline-style', 'solid');
  await expect(homeLink).toHaveCSS('outline-width', '3px');
  await expect(homeLink).toHaveCSS('outline-color', 'rgb(76, 37, 24)');
});
