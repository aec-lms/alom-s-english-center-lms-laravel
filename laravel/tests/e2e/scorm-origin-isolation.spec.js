const { test, expect } = require('@playwright/test');

test('untrusted SCORM content cannot inspect or escape the trusted LMS player', async ({ page }) => {
  const contentOrigin = 'https://scorm-content.e2e.invalid';
  const appOrigin = 'http://127.0.0.1:8000';
  const secret = 'lms-parent-secret-must-never-be-readable-from-sco';

  await page.goto('/');
  await page.route(`${contentOrigin}/**`, route => route.fulfill({
    status: 200,
    contentType: 'text/html; charset=utf-8',
    headers: {
      'Referrer-Policy': 'no-referrer',
      'Cross-Origin-Resource-Policy': 'cross-origin',
    },
    body: `<!doctype html>
<html><head><meta charset="utf-8"></head><body>
<script>
(() => {
  const result = {
    parentDocumentReadable: false,
    parentCookieReadable: false,
    parentStorageReadable: false,
    parentSecret: null,
    frameElementAvailable: window.frameElement !== null,
    sandboxRemovalSucceeded: false,
    contentCookie: document.cookie
  };

  try {
    result.parentSecret = parent.document.querySelector('[data-lms-secret]').textContent;
    result.parentDocumentReadable = true;
  } catch (error) {}
  try {
    parent.document.cookie;
    result.parentCookieReadable = true;
  } catch (error) {}
  try {
    parent.localStorage.getItem('lms-private-value');
    result.parentStorageReadable = true;
  } catch (error) {}
  try {
    window.frameElement.removeAttribute('sandbox');
    result.sandboxRemovalSucceeded = true;
  } catch (error) {}

  parent.postMessage({ type: 'scorm:invoke', method: 'deleteCourse', args: [1] }, '*');
  parent.postMessage({ type: 'scorm:status', message: 'ready', result }, ${JSON.stringify(appOrigin)});
})();
</script>
</body></html>`,
  }));

  await page.setContent(`
    <main>
      <p data-lms-secret>${secret}</p>
      <iframe
        id="scorm-sco"
        title="Untrusted SCORM package"
        src="${contentOrigin}/attempt/41/token/attempt-scoped-secret/index.html"
        sandbox="allow-scripts allow-same-origin"
        referrerpolicy="no-referrer"
      ></iframe>
    </main>
    <script>
      (() => {
        const frame = document.getElementById('scorm-sco');
        window.addEventListener('message', event => {
          if (event.origin !== ${JSON.stringify(contentOrigin)}
            || event.source !== frame.contentWindow
            || !event.data || typeof event.data !== 'object'
            || event.data.type !== 'scorm:status') return;
          document.body.dataset.scormStatus = String(event.data.message || '');
          document.body.dataset.scormResult = JSON.stringify(event.data.result || {});
        });
      })();
    </script>
  `);

  await expect(page.locator('body')).toHaveAttribute('data-scorm-status', 'ready');
  const result = JSON.parse(await page.locator('body').getAttribute('data-scorm-result'));

  expect(result.parentDocumentReadable).toBe(false);
  expect(result.parentCookieReadable).toBe(false);
  expect(result.parentStorageReadable).toBe(false);
  expect(result.parentSecret).toBeNull();
  expect(result.frameElementAvailable).toBe(false);
  expect(result.sandboxRemovalSucceeded).toBe(false);
  expect(result.contentCookie).toBe('');

  // The trusted shell accepts only a status message from the exact SCO frame.
  await expect(page.locator('body')).not.toHaveAttribute('data-scorm-arbitrary');
  await expect(page.locator('#scorm-sco')).toHaveAttribute('sandbox', 'allow-scripts allow-same-origin');
});
