<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>QR Attendance — AEC LMS</title>
    <style>
        body{font-family:system-ui,sans-serif;background:#f8fafc;color:#0f172a;margin:0;padding:24px}
        main{max-width:520px;margin:8vh auto;background:#fff;border:1px solid #e2e8f0;border-radius:18px;padding:24px}
        button{background:#4338ca;color:#fff;border:0;border-radius:10px;padding:12px 18px;font-weight:700;cursor:pointer}
        button:disabled{opacity:.5}label{display:block;margin:16px 0}#result{margin-top:16px;padding:12px;background:#f1f5f9;border-radius:10px}
    </style>
</head>
<body>
<main id="attendance-qr-checkin" data-token="{{ $token }}" data-endpoint="{{ url('/attendance/qr/check-in') }}">
    <h1>Confirm attendance</h1>
    <p>Submit this check-in only when you are physically present in your class.</p>
    <label><input type="checkbox" id="gps-consent"> I consent to one-time GPS verification for this attendance check-in.</label>
    <button id="checkin" type="button">Check in</button>
    <div id="result" role="status" aria-live="polite">Ready to verify your attendance.</div>
</main>
<script src="{{ asset('js/attendance-qr-checkin.js') }}" defer></script>
</body>
</html>
