const { test, expect } = require('@playwright/test');

test('real Laravel SCORM launch isolates an imported SCO and persists a legitimate SCORM 1.2 commit', async ({ page }) => {
  const appOrigin = process.env.APP_URL || 'http://lms.e2e.test:8000';
  const playerOrigin = process.env.SCORM_PLAYER_ORIGIN || 'http://player.e2e.test:8000';
  const contentOrigin = process.env.SCORM_CONTENT_ORIGIN || 'http://content.e2e.test:8000';
  const password = 'SCORM-E2E-Strong-Password-2026!';

  await page.goto('/login');
  await page.locator('input[name="email"]').fill('scorm-e2e-student@aec.test');
  await page.locator('input[name="password"]').fill(password);
  await Promise.all([
    page.waitForURL(url => !url.pathname.endsWith('/login')),
    page.getByRole('button', { name: 'Sign In' }).click(),
  ]);

  await page.goto('/my-learning/scorm');
  const activityLink = page.locator('a.open').first();
  await expect(activityLink).toBeVisible();
  const launchUrl = new URL(await activityLink.getAttribute('href'), appOrigin);
  const launchPath = launchUrl.pathname;
  expect(launchPath).toMatch(/^\/my-learning\/scorm\/\d+$/);

  const playerResponsePromise = page.waitForResponse(response =>
    response.url().startsWith(playerOrigin + '/scorm-player/'));
  const bootstrapResponsePromise = page.waitForResponse(response =>
    response.url().startsWith(contentOrigin + '/scorm-bootstrap/'));
  const contentResponsePromise = page.waitForResponse(response =>
    response.url().startsWith(contentOrigin + '/scorm-content/'));
  const commitResponsePromise = page.waitForResponse(response =>
    response.url() === appOrigin + '/api/scorm-runtime/commit'
    && response.request().method() === 'POST');

  await page.goto(launchPath);
  const [playerResponse, bootstrapResponse, contentResponse, commitResponse] = await Promise.all([
    playerResponsePromise,
    bootstrapResponsePromise,
    contentResponsePromise,
    commitResponsePromise,
  ]);

  expect(playerResponse.ok()).toBeTruthy();
  expect(bootstrapResponse.status()).toBe(302);
  expect(contentResponse.ok()).toBeTruthy();
  expect(commitResponse.ok()).toBeTruthy();

  const playerHeaders = await playerResponse.allHeaders();
  expect(playerHeaders['content-security-policy']).toContain('frame-src ' + contentOrigin);
  expect(playerHeaders['content-security-policy']).toContain('frame-ancestors ' + appOrigin);
  expect(playerHeaders['referrer-policy']).toBe('no-referrer');
  expect(playerHeaders['cache-control']).toContain('no-store');

  const bootstrapHeaders = await bootstrapResponse.allHeaders();
  expect(bootstrapHeaders['referrer-policy']).toBe('no-referrer');
  expect(bootstrapHeaders['cache-control']).toContain('no-store');
  expect(bootstrapHeaders['set-cookie']).toContain('HttpOnly');
  expect(bootstrapHeaders['set-cookie']).toContain('SameSite=Lax');
  expect(bootstrapHeaders['set-cookie']).not.toContain('Domain=');

  const contentHeaders = await contentResponse.allHeaders();
  expect(contentHeaders['content-security-policy']).toContain('frame-ancestors ' + appOrigin + ' ' + playerOrigin);
  expect(contentHeaders['referrer-policy']).toBe('no-referrer');
  expect(contentHeaders['cache-control']).toContain('private');
  expect(contentHeaders['cache-control']).toContain('no-store');

  const commitHeaders = await commitResponse.allHeaders();
  expect(commitHeaders['access-control-allow-origin']).toBe(playerOrigin);

  const playerFrame = page.frameLocator('iframe.player');
  await expect(playerFrame.locator('#scorm-sco')).toHaveAttribute('sandbox', 'allow-scripts');
  await expect(playerFrame.locator('#runtime-status')).toContainText('Progress saved');

  const scoFrame = page.frames().find(frame => frame.url().startsWith(contentOrigin + '/scorm-content/'));
  expect(scoFrame).toBeTruthy();
  expect(scoFrame.url()).not.toContain('/scorm-bootstrap/');
  const firstLaunchToken = (await playerResponse.url()).match(/\/scorm-player\/\d+\/([^/?]+)/)?.[1];
  expect(firstLaunchToken).toBeTruthy();
  expect(scoFrame.url()).not.toContain(firstLaunchToken);

  const firstDocument = scoFrame.locator('body');
  await expect(firstDocument).toHaveAttribute('data-api-ready', 'true');
  await expect(firstDocument).toHaveAttribute('data-parent-dom-blocked', 'true');
  await expect(firstDocument).toHaveAttribute('data-lms-dom-blocked', 'true');
  await expect(firstDocument).toHaveAttribute('data-frame-element-available', 'false');
  await expect(firstDocument).toHaveAttribute('data-sandbox-removal-succeeded', 'false');
  await expect(firstDocument).toHaveAttribute('data-cookie-readable', 'false');
  await expect(firstDocument).toHaveAttribute('data-initial-location', '');

  const secondPlayerResponsePromise = page.waitForResponse(response =>
    response.url().startsWith(playerOrigin + '/scorm-player/'));
  const secondContentResponsePromise = page.waitForResponse(response =>
    response.url().startsWith(contentOrigin + '/scorm-content/'));
  await page.goto(launchPath);
  await Promise.all([secondPlayerResponsePromise, secondContentResponsePromise]);

  const resumedFrame = page.frames().find(frame => frame.url().startsWith(contentOrigin + '/scorm-content/'));
  expect(resumedFrame).toBeTruthy();
  await expect(resumedFrame.locator('body')).toHaveAttribute('data-initial-location', 'unit-1/page-2');
  await expect(resumedFrame.locator('body')).toHaveAttribute('data-parent-dom-blocked', 'true');
  await expect(resumedFrame.locator('body')).toHaveAttribute('data-lms-dom-blocked', 'true');
});
