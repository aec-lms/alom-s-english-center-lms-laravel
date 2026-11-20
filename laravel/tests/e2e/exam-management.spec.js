const { test, expect } = require('@playwright/test');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '../..');
const fixturePath = path.join(root, 'storage/framework/testing/e2e-exam-fixture.json');
const fixture = JSON.parse(fs.readFileSync(fixturePath, 'utf8'));

function runPhp(script, ...args) {
  return execFileSync('php', [path.join(__dirname, script), ...args.map(String)], {
    cwd: root,
    encoding: 'utf8',
  }).trim();
}

async function signInAsExamStudent(page) {
  await page.goto('/login');
  await page.getByPlaceholder('Email Address').fill(fixture.student_email);
  await page.locator('#login-password-input').fill('E2E-Strong-Password-2026!');
  await page.getByRole('button', { name: /sign in/i }).click();
  await page.waitForLoadState('networkidle');
}

test('student exam journey uses frozen snapshot, autosaves safely, and remains accessible on desktop/mobile', async ({ page }) => {
  await signInAsExamStudent(page);

  const takeUrl = `/exams/${fixture.exam_id}/take`;
  const firstResponse = await page.goto(takeUrl);
  expect(firstResponse.status()).toBe(200);
  const firstHtml = await firstResponse.text();
  expect(firstHtml).not.toMatch(/correct_option_index|correct_answer|exam_snapshot|answer_key/i);

  await expect(page.getByRole('heading', { name: 'E2E Frozen Snapshot Exam' })).toBeVisible();
  await expect(page.getByRole('group', { name: /E2E snapshot question/ })).toBeVisible();
  await expect(page.getByRole('radio', { name: '3' })).toBeVisible();
  await expect(page.getByRole('radio', { name: '4' })).toBeVisible();
  await expect(page.getByRole('radio', { name: '5' })).toBeVisible();
  await expect(page.getByText('30 minutes')).toBeVisible();
  await expect(page.getByText('Pass marks: 2')).toBeVisible();

  // Verify the native radio group supports keyboard selection and visible focus.
  const optionThree = page.getByRole('radio', { name: '3' });
  const optionFour = page.getByRole('radio', { name: '4' });
  const autosaveStatuses = [];
  let submitStatus = null;
  page.on('response', (response) => {
    const request = response.request();
    if (request.method() !== 'POST') return;
    if (request.url().includes(`/exams/${fixture.exam_id}/attempt/autosave`)) {
      autosaveStatuses.push(response.status());
    }
    if (request.url().endsWith(`/exams/${fixture.exam_id}/attempt`)) {
      submitStatus = response.status();
    }
  });
  await optionThree.check();
  await expect(page.getByRole('status')).toHaveText('All selected answers saved.');
  await optionThree.focus();
  await page.keyboard.press('ArrowRight');
  await expect(optionFour).toBeFocused();
  await expect(optionFour).toBeChecked();
  expect(await optionFour.evaluate((element) => element.matches(':focus-visible'))).toBe(true);
  await expect(page.getByRole('status')).toHaveText('All selected answers saved.');
  const optionLabel = optionFour.locator('xpath=ancestor::label');
  const touchTargetHeight = await optionLabel.evaluate((element) => element.getBoundingClientRect().height);
  expect(touchTargetHeight).toBeGreaterThanOrEqual(48);

  await expect(page.getByRole('status')).toHaveText('All selected answers saved.');
  await page.screenshot({ path: 'test-results/exam-attempt-desktop.png', fullPage: true });

  await page.setViewportSize({ width: 375, height: 812 });
  await expect(optionFour).toBeVisible();
  const overflowReport = await page.evaluate(() => ({
    viewport: window.innerWidth,
    document: document.documentElement.scrollWidth,
    offenders: [...document.querySelectorAll('body *')]
      .filter((element) => {
        const rect = element.getBoundingClientRect();
        return rect.width > 0 && (rect.right > window.innerWidth + 1 || rect.left < -1);
      })
      .slice(0, 20)
      .map((element) => ({
        tag: element.tagName,
        className: typeof element.className === 'string' ? element.className : '',
        text: (element.textContent || '').trim().slice(0, 60),
        left: Math.round(element.getBoundingClientRect().left),
        right: Math.round(element.getBoundingClientRect().right),
      })),
  }));
  expect(overflowReport.document, JSON.stringify(overflowReport)).toBeLessThanOrEqual(overflowReport.viewport);
  await page.screenshot({ path: 'test-results/exam-attempt-mobile.png', fullPage: true });

  await page.setViewportSize({ width: 768, height: 1024 });
  await expect(optionFour).toBeVisible();
  const tabletWidth = await page.evaluate(() => ({
    viewport: window.innerWidth,
    document: document.documentElement.scrollWidth,
  }));
  expect(tabletWidth.document, JSON.stringify(tabletWidth)).toBeLessThanOrEqual(tabletWidth.viewport);
  await page.screenshot({ path: 'test-results/exam-attempt-tablet.png', fullPage: true });

  await page.setViewportSize({ width: 1280, height: 900 });

  const attemptId = Number(await page.locator('form[data-attempt-id]').getAttribute('data-attempt-id'));
  expect(attemptId).toBeGreaterThan(0);
  // Use the application's named history route rather than assuming a URL
  // shape; this keeps the browser journey aligned with the actual route map.
  const historyUrl = await page.getByRole('link', { name: 'My attempt history' }).getAttribute('href');
  const dashboardUrl = await page.getByRole('link', { name: 'My Dashboard' }).getAttribute('href');
  expect(historyUrl).toBeTruthy();
  expect(dashboardUrl).toBeTruthy();

  // Simulate an instructor changing the source question and scoring rules while
  // the student still has an active attempt. The browser must continue using its
  // persisted attempt snapshot, not the mutable source exam.
  runPhp('mutate-exam-source.php', fixture.exam_id);
  const resumedResponse = await page.reload();
  expect(resumedResponse.status()).toBe(200);
  const resumedHtml = await resumedResponse.text();
  expect(resumedHtml).not.toMatch(/correct_option_index|correct_answer|exam_snapshot|answer_key/i);
  await expect(page.getByText('E2E snapshot question: what is 2 + 2?')).toBeVisible();
  await expect(page.getByText('UPDATED SOURCE QUESTION')).toHaveCount(0);
  await expect(page.getByRole('radio', { name: '3' })).toBeVisible();
  await expect(page.getByRole('radio', { name: '4' })).toBeChecked();
  await expect(page.getByRole('radio', { name: 'Edited option X' })).toHaveCount(0);
  await expect(page.getByText('30 minutes')).toBeVisible();
  await expect(page.getByText('Pass marks: 2')).toBeVisible();

  let submitPayload = '';
  let autosavePayload = '';
  page.on('request', (request) => {
    if (request.method() !== 'POST') return;
    if (request.url().includes(`/exams/${fixture.exam_id}/attempt/autosave`)) {
      autosavePayload = request.postData() || '';
    }
    if (request.url().includes(`/exams/${fixture.exam_id}/attempt`)
      && !request.url().includes('/autosave')) {
      submitPayload = request.postData() || '';
    }
  });

  await page.getByRole('radio', { name: '3' }).check();
  await expect(page.getByRole('status')).toHaveText('All selected answers saved.');
  await optionFour.check();
  await expect(page.getByRole('status')).toHaveText('All selected answers saved.');
  expect(autosavePayload).toContain('attempt_id');
  expect(autosavePayload).not.toMatch(/correct_option_index|correct_answer|answer_key/i);

  page.once('dialog', (dialog) => dialog.accept());
  await page.getByRole('button', { name: /submit exam/i }).click();
  expect(submitPayload).toContain('attempt_id');
  expect(submitPayload).not.toMatch(/correct_option_index|correct_answer|answer_key/i);
  // Wait for the controller's final redirect, not merely the POST action URL.
  // The latter changes pathname before the request is processed and can let a
  // following page.goto() cancel submission, leaving the attempt in progress.
  const dashboardPath = new URL(dashboardUrl, page.url()).pathname;
  await page.waitForURL((url) => url.pathname === dashboardPath);
  expect(autosaveStatuses.length).toBeGreaterThanOrEqual(4);
  expect(autosaveStatuses.every((status) => status === 200)).toBe(true);
  expect(submitStatus).toBe(302);

  await page.goto(historyUrl);
  await expect(page.getByText('2.00 / 2.00')).toBeVisible();
  await expect(page.getByText('Passed')).toBeVisible();
});

test('legacy pending attempt returns actionable 409 without changing score or submission state', async ({ page }) => {
  await signInAsExamStudent(page);

  const response = await page.goto(`/exams/${fixture.legacy_exam_id}/take`);
  expect(response.status()).toBe(409);
  await expect(page.getByRole('heading', { name: 'We need to verify this attempt' })).toBeVisible();
  await expect(page.getByRole('alert')).toContainText('Your attempt has not been submitted and your score has not changed.');
  await expect(page.getByText('Please contact your instructor or school administrator').first()).toBeVisible();

  const state = JSON.parse(runPhp('inspect-exam-attempt.php', fixture.legacy_attempt_id));
  expect(state.submitted_at).toBeNull();
  expect(state.score_obtained).toBe(7);
  expect(state.total_marks).toBe(10);
  expect(state.exam_snapshot).toBeNull();
});


test('corrupt snapshot returns actionable 409 without changing historical attempt data', async ({ page }) => {
  await signInAsExamStudent(page);

  const response = await page.goto(`/exams/${fixture.corrupt_exam_id}/take`);
  expect(response.status()).toBe(409);
  await expect(page.getByRole('heading', { name: 'We need to verify this attempt' })).toBeVisible();
  await expect(page.getByRole('alert')).toContainText('Your attempt has not been submitted and your score has not changed.');
  await expect(page.getByText('Please contact your instructor or school administrator').first()).toBeVisible();

  const state = JSON.parse(runPhp('inspect-exam-attempt.php', fixture.corrupt_attempt_id));
  expect(state.submitted_at).toBeNull();
  expect(state.score_obtained).toBe(3.25);
  expect(state.total_marks).toBe(10);
  expect(state.exam_snapshot.version).toBe(999);
  expect(state.exam_snapshot.questions).toEqual([]);
});
