<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>My SCORM Activities — Alom's English Center</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #f5f7fb; color: #172033; font-family: system-ui, sans-serif; }
        main { width: min(100% - 2rem, 980px); margin: 2rem auto; }
        h1 { margin: 0; font-size: clamp(1.5rem, 3vw, 2rem); }
        .intro { margin: .4rem 0 1.5rem; color: #526078; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 260px), 1fr)); gap: 1rem; }
        .card { display: flex; flex-direction: column; gap: .8rem; padding: 1.2rem; border: 1px solid #dbe2ea; border-radius: .75rem; background: #fff; }
        .course { color: #526078; font-size: .85rem; }
        h2 { margin: 0; font-size: 1.1rem; }
        .meta { color: #64748b; font-size: .85rem; }
        .open { align-self: flex-start; display: inline-flex; padding: .55rem .9rem; border-radius: .5rem; color: #fff; background: #1d4ed8; text-decoration: none; font-weight: 600; }
        .empty { padding: 2.5rem 1rem; text-align: center; border: 1px dashed #cbd5e1; border-radius: .75rem; background: #fff; color: #64748b; }
    </style>
</head>
<body>
<main>
    <h1>My SCORM Activities</h1>
    <p class="intro">Interactive learning packages attached to courses where you are enrolled.</p>
    @if ($packages->isEmpty())
        <div class="empty">
            <h2>No SCORM activities yet</h2>
            <p>Your teachers have not published any SCORM learning packages for your enrolled courses.</p>
        </div>
    @else
        <div class="grid">
            @foreach ($packages as $package)
                <article class="card">
                    <div class="course">{{ $package->course_title }}</div>
                    <h2>{{ $package->title }}</h2>
                    <div class="meta">SCORM {{ $package->version }}</div>
                    <a class="open" href="{{ route('plugins.scorm.launch', $package->id) }}">Open activity</a>
                </article>
            @endforeach
        </div>
        <div class="mt-4">{{ $packages->links() }}</div>
    @endif
</main>
</body>
</html>
