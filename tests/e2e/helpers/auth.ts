import type { Page } from '@playwright/test';

export async function logIn(page: Page): Promise<void> {
  const username = process.env.WP_TEST_USER ?? 'guest';
  const password = process.env.WP_TEST_PASSWORD ?? 'password';

  await page.goto('/wp-login.php');
  await page.locator('#user_login').fill(username);
  await page.locator('#user_pass').fill(password);
  await page.getByRole('button', { name: /log in/i }).click();
  await page.waitForURL(url => !url.pathname.endsWith('/wp-login.php'), { timeout: 15_000 });
  await page.waitForLoadState('networkidle');
}
