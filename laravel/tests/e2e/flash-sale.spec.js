const { test, expect } = require('@playwright/test');

async function signInAsAdmin(page) {
  await page.goto('/login');
  await page.getByPlaceholder('Email Address').fill('e2e-admin@aec.test');
  await page.locator('#login-password-input').fill('E2E-Strong-Password-2026!');
  await page.getByRole('button', { name: /sign in/i }).click();
  await expect(page).toHaveURL(/\/admin(?:\/|$)/);
}

async function assertNoHorizontalOverflow(page) {
  const layout = await page.evaluate(() => ({
    viewportWidth: document.documentElement.clientWidth,
    documentWidth: document.documentElement.scrollWidth,
  }));
  expect(layout.documentWidth, JSON.stringify(layout))
    .toBeLessThanOrEqual(layout.viewportWidth + 1);
}

test('public Flash Sale storefront renders responsive offers with keyboard-visible CTA', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 1000 });
  const response = await page.goto('/flash-sale');
  expect(response?.status()).toBe(200);

  await expect(page.getByRole('heading', { name: 'Flash Sale E2E' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'E2E Premium English Course' })).toBeVisible();
  await expect(page.getByText('E2E Winter Offer')).toBeVisible();
  await expect(page.locator('.fp-price-disclaimer')).toContainText('final enrollment invoice amount may differ');
  await expect(page.locator('.fp-price')).toHaveText('BDT 800.00');
  await expect(page.locator('.fp-old')).toHaveText('BDT 1,000.00');

  const cta = page.getByRole('link', { name: 'Explore course' });
  await expect(cta).toHaveAttribute('href', /\/courses\/e2e-premium-english-course$/);
  const offerTerms = page.locator('.fp-card details summary');
  await expect(offerTerms).toBeVisible();
  await offerTerms.focus();
  await page.keyboard.press('Tab');
  await expect(cta).toBeFocused();
  expect(await cta.evaluate((node) => node.matches(':focus-visible'))).toBe(true);

  const desktopColumns = await page.locator('.flash-page .row').evaluate((node) =>
    getComputedStyle(node).gridTemplateColumns.split(' ').length
  );
  expect(desktopColumns).toBe(3);
  await assertNoHorizontalOverflow(page);
  await page.screenshot({ path: 'test-results/flash-sale-storefront-desktop.png', fullPage: true });

  await page.setViewportSize({ width: 768, height: 1024 });
  await page.reload();
  await expect(page.locator('.fp-card')).toBeVisible();
  const tabletColumns = await page.locator('.flash-page .row').evaluate((node) =>
    getComputedStyle(node).gridTemplateColumns.split(' ').length
  );
  expect(tabletColumns).toBe(2);
  await assertNoHorizontalOverflow(page);
  await page.screenshot({ path: 'test-results/flash-sale-storefront-tablet.png', fullPage: true });

  await page.setViewportSize({ width: 375, height: 812 });
  await page.reload();
  await expect(page.locator('.fp-card')).toBeVisible();
  const mobileColumns = await page.locator('.flash-page .row').evaluate((node) =>
    getComputedStyle(node).gridTemplateColumns.split(' ').length
  );
  expect(mobileColumns).toBe(1);
  await assertNoHorizontalOverflow(page);
  await page.screenshot({ path: 'test-results/flash-sale-storefront-mobile.png', fullPage: true });
});

test('administrator can inspect Flash Sale Studio with responsive, labelled, keyboard-accessible controls', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 1100 });
  await signInAsAdmin(page);
  const response = await page.goto('/admin/flash-sales');
  expect(response?.status()).toBe(200);

  await expect(page.getByRole('heading', { name: 'Flash Sale Studio' })).toBeVisible();
  const campaignRow = page.getByRole('row').filter({ hasText: 'E2E Winter Offer' }).first();
  await expect(campaignRow).toBeVisible();
  await expect(page.getByRole('cell', { name: 'E2E Premium English Course' })).toBeVisible();
  const pricingCard = page.locator('.fs-stat').filter({ hasText: 'Pricing engine' });
  await expect(pricingCard).toContainText('Server-side quote');
  await expect(pricingCard).toContainText('Enrollment and cart integration tracked separately');
  await expect(page.getByRole('heading', { name: 'Create a premium offer' })).toBeVisible();
  await expect(page.locator('#page_enabled')).toBeChecked();

  const createForm = page.locator('form[action$="/admin/flash-sales"]');
  const createLabels = [
    'Campaign name',
    'Course',
    'Short headline',
    'Discount model',
    'Discount value',
    'Highlight badge',
    'Campaign banner image URL (HTTPS)',
    'Button label',
    'Display priority',
    'Starts at',
    'Ends at',
    'Campaign description',
    'Terms & conditions',
    'Branch targeting',
    'Publish immediately',
    'Campaign enabled',
  ];
  for (const label of createLabels) {
    await expect(createForm.getByLabel(label)).toBeVisible();
  }

  const createTitle = createForm.getByLabel('Campaign name');
  const createCourse = createForm.getByLabel('Course');
  await createTitle.focus();
  await page.keyboard.press('Tab');
  await expect(createCourse).toBeFocused();
  expect(await createCourse.evaluate((node) => node.matches(':focus-visible'))).toBe(true);

  const desktopColumns = await page.locator('.fs-metrics').evaluate((node) =>
    getComputedStyle(node).gridTemplateColumns.split(' ').length
  );
  expect(desktopColumns).toBe(4);
  await assertNoHorizontalOverflow(page);
  await page.screenshot({ path: 'test-results/flash-sale-admin-desktop-overview.png', fullPage: true });

  await page.setViewportSize({ width: 768, height: 1024 });
  await page.reload();
  const tabletColumns = await page.locator('.fs-metrics').evaluate((node) =>
    getComputedStyle(node).gridTemplateColumns.split(' ').length
  );
  expect(tabletColumns).toBe(2);
  await assertNoHorizontalOverflow(page);
  await page.screenshot({ path: 'test-results/flash-sale-admin-tablet.png', fullPage: true });

  await page.setViewportSize({ width: 375, height: 812 });
  await page.reload();
  const mobileColumns = await page.locator('.fs-metrics').evaluate((node) =>
    getComputedStyle(node).gridTemplateColumns.split(' ').length
  );
  expect(mobileColumns).toBe(1);
  await assertNoHorizontalOverflow(page);
  await page.screenshot({ path: 'test-results/flash-sale-admin-mobile-overview.png', fullPage: true });

  await page.setViewportSize({ width: 1440, height: 1100 });
  const editDisclosure = page.locator('.fs-edit-details');
  const editSummary = editDisclosure.locator(':scope > summary');
  await editSummary.focus();
  await page.keyboard.press('Enter');
  await expect(editDisclosure).toHaveAttribute('open', '');
  const editForm = page.getByRole('form', { name: 'Edit campaign: E2E Winter Offer' });
  await expect(editForm).toBeVisible();
  await expect(editForm).not.toHaveAttribute('role', 'dialog');
  await expect(editForm).not.toHaveAttribute('aria-modal');

  const editLabels = [
    'Campaign name',
    'Course',
    'Headline',
    'Type',
    'Value',
    'Badge',
    'Priority',
    'Banner URL (HTTPS)',
    'Button label',
    'Starts',
    'Ends',
    'Description',
    'Terms',
    'Branch ID (blank = all)',
    'Published',
    'Enabled',
  ];
  for (const label of editLabels) {
    await expect(editForm.getByLabel(label)).toBeVisible();
  }

  const editTitle = editForm.getByLabel('Campaign name');
  const editCourse = editForm.getByLabel('Course');
  const closeEditor = editForm.getByRole('button', { name: 'Close editor' });
  await expect(editSummary).toBeFocused();
  await page.keyboard.press('Tab');
  await expect(closeEditor).toBeFocused();
  await page.keyboard.press('Tab');
  await expect(editTitle).toBeFocused();
  expect(await editTitle.evaluate((node) => node.matches(':focus-visible'))).toBe(true);
  await page.keyboard.press('Tab');
  await expect(editCourse).toBeFocused();
  expect(await editCourse.evaluate((node) => node.matches(':focus-visible'))).toBe(true);

  const desktopEditor = await editForm.evaluate((node) => ({
    position: getComputedStyle(node).position,
    width: node.getBoundingClientRect().width,
    viewportWidth: window.innerWidth,
  }));
  expect(desktopEditor.position).not.toBe('fixed');
  expect(desktopEditor.width).toBeGreaterThan(0);
  expect(desktopEditor.width).toBeLessThan(desktopEditor.viewportWidth);
  await page.screenshot({ path: 'test-results/flash-sale-admin-desktop-editor.png' });

  await page.setViewportSize({ width: 375, height: 812 });
  await assertNoHorizontalOverflow(page);
  const mobileEditorWidth = await editForm.evaluate((node) => node.getBoundingClientRect().width);
  expect(mobileEditorWidth).toBeLessThan(375);
  await page.screenshot({ path: 'test-results/flash-sale-admin-mobile-editor.png' });

  await closeEditor.focus();
  await expect(closeEditor).toBeFocused();
  await page.keyboard.press('Enter');
  await expect(editDisclosure).not.toHaveAttribute('open', '');
  await expect(editForm).toBeHidden();
  await expect(editSummary).toBeFocused();
});
