(() => {
    const initialize = () => {
        const root = document.getElementById('attendance-qr-checkin');
        const button = document.getElementById('checkin');
        const result = document.getElementById('result');
        const consent = document.getElementById('gps-consent');
        if (!root || !button || !result || !consent) return;

        const token = root.dataset.token || '';
        const endpoint = root.dataset.endpoint || '/attendance/qr/check-in';
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';

        const send = async (position = null) => {
            const payload = { token, gps_consent: consent.checked };
            if (position) {
                payload.latitude = position.coords.latitude;
                payload.longitude = position.coords.longitude;
                payload.gps_accuracy_meters = Math.max(1, Math.ceil(position.coords.accuracy));
            }

            const response = await fetch(endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrf
                },
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
                    const position = await new Promise((resolve, reject) => navigator.geolocation.getCurrentPosition(
                        resolve,
                        () => reject(new Error('GPS permission or location lookup was not available.')),
                        { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
                    ));
                    await send(position);
                } else {
                    await send();
                }
            } catch (error) {
                result.textContent = error.message || 'Could not verify attendance.';
                button.disabled = false;
            }
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize, { once: true });
    } else {
        initialize();
    }
})();
