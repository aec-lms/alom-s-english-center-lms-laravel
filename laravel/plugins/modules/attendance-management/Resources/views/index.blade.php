@extends('layouts.admin')
@section('title', 'Attendance Management — AEC LMS')
@section('content')
<div class="space-y-6 max-w-6xl">
    <div>
        <h1 class="text-2xl font-extrabold text-slate-900">Attendance & Progress Tracking</h1>
        <p class="text-sm text-slate-500 mt-1">Select a batch and mark the whole class. Changes to saved attendance require a reason.</p>
    </div>
    <div id="attendance-sync-status" role="status" aria-live="polite" class="rounded-lg bg-slate-100 px-4 py-3 text-sm text-slate-700">Online sync ready. Pending offline entries are saved on this device.</div>
    <form id="attendance-register-form" method="POST" action="{{ route('plugins.attendance.store') }}" data-sync-url="{{ route('plugins.attendance.sync') }}" class="bg-white rounded-2xl border border-slate-200 p-5 space-y-4">
        @csrf
        <div class="grid gap-4 md:grid-cols-3">
            <label class="block text-sm font-semibold">Batch
                <select id="attendance-batch" name="batch_id" required class="mt-1 w-full rounded-lg border-slate-300">
                    <option value="">Select batch</option>
                    @foreach ($batches as $batch)
                        <option value="{{ $batch->id }}" data-branch="{{ $batch->branch_id }}">{{ $batch->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block text-sm font-semibold">Attendance date
                <input type="date" name="attendance_date" value="{{ now()->toDateString() }}" required class="mt-1 w-full rounded-lg border-slate-300">
            </label>
            <label class="block text-sm font-semibold">Entry source
                <select name="source" class="mt-1 w-full rounded-lg border-slate-300"><option value="online">Online</option><option value="offline">Offline sync</option></select>
            </label>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left">
                <thead class="text-slate-500 border-b"><tr><th class="py-2">Student ID</th><th>Student</th><th>Status</th><th>Remarks</th><th>Edit reason</th></tr></thead>
                <tbody class="divide-y">
                    @forelse ($students as $student)
                        <tr data-student-branch="{{ $student->branch_id }}">
                            <td class="py-2">{{ $student->id }}</td>
                            <td>{{ $student->user?->name ?? $student->student_code }}</td>
                            <td>
                                <select name="records[{{ $loop->index }}][status]" class="rounded-lg border-slate-300"><option value="present">Present</option><option value="absent">Absent</option><option value="late">Late</option><option value="excused">Excused</option></select>
                                <input type="hidden" name="records[{{ $loop->index }}][student_id]" value="{{ $student->id }}">
                            </td>
                            <td><input name="records[{{ $loop->index }}][remarks]" maxlength="255" class="rounded-lg border-slate-300 w-40" placeholder="Optional"></td>
                            <td><input name="records[{{ $loop->index }}][edit_reason]" maxlength="255" class="rounded-lg border-slate-300 w-44" placeholder="Required for changes"></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-4 text-slate-400">No students available for this branch.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="flex justify-end"><button type="submit" class="rounded-lg bg-indigo-600 text-white px-5 py-2 font-semibold">Save attendance</button></div>
    </form>
    <div class="bg-white rounded-2xl border border-slate-200 p-5">
        <h2 class="font-bold text-lg mb-3">Recent attendance</h2>
        <div class="overflow-x-auto"><table class="w-full text-xs text-left">
            <thead class="text-slate-500 border-b"><tr><th class="py-2">Date</th><th>Batch ID</th><th>Student ID</th><th>Branch</th><th>Status</th><th>Remarks</th></tr></thead>
            <tbody class="divide-y">
                @forelse ($records as $rec)
                    <tr><td class="py-2 font-mono">{{ $rec->attendance_date?->toDateString() }}</td><td>{{ $rec->batch_id }}</td><td>{{ $rec->student_id }}</td><td>{{ $rec->branch_id }}</td><td class="uppercase font-semibold">{{ $rec->status }}</td><td>{{ $rec->remarks ?? '—' }}</td></tr>
                @empty
                    <tr><td colspan="6" class="py-4 text-slate-400">No attendance marked yet.</td></tr>
                @endforelse
            </tbody>
        </table></div>
        <div class="mt-4">{{ $records->links() }}</div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const batch = document.getElementById('attendance-batch');
    const form = document.getElementById('attendance-register-form');
    const status = document.getElementById('attendance-sync-status');
    const rows = document.querySelectorAll('[data-student-branch]');
    const DB_NAME = 'aec-lms-attendance-offline';
    const STORE = 'pending-sync';
    const announce = message => { status.textContent = message; };

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
            request.onsuccess = () => { db.close(); resolve(request.result || []); };
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
        if (!batchId || !date || records.length === 0) throw new Error('Select a batch with students before saving attendance.');
        return { batch_id: Number(batchId), attendance_date: date, records };
    };

    let syncing = false;
    const syncPending = async () => {
        if (syncing || !navigator.onLine) return;
        syncing = true;
        try {
            const pending = await allPending();
            if (!pending.length) { announce('All attendance entries are synchronized.'); return; }
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
        submit.disabled = true;
        try {
            await addPending({ id: uuid(), payload: readPayload(), created_at: new Date().toISOString() });
            announce('Attendance safely saved on this device. ' + (navigator.onLine ? 'Starting sync…' : 'It will sync when internet returns.'));
            await syncPending();
        } catch (error) { announce(error.message); }
        finally { submit.disabled = false; }
    });
    window.addEventListener('online', syncPending);
    window.addEventListener('offline', () => announce('You are offline. New attendance will be queued on this device.'));
    if (navigator.onLine) syncPending();
    else announce('You are offline. Attendance will be queued on this device.');
});
</script>
@endsection
