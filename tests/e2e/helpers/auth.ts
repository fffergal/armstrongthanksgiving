import type { Page } from '@playwright/test';

export async function logIn(page: Page): Promise<void> {
  const username = process.env.WP_TEST_USER ?? 'guest';
  const password = process.env.WP_TEST_PASSWORD ?? 'password';

  await page.goto('/wp-login.php');
  // Magic Login hides core's submit input and adds its own visible submit
  // button. Set the fixture values in the page and dispatch normal input
  // events so browser autofill cannot swap them while the form is loading.
  await page.evaluate(({ user, pass }) => {
    const usernameInput = document.querySelector<HTMLInputElement>('#user_login');
    const passwordInput = document.querySelector<HTMLInputElement>('#user_pass');
    if (!usernameInput || !passwordInput) throw new Error('WordPress login fields not found');
    usernameInput.value = user;
    passwordInput.value = pass;
    for (const input of [usernameInput, passwordInput]) {
      input.dispatchEvent(new Event('input', { bubbles: true }));
      input.dispatchEvent(new Event('change', { bubbles: true }));
    }
  }, { user: username, pass: password });
  await page.locator('#wp-login-submit').click();
  await page.waitForURL(url => !url.pathname.endsWith('/wp-login.php'), { timeout: 15_000 });
  await page.waitForLoadState('networkidle');
}
