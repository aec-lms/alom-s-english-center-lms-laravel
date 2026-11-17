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
<main>
    <h1>Confirm attendance</h1>
    <p>Submit this check-in only when you are physically present in your class.</p>
    <label><input type="checkbox" id="gps-consent"> I consent to one-time GPS verification for this attendance check-in.</label>
    <button id="checkin" type="button">Check in</button>
    <div id="result" role="status" aria-live="polite">Ready to verify your attendance.</div>
</main>
<script>
(() => {
    const token = @json($token);
    const button = document.getElementById('checkin');
    const result = document.getElementById('result');
    const consent = document.getElementById('gps-consent');
    const csrf = document.querySelector('meta[name="csrf-token"]').content;

    const submit = async (location = null) => {
        const payload = { token, gps_consent: consent.checked };
        if (location) {
            payload.latitude = location.coords.latitude;
            payload.longitude = location.coords.longitude;
            payload.gps_accuracy_meters = Math.max(1, Math.ceil(location.coords.accuracy));
        }
        const response = await fetch(@json(route('plugins.attendance.qr.checkin')), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify(payload)
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(data.message || 'Attendance check-in failed.');
        result.textContent = 'Attendance confirmed at ' + data.checked_in_at;
        button.disabled = true;
    };

    button.addEventListener('click', async () => {
        button.disabled = true;
        result.textContent = 'Verifying attendance…';
        try {
            if (consent.checked) {
                if (!navigator.geolocation) throw new Error('This browser does not support GPS verification.');
                const location = await new Promise((resolve, reject) => navigator.geolocation.getCurrentPosition(resolve, reject, {
                    enableHighAccuracy: true, timeout: 10000, maximumAge: 0
                }));
                await submit(location);
            } else {
                await submit();
            }
        } catch (error) {
            result.textContent = error.message || 'Could not verify your location.';
            button.disabled = false;
        }
    });
})();
</script>
</body>
</html>
