const { test, expect } = require('@playwright/test');

test('S07-W01 runs the live secretary and counselor operational cycle', async ({ page }) => {
  const env = process.env;
  const required = [
    'ENSHA_BROWSER_URL', 'ENSHA_SECRETARY_USER', 'ENSHA_SECRETARY_PASSWORD',
    'ENSHA_COUNSELOR_USER', 'ENSHA_COUNSELOR_PASSWORD', 'ENSHA_W01_DATE',
    'ENSHA_W01_CLIENT_IDS', 'ENSHA_W01_SLOT_IDS', 'ENSHA_W01_TOPIC_ID', 'ENSHA_W01_WAITLIST_CLIENT_TEXT',
  ];
  test.skip(env.ENSHA_W01_LIVE !== '1' || required.some(key => !env[key]), 'Explicit live flag and fixtures are required.');

  const base = env.ENSHA_BROWSER_URL;
  const clients = env.ENSHA_W01_CLIENT_IDS.split(',');
  const slots = env.ENSHA_W01_SLOT_IDS.split(',');
  test.skip(clients.length < 4 || slots.length < 6, 'Four clients and six free slots are required.');

  async function login(user, password) {
    await page.goto(`${base}/login`);
    await page.locator('input[name="phone"]').fill(user);
    await page.locator('input[name="password"]').fill(password);
    await Promise.all([page.waitForURL(/dashboard/), page.locator('button[type="submit"]').click()]);
  }

  async function book(clientId, slotId) {
    await page.goto(`${base}/appointments/create`);
    await page.locator('select[name="client_id"]').selectOption(clientId);
    await page.locator('select[name="slot_id"]').selectOption(slotId);
    await page.locator('textarea[name="notes"]').fill('ENSHA-P1-S07-W01 live acceptance; keep data.');
    await Promise.all([
      page.waitForURL(/appointments\/\d+$/),
      page.getByRole('button', { name: 'رزرو نهایی' }).click(),
    ]);
    return page.url().match(/appointments\/(\d+)$/)[1];
  }

  await login(env.ENSHA_SECRETARY_USER, env.ENSHA_SECRETARY_PASSWORD);
  const completeId = await book(clients[0], slots[0]);
  const noShowId = await book(clients[1], slots[1]);
  const cancelId = await book(clients[2], slots[2]);
  const rescheduleId = await book(clients[3], slots[3]);

  await page.goto(`${base}/operations?date=${env.ENSHA_W01_DATE}`);
  await page.locator(`form[action$="/operations/appointments/${completeId}/check-in"] button`).click();
  await expect(page.getByText('مراجع پذیرش شد و وارد صف انتظار گردید.')).toBeVisible();

  await page.locator(`form[action$="/operations/appointments/${noShowId}/no-show"] button`).click();
  await expect(page.getByText('عدم مراجعه ثبت شد.')).toBeVisible();

  page.once('dialog', dialog => dialog.accept());
  const cancelForm = page.locator(`form[action$="/operations/appointments/${cancelId}/cancel"]`);
  await cancelForm.locator('input[name="reason"]').fill('لغو کنترل‌شده تست پذیرش');
  await cancelForm.getByRole('button', { name: 'لغو نوبت' }).click();
  await expect(page.getByText('نوبت لغو شد و ظرفیت اسلات آزاد شد.')).toBeVisible();

  const rescheduleForm = page.locator(`form[action$="/operations/appointments/${rescheduleId}/reschedule"]`);
  await rescheduleForm.locator('select[name="slot_id"]').selectOption(slots[4]);
  await rescheduleForm.locator('input[name="reason"]').fill('جابه‌جایی کنترل‌شده تست پذیرش');
  await Promise.all([
    page.waitForURL(new RegExp(`/appointments/${rescheduleId}$`)),
    rescheduleForm.getByRole('button', { name: 'ثبت جابه‌جایی' }).click(),
  ]);

  await page.goto(`${base}/operations?date=${env.ENSHA_W01_DATE}`);
  const waitlistForm = page.locator('form[action$="/operations/waitlist"]');
  await waitlistForm.locator('select[name="client_id"]').selectOption(clients[1]);
  await waitlistForm.locator('select[name="topic_id"]').selectOption(env.ENSHA_W01_TOPIC_ID);
  await waitlistForm.locator('input[name="priority"]').fill('5');
  await waitlistForm.getByRole('button', { name: 'افزودن به صف انتظار' }).click();
  const waitlistCard = page.locator('div.rounded-lg').filter({ hasText: env.ENSHA_W01_WAITLIST_CLIENT_TEXT }).last();
  const promoteForm = waitlistCard.locator('form[action*="/operations/waitlist/"]');
  await promoteForm.locator('select[name="slot_id"]').selectOption(slots[5]);
  await promoteForm.getByRole('button', { name: 'تبدیل به نوبت' }).click();
  await expect(page.getByText('لیست انتظار به نوبت تبدیل شد.')).toBeVisible();

  await page.getByRole('button', { name: /خروج از سامانه/ }).first().click();
  await login(env.ENSHA_COUNSELOR_USER, env.ENSHA_COUNSELOR_PASSWORD);
  await page.goto(`${base}/counselor/appointments/${completeId}/report`);
  await page.locator(`form[action$="/counselor/appointments/${completeId}/start"] button`).click();
  await expect(page.getByText('جلسه شروع شد و گزارش آن آماده ثبت است.')).toBeVisible();
  await page.locator(`form[action$="/counselor/appointments/${completeId}/complete"] button`).click();
  await expect(page.getByText('جلسه پایان یافت؛ گزارش را تکمیل و نهایی کنید.')).toBeVisible();

  // The accepted scenario intentionally keeps all DEMO records for review.
});
