const { test, expect } = require('@playwright/test');

test('untrusted SCO cannot inspect LMS/player state and commits only through the trusted player bridge', async ({ page }) => {
  const appOrigin = 'https://lms.e2e.invalid';
  const playerOrigin = 'https://scorm-player.e2e.invalid';
  const contentOrigin = 'https://scorm-content.e2e.invalid';
  const playerToken = 'attempt-token-visible-only-to-trusted-player';
  const bridgeNonce = 'abcdef0123456789'.repeat(4);
  const lmsSecret = 'lms-session-secret';
  const playerSecret = 'trusted-player-secret';
  const capturedPayloads = [];

  // Simulate the three HTTPS origins used in production. A public HTTP
  // player calling localhost would be blocked by Chromium's Private Network
  // Access policy before our exact-origin CORS bridge could be exercised.
  await page.route(`${appOrigin}/scorm-test-origin`, route => route.fulfill({
    status: 200,
    contentType: 'text/html; charset=utf-8',
    body: '<!doctype html><html><head><meta charset="utf-8"></head><body></body></html>',
  }));
  await page.goto(`${appOrigin}/scorm-test-origin`);
  await page.evaluate(secret => {
    document.cookie = 'lms_session=' + encodeURIComponent(secret) + '; path=/; SameSite=Lax';
    document.body.dataset.lmsSecret = secret;
  }, lmsSecret);

  await page.route(`${appOrigin}/api/scorm-runtime/commit`, async route => {
    if (route.request().method() === 'OPTIONS') {
      await route.fulfill({
        status: 204,
        headers: {
          'Access-Control-Allow-Origin': playerOrigin,
          'Access-Control-Allow-Methods': 'POST, OPTIONS',
          'Access-Control-Allow-Headers': 'Content-Type',
          'Access-Control-Allow-Private-Network': 'true',
          'Access-Control-Max-Age': '600',
          'Vary': 'Origin',
        },
      });
      return;
    }

    const payload = JSON.parse(route.request().postData() || '{}');
    capturedPayloads.push(payload);
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      headers: { 'Access-Control-Allow-Origin': playerOrigin, 'Vary': 'Origin' },
      body: JSON.stringify({ ok: true, version: Number(payload.version) + 1, completion_state: 'in_progress' }),
    });
  });

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
      const appOrigin = ${JSON.stringify(appOrigin)};
      const bridgeNonce = ${JSON.stringify(bridgeNonce)};
      const attemptToken = ${JSON.stringify(playerToken)};
      const attemptId = 41;
      let stateVersion = 0;
      const observedMessages = [];

      window.addEventListener('message', async event => {
        if (event.data && typeof event.data === 'object') {
          observedMessages.push({
            type: event.data.type || null,
            origin: event.origin,
            sourceMatches: event.source === frame.contentWindow,
          });
          document.body.dataset.messageTrace = JSON.stringify(observedMessages);
        }
        if (event.source !== frame.contentWindow || event.origin !== 'null'
          || !event.data || typeof event.data !== 'object') return;

        if (event.data.type === 'scorm:invoke') {
          document.body.dataset.privilegedAction = 'blocked';
          return;
        }
        if (event.data.type === 'scorm:status'
          && event.data.bridge_nonce === bridgeNonce) {
          status.textContent = String(event.data.message || '');
          document.body.dataset.scormResult = JSON.stringify(event.data.result || {});
          return;
        }

        const message = event.data;
        const requestIdValid = typeof message.request_id === 'string'
          && /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(message.request_id);
        document.body.dataset.commitValidation = JSON.stringify({
          typeValid: message.type === 'scorm:commit',
          nonceValid: message.bridge_nonce === bridgeNonce,
          attemptValid: message.attempt_id === attemptId,
          methodValid: ['LMSCommit', 'LMSFinish'].includes(message.method),
          requestIdValid,
          dataValid: !!message.data && typeof message.data === 'object' && !Array.isArray(message.data),
        });
        if (message.type !== 'scorm:commit'
          || message.bridge_nonce !== bridgeNonce
          || message.attempt_id !== attemptId
          || !['LMSCommit', 'LMSFinish'].includes(message.method)
          || !requestIdValid
          || !message.data || typeof message.data !== 'object' || Array.isArray(message.data)) return;

        const payload = {
          attempt_id: attemptId,
          token: attemptToken,
          request_id: message.request_id,
          version: stateVersion,
          data: message.data,
          finish: message.method === 'LMSFinish',
        };
        let response;
        try {
          response = await fetch(appOrigin + '/api/scorm-runtime/commit', {
            method: 'POST',
            mode: 'cors',
            credentials: 'omit',
            cache: 'no-store',
            headers: { 'Content-Type': 'text/plain;charset=UTF-8' },
            body: JSON.stringify(payload),
          });
        } catch (error) {
          document.body.dataset.apiError = String(error && error.message || error);
          return;
        }
        const result = await response.json();
        if (!response.ok || result.ok !== true) return;

        stateVersion = Number(result.version);
        frame.contentWindow.postMessage({
          type: 'scorm:commit-result',
          bridge_nonce: bridgeNonce,
          request_id: message.request_id,
          ok: true,
          version: stateVersion,
        }, '*');
        document.body.dataset.apiCredentials = 'omit';
      });
    })();
  </script>
</body></html>`,
  }));

  await page.route(`${contentOrigin}/**`, route => route.fulfill({
    status: 200,
    contentType: 'text/html; charset=utf-8',
    headers: {
      'Content-Security-Policy': `default-src 'self' data: blob:; script-src 'self' 'unsafe-inline' 'unsafe-eval'; connect-src 'self'; frame-ancestors ${appOrigin} ${playerOrigin}; form-action 'none'; object-src 'none'`,
      'Cross-Origin-Resource-Policy': 'cross-origin',
      'Referrer-Policy': 'no-referrer',
      'Cache-Control': 'private, no-store',
    },
    body: `<!doctype html>
<html><head><meta charset="utf-8"><meta name="referrer" content="no-referrer"></head><body>
<script>
(() => {
  const bridgeNonce = ${JSON.stringify(bridgeNonce)};
  let contentCookie = '';
  let contentCookieReadBlocked = false;
  try {
    contentCookie = document.cookie;
  } catch (error) {
    // A sandboxed opaque-origin SCO cannot read even its own cookie jar.
    contentCookieReadBlocked = true;
  }

  const result = {
    playerDocumentReadable: false,
    playerSecretReadable: false,
    lmsDocumentReadable: false,
    lmsSecretReadable: false,
    parentCookieReadable: false,
    frameElementAvailable: window.frameElement !== null,
    sandboxRemovalSucceeded: false,
    contentUrlHasToken: location.href.includes(${JSON.stringify(playerToken)}),
    contentCookie,
    contentCookieReadBlocked
  };

  try {
    result.playerSecretReadable = parent.document.getElementById('player-secret').textContent === ${JSON.stringify(playerSecret)};
    result.playerDocumentReadable = true;
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

  window.API = {
    LMSInitialize(parameter) { return parameter === '' ? 'true' : 'false'; },
    LMSSetValue(element, value) {
      if (element !== 'cmi.core.lesson_location' || typeof value !== 'string') return 'false';
      this.data[element] = value;
      return 'true';
    },
    data: {},
    LMSCommit(parameter) {
      if (parameter !== '') return 'false';
      parent.postMessage({
        type: 'scorm:commit',
        method: 'LMSCommit',
        attempt_id: 41,
        bridge_nonce: bridgeNonce,
        request_id: '123e4567-e89b-42d3-a456-426614174000',
        data: this.data,
      }, '*');
      return 'true';
    }
  };

  window.addEventListener('message', event => {
    if (event.source !== parent || event.origin !== ${JSON.stringify(playerOrigin)}
      || !event.data || event.data.type !== 'scorm:commit-result'
      || event.data.bridge_nonce !== bridgeNonce) return;
    document.body.dataset.apiCommit = event.data.ok === true ? 'success' : 'failed';
    document.body.dataset.apiVersion = String(event.data.version);
  });

  API.LMSInitialize('');
  API.LMSSetValue('cmi.core.lesson_location', 'unit-1/page-2');
  API.LMSCommit('');

  parent.postMessage({
    type: 'scorm:invoke',
    method: 'deleteCourse',
    args: [1],
    bridge_nonce: bridgeNonce
  }, '*');
  parent.postMessage({
    type: 'scorm:status',
    message: 'ready',
    bridge_nonce: bridgeNonce,
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

  const playerFrame = page.frameLocator('#trusted-player');
  const scoFrame = playerFrame.frameLocator('#scorm-sco');
  await expect(scoFrame.locator('body')).toHaveAttribute('data-api-commit', 'success');
  await expect(playerFrame.locator('body')).toHaveAttribute('data-privileged-action', 'blocked');
  const result = JSON.parse(await playerFrame.locator('body').getAttribute('data-scorm-result'));

  expect(result.playerDocumentReadable).toBe(false);
  expect(result.playerSecretReadable).toBe(false);
  expect(result.lmsDocumentReadable).toBe(false);
  expect(result.lmsSecretReadable).toBe(false);
  expect(result.parentCookieReadable).toBe(false);
  expect(result.frameElementAvailable).toBe(false);
  expect(result.sandboxRemovalSucceeded).toBe(false);
  expect(result.contentUrlHasToken).toBe(false);
  expect(result.contentCookie).toBe('');
  expect(result.contentCookieReadBlocked).toBe(true);

  expect(capturedPayloads).toHaveLength(1);
  expect(capturedPayloads[0].attempt_id).toBe(41);
  expect(capturedPayloads[0].token).toBe(playerToken);
  expect(capturedPayloads[0].version).toBe(0);
  expect(capturedPayloads[0].data['cmi.core.lesson_location']).toBe('unit-1/page-2');
  expect(capturedPayloads[0].finish).toBe(false);
  await expect(scoFrame.locator('body')).toHaveAttribute('data-api-version', '1');

  // The LMS cannot inspect the trusted player DOM, even though it may frame
  // that separate origin. The player alone can bridge the opaque SCO.
  const lmsCanReadPlayer = await page.evaluate(() => {
    try {
      return !!document.querySelector('#trusted-player').contentDocument.querySelector('#player-secret');
    } catch (error) {
      return false;
    }
  });
  expect(lmsCanReadPlayer).toBe(false);
  await expect(playerFrame.locator('#scorm-sco')).toHaveAttribute('sandbox', 'allow-scripts');
});
