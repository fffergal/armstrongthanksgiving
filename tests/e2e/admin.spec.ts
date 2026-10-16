import { expect, test } from '@playwright/test';
import { logInAsAdmin } from './helpers/auth';
import { spawnSync } from 'node:child_process';
import path from 'node:path';

const root = path.resolve(__dirname, '../..');

function runWpEval(code: string): string {
  const result = spawnSync(process.execPath, [path.join(root, 'scripts/wp-env.mjs'), 'run', 'cli', 'wp', 'eval', code], {
    cwd: root,
    encoding: 'utf8',
    stdio: 'pipe',
  });
  if (result.status !== 0) throw new Error(result.stderr || result.stdout || 'Could not prepare the roster visibility fixture.');
  return result.stdout;
}

test('an administrator can save event details from Gathering RSVPs', async ({ page }) => {
  await logInAsAdmin(page);

  const adminPage = '/wp-admin/admin.php?page=at-gathering';
  const fixture = {
    date: '12 December 2026',
    time: '6:42 pm',
    address: '123 Example Lane, Testville',
  };
  const updated = {
    date: '13 December 2026',
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

test('an administrator can hide a claimed guest from the RSVP picker without deleting their RSVP', async ({ page }) => {
  runWpEval("global $wpdb; $email = 'picker-test@example.test'; $user_id = wp_insert_user(array('user_login' => 'picker-test', 'user_pass' => wp_generate_password(), 'user_email' => $email, 'display_name' => 'Picker Test Guest', 'role' => 'subscriber')); if (is_wp_error($user_id)) { WP_CLI::error($user_id->get_error_message()); } $now = current_time('mysql'); $wpdb->insert(at_gathering_roster_table(), array('email_normalized' => $email, 'display_name' => 'Picker Test Guest', 'user_id' => $user_id, 'claim_state' => 'claimed', 'created_at' => $now, 'updated_at' => $now)); $wpdb->replace(at_gathering_table(), array('user_id' => $user_id, 'status' => 'yes', 'guest_count' => 1, 'guest_names' => '', 'children_count' => 0, 'dietary' => '', 'foods' => '[]', 'food_amounts' => '{}', 'custom_food' => '', 'notes' => '', 'created_at' => $now, 'updated_at' => $now));");
  try {
    await logInAsAdmin(page);
    await page.goto('/wp-admin/admin.php?page=at-gathering');
    const row = page.locator('tr').filter({ hasText: 'picker-test@example.test' }).filter({
      has: page.locator('input[name="at_invitation_mode"][value="visibility"]'),
    });
    await expect(row).toContainText('Shown');
    await row.getByRole('button', { name: 'Hide from picker' }).click();
    await expect(page).toHaveURL(/at_invitation=hidden/);
    await expect(page.getByText('Guest hidden from the RSVP adult picker. Their account and RSVP are unchanged.')).toBeVisible();
    await expect(row).toContainText('Hidden');
    const hidden = runWpEval("$guest = at_gathering_roster_guest_by_email('picker-test@example.test'); $choices = at_gathering_roster_choices(); $visible = in_array((int) $guest->id, array_map(static function ($choice) { return (int) $choice->id; }, $choices), true); $all_choices = at_gathering_roster_choices(true); $retained = (bool) at_gathering_get_rsvp((int) $guest->user_id); echo !$visible && $retained && $guest && (int) $guest->hidden_from_picker === 1 && in_array((int) $guest->id, array_map(static function ($choice) { return (int) $choice->id; }, $all_choices), true) ? 'retained' : 'failed';").trim();
    expect(hidden).toBe('retained');
    await row.getByRole('button', { name: 'Show in picker' }).click();
    await expect(page).toHaveURL(/at_invitation=shown/);
    expect(runWpEval("$guest = at_gathering_roster_guest_by_email('picker-test@example.test'); echo $guest && 0 === (int) $guest->hidden_from_picker ? 'shown' : 'failed';").trim()).toBe('shown');
  } finally {
    runWpEval("global $wpdb; $guest = at_gathering_roster_guest_by_email('picker-test@example.test'); if ($guest) { $wpdb->delete(at_gathering_assignments_table(), array('guest_id' => (int) $guest->id)); $wpdb->delete(at_gathering_table(), array('user_id' => (int) $guest->user_id)); $wpdb->delete(at_gathering_roster_table(), array('id' => (int) $guest->id)); } $user = get_user_by('email', 'picker-test@example.test'); if ($user) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user((int) $user->ID); }");
  }
});
