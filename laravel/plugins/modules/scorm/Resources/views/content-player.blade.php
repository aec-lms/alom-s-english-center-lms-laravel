<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>{{ $title }} — SCORM Player</title>
    <style>
        html, body { height: 100%; margin: 0; font-family: system-ui, sans-serif; background: #f4f6f8; }
        .player { height: 100%; display: flex; flex-direction: column; }
        .player__header { min-height: 3rem; display: flex; align-items: center; padding: .5rem 1rem; background: #fff; border-bottom: 1px solid #dce1e7; }
        .player__title { margin: 0; font-size: 1rem; font-weight: 600; }
        .player__status { margin-left: auto; font-size: .875rem; color: #475569; }
        .player__content { flex: 1; min-height: 0; width: 100%; border: 0; background: #fff; }
    </style>
</head>
<body>
<div class="player">
    <header class="player__header">
        <h1 class="player__title">{{ $title }}</h1>
        <span id="runtime-status" class="player__status" role="status" aria-live="polite">{{ __('plugins.scorm::messages.ready') }}</span>
    </header>
    <iframe
        id="scorm-sco"
        class="player__content"
        src="{{ $launchUrl }}"
        title="{{ $title }}"
        sandbox="allow-scripts"
        referrerpolicy="no-referrer"
    ></iframe>
</div>
<script>
(() => {
    'use strict';

    const frame = document.getElementById('scorm-sco');
    const status = document.getElementById('runtime-status');
    const runtimeUrl = @json($runtimeUrl);
    const appOrigin = @json($appOrigin);
    const attemptId = Number(@json($attemptId));
    const token = @json($token);
    const bridgeNonce = @json($bridgeNonce);
    let stateVersion = Number(@json($stateVersion));
    let commitQueue = Promise.resolve();

    function showStatus(message) {
        status.textContent = String(message || '').slice(0, 160);
    }

    function sendResult(payload) {
        try {
            // The SCO has an opaque sandbox origin ("null"), so "*" is required
            // as targetOrigin. The source window and per-attempt nonce are checked
            // in both directions.
            frame.contentWindow.postMessage(payload, '*');
        } catch (error) {
            showStatus(@json(__('plugins.scorm::messages.connection_error')));
        }
    }

    function validCommit(message) {
        if (!message || typeof message !== 'object' || Array.isArray(message)
            || message.type !== 'scorm:commit'
            || message.attempt_id !== attemptId
            || message.bridge_nonce !== bridgeNonce
            || !['LMSCommit', 'LMSFinish'].includes(message.method)
            || typeof message.request_id !== 'string'
            || !/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(message.request_id)
            || !message.data || typeof message.data !== 'object' || Array.isArray(message.data)) {
            return false;
        }

        const entries = Object.entries(message.data);
        if (entries.length > 300) return false;
        for (const [key, value] of entries) {
            if (typeof key !== 'string' || key.length === 0 || key.length > 255
                || typeof value !== 'string' || value.length > 4096) return false;
        }

        try {
            return JSON.stringify(message.data).length <= 262144;
        } catch (error) {
            return false;
        }
    }

    async function commit(message) {
        const finish = message.method === 'LMSFinish';
        const payload = {
            attempt_id: attemptId,
            token,
            request_id: message.request_id,
            version: stateVersion,
            data: message.data,
            finish,
        };

        try {
            let response = null;
            let result = {};
            let lastNetworkError = null;

            // Retry only transport/server failures with the exact same request
            // id and payload. The server's idempotency ledger safely replays a
            // commit if the first response was lost after its transaction.
            for (let attempt = 0; attempt < 3; attempt += 1) {
                try {
                    response = await fetch(runtimeUrl, {
                        method: 'POST',
                        mode: 'cors',
                        credentials: 'omit',
                        cache: 'no-store',
                        headers: { 'Content-Type': 'text/plain;charset=UTF-8' },
                        body: JSON.stringify(payload),
                    });
                    result = await response.json().catch(() => ({}));
                    if (response.ok || response.status < 500) break;
                    lastNetworkError = new Error(result.message || 'SCORM runtime service is temporarily unavailable.');
                } catch (error) {
                    lastNetworkError = error;
                }
                if (attempt < 2) {
                    await new Promise(resolve => window.setTimeout(resolve, 250 * (attempt + 1)));
                }
            }

            if (!response || !response.ok || result.ok !== true || !Number.isInteger(Number(result.version))) {
                throw new Error(result.message || (lastNetworkError && lastNetworkError.message)
                    || @json(__('plugins.scorm::messages.save_failed')));
            }

            stateVersion = Number(result.version);
            showStatus(finish ? @json(__('plugins.scorm::messages.saved')) : @json(__('plugins.scorm::messages.progress_saved')));
            sendResult({
                type: 'scorm:commit-result',
                bridge_nonce: bridgeNonce,
                request_id: message.request_id,
                ok: true,
                version: stateVersion,
            });
        } catch (error) {
            const messageText = String(error && error.message ? error.message : @json(__('plugins.scorm::messages.connection_error')));
            showStatus(messageText);
            sendResult({
                type: 'scorm:commit-result',
                bridge_nonce: bridgeNonce,
                request_id: message.request_id,
                ok: false,
                message: messageText.slice(0, 160),
                version: stateVersion,
            });
        }
    }

    window.addEventListener('message', event => {
        // The SCO is deliberately sandboxed without allow-same-origin.
        if (event.source !== frame.contentWindow || event.origin !== 'null'
            || !event.data || typeof event.data !== 'object') return;

        if (event.data.type === 'scorm:status'
            && event.data.bridge_nonce === bridgeNonce) {
            showStatus(event.data.message);
            return;
        }

        if (!validCommit(event.data)) return;

        // Serialize commits so every request uses the last server-confirmed
        // version; overlapping LMSCommit/LMSFinish calls cannot race or reorder.
        commitQueue = commitQueue.then(() => commit(event.data)).catch(error => {
            showStatus(@json(__('plugins.scorm::messages.connection_error')));
        });
    });
})();
</script>
</body>
</html>
