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
});

test('administrator can inspect Flash Sale Studio and its integration boundary', async ({ page }) => {
  await signInAsAdmin(page);

  const response = await page.goto('/admin/flash-sales');
  expect(response?.status()).toBe(200);

  await expect(page.getByRole('heading', { name: 'Flash Sale Studio' })).toBeVisible();
  await expect(page.getByText('E2E Winter Offer')).toBeVisible();
  await expect(page.getByText('E2E Premium English Course')).toBeVisible();
  const pricingCard = page.locator('.fs-stat').filter({ hasText: 'Pricing engine' });
  await expect(pricingCard).toContainText('Server-side quote');
  await expect(pricingCard).toContainText('Enrollment and cart integration tracked separately');
  await expect(page.getByRole('heading', { name: 'Create a premium offer' })).toBeVisible();
});
