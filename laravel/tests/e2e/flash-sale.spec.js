const { test, expect } = require('@playwright/test');

async function signInAsAdmin(page) {
  await page.goto('/login');
  await page.getByPlaceholder('Email Address').fill('e2e-admin@aec.test');
  await page.locator('#login-password-input').fill('E2E-Strong-Password-2026!');
  await page.getByRole('button', { name: /sign in/i }).click();
  await expect(page).toHaveURL(/\/admin(?:\/|$)/);
}

test('public Flash Sale storefront shows the server-calculated offer and fits mobile width', async ({ page }) => {
  const response = await page.goto('/flash-sale');
  expect(response?.status()).toBe(200);

  await expect(page.getByRole('heading', { name: 'Flash Sale E2E' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'E2E Premium English Course' })).toBeVisible();
  await expect(page.getByText('E2E Winter Offer')).toBeVisible();
  await expect(page.locator('.fp-price')).toHaveText('BDT 800.00');
  await expect(page.locator('.fp-old')).toHaveText('BDT 1,000.00');
  await expect(page.getByRole('link', { name: 'Explore course' }))
    .toHaveAttribute('href', /\/courses\/e2e-premium-english-course$/);

  const desktopColumns = await page.locator('.flash-page .row').evaluate((node) =>
    getComputedStyle(node).gridTemplateColumns.split(' ').length
  );
  expect(desktopColumns).toBe(3);
  await page.screenshot({ path: 'test-results/flash-sale-storefront-desktop.png', fullPage: true });

  await page.setViewportSize({ width: 375, height: 812 });
  await page.reload();
  await expect(page.locator('.fp-card')).toBeVisible();

  const layout = await page.evaluate(() => ({
    viewportWidth: document.documentElement.clientWidth,
    documentWidth: document.documentElement.scrollWidth,
    cardWidth: document.querySelector('.fp-card')?.getBoundingClientRect().width ?? 0,
  }));
  expect(layout.documentWidth).toBeLessThanOrEqual(layout.viewportWidth + 1);
  expect(layout.cardWidth).toBeGreaterThan(0);
  const mobileColumns = await page.locator('.flash-page .row').evaluate((node) =>
    getComputedStyle(node).gridTemplateColumns.split(' ').length
  );
  expect(mobileColumns).toBe(1);
  await page.screenshot({ path: 'test-results/flash-sale-storefront-mobile.png', fullPage: true });
});

test('administrator can inspect Flash Sale Studio and its integration boundary', async ({ page }) => {
  await signInAsAdmin(page);

  const response = await page.goto('/admin/flash-sales');
  expect(response?.status()).toBe(200);

  await expect(page.getByRole('heading', { name: 'Flash Sale Studio' })).toBeVisible();
  await expect(page.getByText('E2E Winter Offer')).toBeVisible();
  await expect(page.getByRole('cell', { name: 'E2E Premium English Course' })).toBeVisible();
  const pricingCard = page.locator('.fs-stat').filter({ hasText: 'Pricing engine' });
  await expect(pricingCard).toContainText('Server-side quote');
  await expect(pricingCard).toContainText('Enrollment and cart integration tracked separately');
  await expect(page.getByRole('heading', { name: 'Create a premium offer' })).toBeVisible();
  await expect(page.locator('#page_enabled')).toBeChecked();

  const createForm = page.locator('form[action$="/admin/flash-sales"]');
  await expect(createForm.getByLabel('Campaign name')).toBeVisible();
  await expect(createForm.getByLabel('Course')).toBeVisible();
  await expect(createForm.getByLabel('Branch targeting')).toBeVisible();

  const desktopColumns = await page.locator('.fs-metrics').evaluate((node) =>
    getComputedStyle(node).gridTemplateColumns.split(' ').length
  );
  expect(desktopColumns).toBe(4);

  await page.getByText('Edit campaign', { exact: true }).click();
  const editForm = page.getByRole('dialog', { name: 'Edit campaign E2E Winter Offer' });
  await expect(editForm).toHaveAttribute('role', 'dialog');
  await expect(editForm).toHaveAttribute('aria-modal', 'true');
  await expect(editForm.getByLabel('Campaign name')).toBeVisible();
  await expect(editForm.getByLabel('Published')).toBeVisible();
  await expect(editForm.getByLabel('Enabled')).toBeVisible();
  const desktopEditor = await editForm.evaluate((node) => ({
    position: getComputedStyle(node).position,
    width: node.getBoundingClientRect().width,
    viewportWidth: window.innerWidth,
  }));
  expect(desktopEditor.position).toBe('fixed');
  expect(desktopEditor.width).toBeLessThan(desktopEditor.viewportWidth);
  await page.screenshot({ path: 'test-results/flash-sale-admin-desktop.png', fullPage: true });

  await page.setViewportSize({ width: 375, height: 812 });
  const mobileColumns = await page.locator('.fs-metrics').evaluate((node) =>
    getComputedStyle(node).gridTemplateColumns.split(' ').length
  );
  expect(mobileColumns).toBe(1);
  const mobileLayout = await page.evaluate(() => ({
    viewportWidth: document.documentElement.clientWidth,
    documentWidth: document.documentElement.scrollWidth,
  }));
  expect(mobileLayout.documentWidth).toBeLessThanOrEqual(mobileLayout.viewportWidth + 1);
  const mobileEditorWidth = await editForm.evaluate((node) => node.getBoundingClientRect().width);
  expect(mobileEditorWidth).toBeLessThan(375);
  await page.screenshot({ path: 'test-results/flash-sale-admin-mobile.png', fullPage: true });
  await page.getByText('Edit campaign', { exact: true }).click();
  await expect(editForm).toBeHidden();
});
