import { expect, test } from '@playwright/test';
import { logIn } from './helpers/auth';

test('a member can create, reply to, and edit their own forum posts', async ({ page }, testInfo) => {
  test.setTimeout(60_000);
  await logIn(page);
  await page.goto('/forum/');

  const suffix = `${testInfo.project.name.replace(/\W/g, '')}${Date.now()}`;
  const topicTitle = `Forum exercise ${suffix}`;
  const topicBody = `First topic body ${suffix}`;
  const editedTopicTitle = `Edited forum exercise ${suffix}`;
  const editedTopicBody = `Edited topic body ${suffix}`;
  const replyBody = `First reply body ${suffix}`;
  const editedReplyBody = `Edited reply body ${suffix}`;

  await expect(page.locator('#bbp_topic_title')).toBeVisible();
  const subscription = page.locator('#bbp_topic_subscription');
  await expect(subscription).toBeVisible();
  await expect(subscription).toHaveCSS('-webkit-appearance', 'none');
  await expect(subscription).toHaveCSS('border-radius', '4.8px');
  await subscription.check();
  await expect(subscription).toHaveCSS('background-color', 'rgb(179, 63, 49)');
  expect(await subscription.evaluate((element) => getComputedStyle(element, '::before').transform)).not.toBe('none');
  await expect(page.locator('#bbp_topic_content')).toHaveCSS('border-width', '0px');
  await expect(page.locator('#bbp_topic_content')).toHaveCSS('outline-style', 'none');
  await expect(page.locator('#qt_bbp_topic_content_toolbar')).toBeVisible();
  await page.locator('#bbp_topic_title').fill(topicTitle);
  await page.locator('#bbp_topic_content').fill(topicBody);
  await page.locator('#bbp_topic_submit').click();
  await page.waitForURL(/\/community\/topic\/[^/]+\/$/);
  const topicUrl = page.url();

  await expect(page.getByText(topicBody, { exact: true })).toBeVisible();
  await expect(page.locator('input[type="file"]')).toHaveCount(0);
  await expect(page.locator('.bbp-topic-trash-link')).toHaveCount(0);
  await expect(page.locator('.subscription-toggle')).toBeHidden();
  await expect(page.locator('.favorite-toggle')).toBeHidden();
  await expect(page.locator('.bbp-replies > li.bbp-header')).toBeHidden();
  await expect(page.locator('#bbp_reply_submit')).toHaveCSS('background-color', 'rgb(179, 63, 49)');
  await expect(page.locator('#bbp_reply_submit')).toHaveCSS('-webkit-appearance', 'none');
  await expect(page.locator('#bbp_reply_content')).toHaveCSS('-webkit-appearance', 'none');
  await expect(page.locator('#bbp_reply_content')).toHaveCSS('border-width', '0px');
  await expect(page.locator('#bbp_reply_content')).toHaveCSS('outline-style', 'none');
  await expect(page.locator('#bbp_reply_content')).toHaveCSS('display', 'block');
  await expect(page.locator('#bbp_reply_content')).toHaveCSS('padding', '10px');
  await expect(page.locator('#bbp_reply_content')).toHaveCSS('font-family', 'Consolas, Monaco, monospace');
  await expect(page.locator('#bbp_reply_content')).toHaveCSS('line-height', '18px');
  await expect(page.locator('#qt_bbp_reply_content_toolbar')).toBeVisible();

  // bbPress throttles consecutive posts from one member for ten seconds.
  await page.waitForTimeout(11_000);
  await page.locator('#bbp_reply_content').fill(replyBody);
  await page.locator('#bbp_reply_submit').click();
  await expect(page.locator('.bbp-reply-content').filter({ hasText: replyBody })).toBeVisible();

  await page.locator('.bbp-reply-to-link').first().click();
  await expect(page.locator('#bbp_reply_to')).not.toHaveValue('0');
  const directReplyBody = `Direct reply body ${suffix}`;
  await page.waitForTimeout(11_000);
  await page.locator('#bbp_reply_content').fill(directReplyBody);
  await page.locator('#bbp_reply_submit').click();
  const directReply = page.locator('.bbp-reply-content').filter({ hasText: directReplyBody });
  await expect(directReply).toBeVisible();
  await expect(directReply.locator('..')).toHaveClass(/bbp-direct-reply/);
  await expect(directReply.locator('..')).toHaveCSS('border-left-style', 'solid');
  const directReplyThread = directReply.locator('xpath=ancestor::ul[contains(concat(" ", normalize-space(@class), " "), " bbp-threaded-replies ")]');
  await expect(directReplyThread).toHaveCount(1);
  await expect(directReplyThread).toHaveCSS('margin-left', '48px');

  const secondLevelBody = `Second-level reply body ${suffix}`;
  await page.locator('.bbp-reply-to-link').last().click();
  await expect(page.locator('#bbp_reply_to')).not.toHaveValue('0');
  await page.waitForTimeout(11_000);
  await page.locator('#bbp_reply_content').fill(secondLevelBody);
  await page.locator('#bbp_reply_submit').click();
  const secondLevelReply = page.locator('.bbp-reply-content').filter({ hasText: secondLevelBody });
  await expect(secondLevelReply).toBeVisible();
  const secondLevelThreads = secondLevelReply.locator('xpath=ancestor::ul[contains(concat(" ", normalize-space(@class), " "), " bbp-threaded-replies ")]');
  await expect(secondLevelThreads).toHaveCount(2);
  await expect(secondLevelThreads.nth(0)).toHaveCSS('margin-left', '48px');
  await expect(secondLevelThreads.nth(1)).toHaveCSS('margin-left', '48px');

  await page.locator('.bbp-topic-edit-link').click();
  await expect(page.locator('#bbp_topic_title')).toHaveValue(topicTitle);
  await expect(page.locator('#bbp_topic_content')).toHaveValue(topicBody);
  await page.locator('#bbp_topic_title').fill(editedTopicTitle);
  await page.locator('#bbp_topic_content').fill(editedTopicBody);
  await page.locator('#bbp_topic_submit').click();
  await page.waitForURL(url => url.pathname === new URL(topicUrl).pathname);
  await expect(page.getByText(editedTopicBody, { exact: true })).toBeVisible();

  const replyEditLink = page.locator('.bbp-reply-edit-link').last();
  await expect(replyEditLink).toBeVisible();
  await replyEditLink.click();
  await expect(page.locator('#bbp_reply_content')).toHaveValue(secondLevelBody);
  await page.locator('#bbp_reply_content').fill(editedReplyBody);
  await page.locator('#bbp_reply_submit').click();
  await expect(page.getByText(editedReplyBody, { exact: true })).toBeVisible();
  await expect(page.locator('.bbp-reply-trash-link')).toHaveCount(0);
});
