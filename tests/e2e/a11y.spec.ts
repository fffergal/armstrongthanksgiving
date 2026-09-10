import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

test('entry page has no serious accessibility violations', async ({ page }) => {
  await page.goto('/');
  const result = await new AxeBuilder({ page }).analyze();
  const serious = result.violations.filter(v => ['serious', 'critical'].includes(v.impact ?? ''));
  expect(serious).toEqual([]);
});

