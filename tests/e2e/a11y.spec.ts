import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import { logIn } from './helpers/auth';

test('entry page has no serious accessibility violations', async ({ page }) => {
  await page.goto('/');
  const result = await new AxeBuilder({ page }).analyze();
  const serious = result.violations.filter(v => ['serious', 'critical'].includes(v.impact ?? ''));
  expect(serious).toEqual([]);
});

test('member home has no serious accessibility violations', async ({ page }) => {
  await logIn(page);
  await page.goto('/');
  const result = await new AxeBuilder({ page }).analyze();
  const serious = result.violations.filter(v => ['serious', 'critical'].includes(v.impact ?? ''));
  expect(serious).toEqual([]);
});

test('the signed-in RSVP form has no serious accessibility violations', async ({ page }) => {
  await logIn(page);
  await page.goto('/rsvp/');
  const result = await new AxeBuilder({ page }).analyze();
  const serious = result.violations.filter(v => ['serious', 'critical'].includes(v.impact ?? ''));
  expect(serious).toEqual([]);
});

test('the public signup and RSVP form has no serious accessibility violations', async ({ page }) => {
  await page.goto('/rsvp/');
  const result = await new AxeBuilder({ page }).analyze();
  const serious = result.violations.filter(v => ['serious', 'critical'].includes(v.impact ?? ''));
  expect(serious).toEqual([]);
});
