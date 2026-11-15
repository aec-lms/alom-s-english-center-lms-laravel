import { expect, test } from '@playwright/test';

test('public home and course catalogue render', async ({ page }) => {
  await page.goto('/');
  await expect(page.locator('header')).toBeVisible();
  const navigation = page.getByRole('navigation');
  await expect(navigation.getByRole('button', { name: 'Home' })).toBeVisible();

  await navigation.getByRole('button', { name: 'Courses' }).click();
  await expect(
    page.getByRole('heading', { name: 'All English Language & IELTS Courses' }),
  ).toBeVisible();
});

test('visitor can open the sign-in screen from public navigation', async ({ page }) => {
  await page.goto('/');
  await page.getByRole('button', { name: 'Login' }).click();

  await expect(page.getByRole('heading', { name: "Alom's English Center" })).toBeVisible();
  await expect(page.getByLabel('Email address')).toHaveAttribute('type', 'email');
  await expect(page.getByLabel('Password')).toHaveAttribute('type', 'password');
  await expect(page.getByRole('button', { name: 'Sign in' })).toBeVisible();
});

test('mobile navigation opens the course catalogue', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('/');

  await page.getByRole('button', { name: 'Toggle navigation menu' }).click();
  await page.getByRole('button', { name: 'Courses' }).last().click();

  await expect(
    page.getByRole('heading', { name: 'All English Language & IELTS Courses' }),
  ).toBeVisible();
});

test('visitor can open and dismiss the official certificate verification form', async ({ page }) => {
  await page.goto('/');
  await page.getByRole('button', { name: 'Result', exact: true }).click();

  await expect(page.getByRole('heading', { name: 'Official Certificate Verification' })).toBeVisible();
  await expect(page.getByPlaceholder('AEC-CERT-2026-XXXXXXXXXXXX')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Verify', exact: true })).toBeVisible();

  await page.getByRole('button', { name: 'Close' }).first().click();
  await expect(page.getByRole('heading', { name: 'Official Certificate Verification' })).toBeHidden();
});

test('sign-in screen exposes the password recovery workflow', async ({ page }) => {
  await page.goto('/');
  await page.getByRole('button', { name: 'Login' }).click();
  await page.getByRole('button', { name: 'Forgot password?' }).click();

  await expect(page.getByRole('heading', { name: 'Reset your password' })).toBeVisible();
  await expect(page.getByLabel('Email address')).toHaveAttribute('type', 'email');
  await expect(page.getByRole('button', { name: 'Send reset instructions' })).toBeVisible();
});
