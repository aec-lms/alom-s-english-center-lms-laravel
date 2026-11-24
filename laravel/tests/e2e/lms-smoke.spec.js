const { test, expect } = require('@playwright/test');

test('public pages render and login password visibility works', async ({ page }) => {
  const home = await page.goto('/');
  expect(home.status()).toBe(200);
  const login = await page.goto('/login');
  expect(login.status()).toBe(200);
  await expect(page.getByRole('heading', { name: 'Welcome Back!' })).toBeVisible();
  await expect(page.locator('#login-password-input')).toHaveAttribute('type', 'password');
  await page.getByRole('button', { name: 'Toggle password visibility' }).click();
  await expect(page.locator('#login-password-input')).toHaveAttribute('type', 'text');
  const register = await page.goto('/register');
  expect(register.status()).toBe(200);
  await expect(page.getByRole('heading', { name: 'Create Your Learner Account' })).toBeVisible();
  const forgot = await page.goto('/forgot-password');
  expect(forgot.status()).toBe(200);
});

test('admin can sign in and open Recycle Bin', async ({ page }) => {
  await page.goto('/login');
  await page.getByPlaceholder('Email Address').fill('e2e-admin@aec.test');
  await page.locator('#login-password-input').fill('E2E-Strong-Password-2026!');
  await page.getByRole('button', { name: /sign in/i }).click();
  await expect(page).toHaveURL(/\/admin(?:\/|$)/);
  await expect(page.getByRole('heading', { name: 'Dashboard', exact: true })).toBeVisible();
  const response = await page.goto('/admin/recycle-bin');
  expect(response.status()).toBe(200);
  await expect(page.getByRole('heading', { name: 'Admin Recycle Bin' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Deleted Students' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Deleted Instructors' })).toBeVisible();
  const plugins = await page.goto('/admin/plugins');
  expect(plugins.status()).toBe(200);
  await expect(page.getByRole('heading', { name: /Plugin Center/ })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Plugin Recycle Bin' })).toBeVisible();
});

test('guest cannot open the Admin Recycle Bin', async ({ page }) => {
  await page.goto('/admin/recycle-bin');
  await expect(page).toHaveURL(/\/login(?:\?|$)/);
  await expect(page.getByRole('heading', { name: 'Welcome Back!' })).toBeVisible();
});

test('admin can reorder dashboard widgets and save the layout', async ({ page }) => {
  await page.goto('/login');
  await page.getByPlaceholder('Email Address').fill('e2e-admin@aec.test');
  await page.locator('#login-password-input').fill('E2E-Strong-Password-2026!');
  await page.getByRole('button', { name: /sign in/i }).click();
  await expect(page).toHaveURL(/\/admin(?:\/|$)/);
  await page.getByText('Customize Dashboard').click();

  const moveSystemUp = page.getByRole('button', { name: 'Move System Information up' });
  for (let i = 0; i < 4; i++) {
    await moveSystemUp.click();
  }
  await page.getByRole('button', { name: 'Save layout' }).click();
  await expect(page.locator('[data-dashboard-widget]').first()).toHaveAttribute('data-dashboard-widget', 'system_info');
  await expect(page.getByRole('heading', { name: 'System Information' })).toBeVisible();
});
