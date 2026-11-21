<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>{{ $title }} — Alom's English Center</title>
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        html, body { min-height: 100%; margin: 0; font-family: system-ui, sans-serif; color: #172033; background: #f5f7fb; }
        main { min-height: 100vh; display: flex; flex-direction: column; padding: clamp(.75rem, 2vw, 1.5rem); gap: 1rem; }
        header { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .75rem; }
        h1 { margin: 0; font-size: clamp(1.1rem, 2vw, 1.5rem); }
        p { margin: .25rem 0 0; color: #526078; font-size: .9rem; }
        .player { flex: 1; min-height: 65vh; width: 100%; border: 1px solid #dbe2ea; border-radius: .75rem; background: #fff; }
        .notice { padding: .75rem 1rem; border-radius: .5rem; background: #eaf2ff; color: #1e3a66; font-size: .9rem; }
        @media (max-width: 640px) { .player { min-height: 75vh; border-radius: .4rem; } }
    </style>
</head>
<body>
<main>
    <header>
        <div>
            <h1>{{ $title }}</h1>
            <p>{{ __('plugins.scorm::messages.activity_attempt') }} #{{ $attemptId }}</p>
        </div>
        <a href="{{ url('/my-learning') }}">{{ __('plugins.scorm::messages.back_to_learning') }}</a>
    </header>
    <div class="notice" role="status">{{ __('plugins.scorm::messages.save_notice') }}</div>
    <iframe
        class="player"
        src="{{ $playerUrl }}"
        title="{{ $title }} SCORM learning activity"
        sandbox="allow-scripts allow-same-origin"
        referrerpolicy="no-referrer"
        allow="autoplay; fullscreen"
    ></iframe>
</main>
</body>
</html>
