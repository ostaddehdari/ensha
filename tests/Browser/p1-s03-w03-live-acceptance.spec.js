const { test, expect } = require('@playwright/test');

test('W03 secretary live acceptance: create, filter, drag, resize and reject collision', async ({ page }) => {
  const config = {
    enabled: process.env.ENSHA_W03_LIVE === '1',
    base: process.env.ENSHA_BROWSER_URL,
    phone: process.env.ENSHA_BROWSER_USER,
    password: process.env.ENSHA_BROWSER_PASSWORD,
    date: process.env.ENSHA_W03_DATE,
    branch: process.env.ENSHA_W03_BRANCH_ID,
    topic: process.env.ENSHA_W03_TOPIC_ID,
    counselor: process.env.ENSHA_W03_COUNSELOR_ID,
    client: process.env.ENSHA_W03_CLIENT_ID,
    clientText: process.env.ENSHA_W03_CLIENT_TEXT,
    conflictDate: process.env.ENSHA_W03_CONFLICT_DATE,
    conflictTime: process.env.ENSHA_W03_CONFLICT_TIME,
  };

  test.skip(
    !config.enabled || Object.values(config).some(value => !value),
    'Explicit W03 live flag, credentials and fixture identifiers are required.'
  );

  await page.goto(`${config.base}/login`);
  await page.locator('input[name="phone"]').fill(config.phone);
  await page.locator('input[name="password"]').fill(config.password);
  await Promise.all([
    page.waitForURL(/dashboard/),
    page.locator('button[type="submit"]').click(),
  ]);

  await page.goto(`${config.base}/appointments/slots`);
  const generator = page.locator('form[action$="/appointments/slots/generate"]');
  await generator.locator('select[name="branch_id"]').selectOption(config.branch);
  await generator.locator('select[name="topic_id"]').selectOption(config.topic);
  await generator.locator('select[name="counselor_id"]').selectOption(config.counselor);
  await generator.locator('select[name="mode"]').selectOption('video');
  await generator.locator('input[name="from"]').fill(config.date);
  await generator.locator('input[name="to"]').fill(config.date);
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
    generator.getByRole('button', { name: 'تولید ایمن' }).click(),
  ]);

  await page.goto(`${config.base}/appointments/create`);
  await page.locator('select[name="client_id"]').selectOption(config.client);
  const slot = page.locator('select[name="slot_id"] option').filter({ hasText: config.date }).first();
  await expect(slot).toBeAttached();
  await page.locator('select[name="slot_id"]').selectOption(await slot.getAttribute('value'));
  await page.locator('textarea[name="notes"]').fill('ENSHA-P1-S03-W03 live browser acceptance; keep data.');
  await Promise.all([
    page.waitForURL(/appointments\/\d+$/),
    page.getByRole('button', { name: 'رزرو نهایی' }).click(),
  ]);
  const appointmentId = page.url().match(/appointments\/(\d+)$/)[1];

  await page.goto(`${config.base}/appointments/calendar`);
  const calendarDate = new Intl.DateTimeFormat('fa-IR-u-ca-persian', {
    weekday: 'long', year: 'numeric', month: 'long', day: 'numeric',
  }).format(new Date(`${config.date}T12:00:00`));
  await page.getByRole('button', { name: calendarDate, exact: true }).click();
  await page.locator('[data-calendar-filter][name="branch_id"]').selectOption(config.branch);
  await page.locator('[data-calendar-filter][name="counselor_id"]').selectOption(config.counselor);

  const event = page.locator('.calendar_default_event').filter({ hasText: config.clientText }).first();
  await expect(event).toBeVisible();

  const moveBox = await event.boundingBox();
  const moveResponse = page.waitForResponse(response =>
    response.request().method() === 'PATCH' &&
    response.url().endsWith(`/calendar/api/events/${appointmentId}`)
  );
  await page.mouse.move(moveBox.x + moveBox.width / 2, moveBox.y + moveBox.height / 2);
  await page.mouse.down();
  await page.mouse.move(moveBox.x + moveBox.width / 2, moveBox.y + moveBox.height / 2 + 90, { steps: 8 });
  await page.mouse.up();
  expect((await moveResponse).status()).toBe(200);

  const resizeBox = await event.boundingBox();
  const resizeResponse = page.waitForResponse(response =>
    response.request().method() === 'PATCH' &&
    response.url().endsWith(`/calendar/api/events/${appointmentId}`)
  );
  await page.mouse.move(resizeBox.x + 40, resizeBox.y + resizeBox.height - 5);
  await page.mouse.down();
  await page.mouse.move(resizeBox.x + 40, resizeBox.y + resizeBox.height + 25, { steps: 6 });
  await page.mouse.up();
  expect((await resizeResponse).status()).toBe(200);

  await event.click();
  const editor = page.locator('[data-edit-drawer]');
  await expect(editor).toBeVisible();
  await editor.locator('input[name="appointment_date"]').fill(config.conflictDate);
  await editor.locator('input[name="start_time"]').fill(config.conflictTime);
  await editor.locator('input[name="duration_minutes"]').fill('45');
  const conflictResponse = page.waitForResponse(response =>
    response.request().method() === 'PATCH' &&
    response.url().endsWith(`/calendar/api/events/${appointmentId}`)
  );
  await editor.getByRole('button', { name: 'ذخیره تغییرات' }).click();
  expect((await conflictResponse).status()).toBe(409);
  await expect(editor.locator('[data-edit-error]')).not.toBeEmpty();

  // W03 deliberately leaves the DEMO appointment for review. It never deletes or cancels data.
});
