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
        .sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; border: 0; }
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
        sandbox="allow-scripts allow-same-origin"
        referrerpolicy="no-referrer"
    ></iframe>
</div>
<script>
(() => {
    'use strict';
    const contentOrigin = @json($contentOrigin);
    const frame = document.getElementById('scorm-sco');
    const status = document.getElementById('runtime-status');
    window.addEventListener('message', event => {
        if (event.origin !== contentOrigin || event.source !== frame.contentWindow
            || !event.data || typeof event.data !== 'object' || event.data.type !== 'scorm:status') return;
        status.textContent = String(event.data.message || '').slice(0, 160);
    });
})();
</script>
</body>
</html>
