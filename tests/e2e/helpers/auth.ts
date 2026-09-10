import type { Page } from '@playwright/test';

export async function logIn(page: Page): Promise<void> {
  const username = process.env.WP_TEST_USER;
  const password = process.env.WP_TEST_PASSWORD;
  if (!username || !password) throw new Error('Set WP_TEST_USER and WP_TEST_PASSWORD in .env');

  await page.goto('/wp-login.php');
  await page.getByLabel(/username or email/i).fill(username);
  await page.getByLabel(/password/i).fill(password);
  await page.getByRole('button', { name: /log in/i }).click();
  await page.waitForLoadState('networkidle');
}

