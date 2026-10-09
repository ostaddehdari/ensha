const { test, expect } = require('@playwright/test');

test('secretary filters a branch and edits an appointment inside the calendar', async ({ page }) => {
  const base = process.env.ENSHA_BROWSER_URL;
  const phone = process.env.ENSHA_BROWSER_USER;
  const password = process.env.ENSHA_BROWSER_PASSWORD;
  test.skip(!base || !phone || !password, 'ENSHA browser credentials are required');

  await page.goto(`${base}/login`);
  await page.locator('input[name="phone"]').fill(phone);
  await page.locator('input[name="password"]').fill(password);
  await Promise.all([page.waitForURL(/dashboard/), page.locator('button[type="submit"]').click()]);
  await page.goto(`${base}/appointments/calendar`);

  const branch = page.locator('[data-calendar-filter][name="branch_id"]');
  await expect(branch).toBeVisible();
  if (await branch.locator('option').count() > 1) {
    const response = page.waitForResponse(r => r.url().includes('/calendar/api/events') && r.ok());
    await branch.selectOption({ index: 1 });
    await response;
  }

  const event = page.locator('[class*="calendar_default_event"], [class*="month_default_event"]').first();
  test.skip(await event.count() === 0, 'An appointment in the selected range is required');
  await event.click();
  await expect(page.locator('[data-edit-drawer]')).toBeVisible();
  await expect(page.locator('[data-edit-jalali]')).toContainText('تاریخ شمسی');
  await page.locator('[data-edit-close]').last().click();
});
