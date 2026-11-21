const { test, expect } = require('@playwright/test');

test('untrusted SCO runs on an opaque content origin with no LMS or player DOM access', async ({ page }) => {
  const appOrigin = 'http://127.0.0.1:8000';
  const playerOrigin = 'https://scorm-player.e2e.invalid';
  const contentOrigin = 'https://scorm-content.e2e.invalid';
  const playerToken = 'attempt-token-visible-only-to-trusted-player';
  const bridgeNonce = 'abcdef0123456789'.repeat(4);
  const lmsSecret = 'lms-session-secret';
  const playerSecret = 'trusted-player-secret';

  await page.goto('/');
  await page.evaluate(secret => {
    document.cookie = 'lms_session=' + encodeURIComponent(secret) + '; path=/; SameSite=Lax';
    document.body.dataset.lmsSecret = secret;
  }, lmsSecret);

  await page.route(`${playerOrigin}/**`, route => route.fulfill({
    status: 200,
    contentType: 'text/html; charset=utf-8',
    headers: {
      'Content-Security-Policy': `default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; frame-src ${contentOrigin}; frame-ancestors ${appOrigin}; connect-src ${appOrigin}`,
      'Referrer-Policy': 'no-referrer',
      'Cache-Control': 'private, no-store',
    },
    body: `<!doctype html>
<html><head><meta charset="utf-8"><meta name="referrer" content="no-referrer"><title>Trusted player</title></head>
<body data-player-secret="${playerSecret}">
  <p id="player-secret">${playerSecret}</p>
  <span id="runtime-status"></span>
  <iframe id="scorm-sco" title="Untrusted SCO"
    src="${contentOrigin}/scorm-content/41/index.html"
    sandbox="allow-scripts" referrerpolicy="no-referrer"></iframe>
  <script>
    (() => {
      const frame = document.getElementById('scorm-sco');
      const status = document.getElementById('runtime-status');
      const bridgeNonce = ${JSON.stringify(bridgeNonce)};
      const attemptToken = ${JSON.stringify(playerToken)};
      window.addEventListener('message', event => {
        if (event.source !== frame.contentWindow || event.origin !== 'null'
          || !event.data || event.data.type !== 'scorm:status'
          || event.data.bridge_nonce !== bridgeNonce) return;
        status.textContent = String(event.data.message || '');
        document.body.dataset.scormResult = JSON.stringify(event.data.result || {});
      });
      window.addEventListener('message', event => {
        if (event.source !== frame.contentWindow || event.origin !== 'null'
          || !event.data || event.data.type !== 'scorm:invoke') return;
        // Deliberately do not dispatch privileged calls from package-origin messages.
        document.body.dataset.privilegedAction = 'blocked';
      });
    })();
  </script>
</body></html>`,
  }));

  await page.route(`${contentOrigin}/**`, route => route.fulfill({
    status: 200,
    contentType: 'text/html; charset=utf-8',
    headers: {
      'Content-Security-Policy': `default-src 'self' data: blob:; script-src 'self' 'unsafe-inline' 'unsafe-eval'; connect-src 'self'; frame-ancestors ${playerOrigin}; form-action 'none'; object-src 'none'`,
      'Cross-Origin-Resource-Policy': 'cross-origin',
      'Referrer-Policy': 'no-referrer',
      'Cache-Control': 'private, no-store',
    },
    body: `<!doctype html>
<html><head><meta charset="utf-8"><meta name="referrer" content="no-referrer"></head><body>
<script>
(() => {
  const result = {
    parentDocumentReadable: false,
    playerSecretReadable: false,
    lmsDocumentReadable: false,
    lmsSecretReadable: false,
    parentCookieReadable: false,
    frameElementAvailable: window.frameElement !== null,
    sandboxRemovalSucceeded: false,
    contentUrlHasToken: location.href.includes(${JSON.stringify(playerToken)}),
    contentCookie: document.cookie
  };

  try {
    result.playerSecretReadable = parent.document.getElementById('player-secret').textContent === ${JSON.stringify(playerSecret)};
    result.parentDocumentReadable = true;
  } catch (error) {}
  try {
    result.lmsSecretReadable = top.document.body.dataset.lmsSecret === ${JSON.stringify(lmsSecret)};
    result.lmsDocumentReadable = true;
  } catch (error) {}
  try {
    result.parentCookieReadable = parent.document.cookie.length > 0;
  } catch (error) {}
  try {
    window.frameElement.removeAttribute('sandbox');
    result.sandboxRemovalSucceeded = true;
  } catch (error) {}

  parent.postMessage({
    type: 'scorm:invoke',
    method: 'deleteCourse',
    args: [1],
    bridge_nonce: ${JSON.stringify(bridgeNonce)}
  }, '*');
  parent.postMessage({
    type: 'scorm:status',
    message: 'ready',
    bridge_nonce: ${JSON.stringify(bridgeNonce)},
    result
  }, '*');
})();
</script>
</body></html>`,
  }));

  await page.setContent(`
    <main>
      <iframe id="trusted-player" title="Trusted SCORM player"
        src="${playerOrigin}/scorm-player/41/attempt-token"
        sandbox="allow-scripts allow-same-origin"
        referrerpolicy="no-referrer"></iframe>
    </main>
  `);

  await expect(page.frameLocator('#trusted-player').locator('#runtime-status')).toHaveText('ready');
  const playerFrame = page.frameLocator('#trusted-player');
  await expect(playerFrame.locator('body')).toHaveAttribute('data-scorm-result', /.+/);
  const result = JSON.parse(await playerFrame.locator('body').getAttribute('data-scorm-result'));

  expect(result.parentDocumentReadable).toBe(false);
  expect(result.playerSecretReadable).toBe(false);
  expect(result.lmsDocumentReadable).toBe(false);
  expect(result.lmsSecretReadable).toBe(false);
  expect(result.parentCookieReadable).toBe(false);
  expect(result.frameElementAvailable).toBe(false);
  expect(result.sandboxRemovalSucceeded).toBe(false);
  expect(result.contentUrlHasToken).toBe(false);
  expect(result.contentCookie).toBe('');

  // The LMS cannot inspect the trusted player DOM, even though it may frame
  // that separate origin. The player alone is allowed to bridge the opaque SCO.
  const lmsCanReadPlayer = await page.evaluate(() => {
    try {
      return !!document.querySelector('#trusted-player').contentDocument.querySelector('#player-secret');
    } catch (error) {
      return false;
    }
  });
  expect(lmsCanReadPlayer).toBe(false);
  await expect(playerFrame.locator('body')).toHaveAttribute('data-privileged-action', 'blocked');
  await expect(playerFrame.locator('#scorm-sco')).toHaveAttribute('sandbox', 'allow-scripts');
});
