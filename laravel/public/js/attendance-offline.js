const initializeAttendanceOfflineSync = () => {
    const batch = document.getElementById('attendance-batch');
    const form = document.getElementById('attendance-register-form');
    const status = document.getElementById('attendance-sync-status');
    if (!batch || !form || !status) return;

    const rows = document.querySelectorAll('[data-student-branch]');
    const DB_NAME = 'aec-lms-attendance-offline';
    const STORE = 'pending-sync';
    const actorId = String(form.dataset.actorId || '');
    const announce = message => { status.textContent = message; };

    const registerOfflineShell = async () => {
        if (!('serviceWorker' in navigator)) {
            announce('Offline saving is available, but this browser does not support reopening the Attendance page offline.');
            return;
        }
        try {
            const registration = await navigator.serviceWorker.register('/service-worker.js', { scope: '/' });
            const ready = await navigator.serviceWorker.ready;
            const worker = ready.active || registration.active;
            worker?.postMessage({ type: 'CACHE_ATTENDANCE_PAGE', url: window.location.href });
        } catch (error) {
            announce('Offline page setup could not finish. Keep this page open while connected and try again.');
        }
    };

    const applyBranch = () => {
        const branch = batch.selectedOptions[0]?.dataset.branch;
        rows.forEach(row => {
            const visible = Boolean(branch) && row.dataset.studentBranch === branch;
            row.hidden = !visible;
            row.querySelectorAll('input, select').forEach(field => { field.disabled = !visible; });
        });
    };
    batch.addEventListener('change', applyBranch);
    applyBranch();

    if (!navigator.onLine) {
        const dateField = form.querySelector('[name="attendance_date"]');
        if (dateField) {
            const now = new Date();
            dateField.value = [now.getFullYear(), String(now.getMonth() + 1).padStart(2, '0'), String(now.getDate()).padStart(2, '0')].join('-');
        }
    }

    const openQueue = () => new Promise((resolve, reject) => {
        const request = indexedDB.open(DB_NAME, 1);
        request.onupgradeneeded = () => {
            if (!request.result.objectStoreNames.contains(STORE)) request.result.createObjectStore(STORE, { keyPath: 'id' });
        };
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
    const addPending = async entry => {
        const db = await openQueue();
        return new Promise((resolve, reject) => {
            const tx = db.transaction(STORE, 'readwrite');
            tx.objectStore(STORE).put(entry);
            tx.oncomplete = () => { db.close(); resolve(); };
            tx.onerror = () => { db.close(); reject(tx.error); };
        });
    };
    const allPending = async () => {
        const db = await openQueue();
        return new Promise((resolve, reject) => {
            const request = db.transaction(STORE, 'readonly').objectStore(STORE).getAll();
            request.onsuccess = () => {
                db.close();
                resolve((request.result || []).filter(entry => entry.actor_id === actorId));
            };
            request.onerror = () => { db.close(); reject(request.error); };
        });
    };
    const removePending = async id => {
        const db = await openQueue();
        return new Promise((resolve, reject) => {
            const tx = db.transaction(STORE, 'readwrite');
            tx.objectStore(STORE).delete(id);
            tx.oncomplete = () => { db.close(); resolve(); };
            tx.onerror = () => { db.close(); reject(tx.error); };
        });
    };
    const uuid = () => window.crypto?.randomUUID ? window.crypto.randomUUID() :
        'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
            const r = Math.random() * 16 | 0;
            return (c === 'x' ? r : (r & 3 | 8)).toString(16);
        });

    const readPayload = () => {
        const batchId = form.querySelector('[name="batch_id"]').value;
        const date = form.querySelector('[name="attendance_date"]').value;
        const records = [];
        form.querySelectorAll('tbody tr[data-student-branch]:not([hidden])').forEach(row => {
            const student = row.querySelector('input[name$="[student_id]"]');
            const statusField = row.querySelector('select[name$="[status]"]');
            if (!student || !statusField || statusField.disabled) return;
            records.push({
                student_id: Number(student.value),
                status: statusField.value,
                remarks: row.querySelector('input[name$="[remarks]"]')?.value || null,
                edit_reason: row.querySelector('input[name$="[edit_reason]"]')?.value || null,
                sync_uuid: uuid()
            });
        });
        if (!actorId) throw new Error('Your user session is missing. Reconnect and reload Attendance before saving offline.');
        if (!batchId || !date || records.length === 0) throw new Error('Select a batch with students before saving attendance.');
        return { batch_id: Number(batchId), attendance_date: date, source: navigator.onLine ? 'online' : 'offline', records };
    };

    let syncing = false;
    const syncPending = async () => {
        if (syncing || !navigator.onLine) return;
        syncing = true;
        try {
            const pending = await allPending();
            if (!pending.length) { announce('All attendance entries for this account are synchronized.'); return; }
            announce('Syncing ' + pending.length + ' offline attendance batch(es)…');
            for (const entry of pending) {
                const response = await fetch(form.dataset.syncUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                    },
                    body: JSON.stringify(entry.payload)
                });
                if (!response.ok) {
                    const data = await response.json().catch(() => ({}));
                    throw new Error(data.message || 'Sync failed (HTTP ' + response.status + '). Pending data was kept on this device.');
                }
                await removePending(entry.id);
            }
            const remaining = await allPending();
            announce(remaining.length ? remaining.length + ' batch(es) still pending.' : 'Attendance synchronized successfully.');
        } catch (error) {
            announce((navigator.onLine ? 'Sync paused: ' : 'Offline: ') + error.message);
        } finally { syncing = false; }
    };

    form.addEventListener('submit', async event => {
        event.preventDefault();
        const submit = form.querySelector('button[type="submit"]');
        if (submit) submit.disabled = true;
        try {
            const payload = readPayload();
            await addPending({ id: uuid(), actor_id: actorId, payload, created_at: new Date().toISOString() });
            announce('Attendance safely saved on this device. ' + (navigator.onLine ? 'Starting sync…' : 'It will sync when internet returns.'));
            await syncPending();
        } catch (error) { announce(error.message); }
        finally { if (submit) submit.disabled = false; }
    });
    window.addEventListener('online', syncPending);
    window.addEventListener('offline', () => announce('You are offline. New attendance will be queued on this device.'));
    if (navigator.onLine) syncPending();
    else announce('You are offline. Attendance will be queued on this device.');

    registerOfflineShell();
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeAttendanceOfflineSync, { once: true });
} else {
    initializeAttendanceOfflineSync();
}
