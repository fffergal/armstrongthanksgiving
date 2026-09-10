import { expect, test } from '@playwright/test';
import { logIn } from './helpers/auth';

test('a signed-in friend can use the gathering pages', async ({ page }) => {
  await logIn(page);

  const pages = [
    ['/', 'Thanks'],
    ['/rsvp/', 'Count me in'],
    ['/food/', 'What shall we bring?'],
    ['/albums/', 'Shared Albums'],
    ['/memories/', 'Reminisce on Thanksgivings past'],
    ['/forum/', 'The Gathering']
  ] as const;

  for (const [path, heading] of pages) {
    const response = await page.goto(path);
    expect(response?.status(), path).toBeLessThan(500);
    await expect(page.getByRole('heading', { name: heading, exact: false })).toBeVisible();
  }
});
