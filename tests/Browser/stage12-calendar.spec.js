const { test, expect } = require('@playwright/test');

test('secretary can filter, drag and resize an appointment', async ({ page }) => {
  const base = process.env.ENSHA_BROWSER_URL;
  const phone = process.env.ENSHA_BROWSER_USER;
  const password = process.env.ENSHA_BROWSER_PASSWORD;
  test.skip(!base || !phone || !password, 'ENSHA browser credentials are required');
  await page.goto(`${base}/login`);
  await page.locator('input[name="phone"]').fill(phone);
  await page.locator('input[name="password"]').fill(password);
  await Promise.all([page.waitForURL(/dashboard/), page.locator('button[type="submit"]').click()]);
  await page.goto(`${base}/appointments/calendar`);
  await expect(page.locator('#stage06-daypilot')).toBeVisible();
  await page.locator('[data-calendar-filter][name="counselor_id"]').selectOption({ index: 1 });
  await page.waitForResponse(r => r.url().includes('/calendar/api/events') && r.ok());
  const event=page.locator('[class*="calendar_default_event"]').first();
  test.skip(await event.count()===0,'Seeded appointment is required');
  const box=await event.boundingBox();
  await page.mouse.move(box.x+box.width/2,box.y+box.height/2);
  await page.mouse.down();await page.mouse.move(box.x+box.width/2,box.y+box.height+40,{steps:8});
  const move=page.waitForResponse(r=>r.request().method()==='PATCH'&&r.url().includes('/calendar/api/events/'));
  await page.mouse.up();expect((await move).ok()).toBeTruthy();
  const resized=page.locator('[class*="calendar_default_event"]').first();const rb=await resized.boundingBox();
  await page.mouse.move(rb.x+rb.width/2,rb.y+rb.height-2);await page.mouse.down();await page.mouse.move(rb.x+rb.width/2,rb.y+rb.height+25,{steps:6});
  const resize=page.waitForResponse(r=>r.request().method()==='PATCH'&&r.url().includes('/calendar/api/events/'));
  await page.mouse.up();expect((await resize).ok()).toBeTruthy();
  await page.locator('[data-view]').selectOption('month');await expect(page.locator('#stage06-month')).toBeVisible();
});
