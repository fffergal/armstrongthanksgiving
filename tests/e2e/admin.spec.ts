import { expect, test } from '@playwright/test';
import { logInAsAdmin } from './helpers/auth';

test('an administrator can save event details from Gathering RSVPs', async ({ page }) => {
  await logInAsAdmin(page);

  const adminPage = '/wp-admin/admin.php?page=at-gathering';
  const fixture = {
    date: '12 December 2026',
    time: '6:42 pm',
    address: '123 Example Lane, Testville',
  };
  const updated = {
    date: fixture.date,
    time: '7:15 pm',
    address: '456 Example Street, Testville',
  };
  const saveDetails = async (details: typeof fixture) => {
    await page.goto(adminPage);
    await page.getByLabel('Date').fill(details.date);
    await page.getByLabel('Time').fill(details.time);
    await page.getByLabel('Address').fill(details.address);
    await page.getByRole('button', { name: 'Save event details' }).click();
    await expect(page).toHaveURL(/at_event_details=saved/);
    await expect(page.getByText('Event details saved.')).toBeVisible();
  };

  try {
    await saveDetails(updated);
    await page.reload();
    await expect(page.getByLabel('Date')).toHaveValue(updated.date);
    await expect(page.getByLabel('Time')).toHaveValue(updated.time);
    await expect(page.getByLabel('Address')).toHaveValue(updated.address);

    await page.goto('/');
    await expect(page.locator('.at-date-card')).toContainText(updated.date);
    await expect(page.locator('.at-date-card')).toContainText(updated.time);
    await expect(page.locator('.at-event-address')).toContainText(updated.address);
  } finally {
    await saveDetails(fixture);
  }
});
