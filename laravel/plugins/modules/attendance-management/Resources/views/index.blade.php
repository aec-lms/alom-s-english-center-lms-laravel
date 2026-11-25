@extends('layouts.admin')
@section('title', 'Attendance Management — AEC LMS')
@section('content')
<div class="space-y-6 max-w-6xl">
    <div>
        <h1 class="text-2xl font-extrabold text-slate-900">Attendance & Progress Tracking</h1>
        <p class="text-sm text-slate-500 mt-1">Select a batch and mark the whole class. Changes to saved attendance require a reason.</p>
        <a href="{{ route('plugins.attendance.audit') }}" class="mt-3 inline-flex rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50">View attendance audit history →</a>
        <a href="{{ route('plugins.attendance.reports') }}" class="mt-3 ml-2 inline-flex rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700">Student reports →</a>
        <a href="{{ route('plugins.attendance.teachers.index') }}" class="mt-3 ml-2 inline-flex rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700">Teacher attendance →</a>
    </div>
    <div id="attendance-sync-status" role="status" aria-live="polite" class="rounded-lg bg-slate-100 px-4 py-3 text-sm text-slate-700">Online sync ready. Pending offline entries are saved on this device.</div>
    @if (!$attendanceReady)
        <div role="status" class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            Attendance Management is installed independently. To create new attendance records, enable
            @if (!$courseIntegrationEnabled)<strong>Course Management</strong>@endif
            @if (!$courseIntegrationEnabled && !$studentIntegrationEnabled) and @endif
            @if (!$studentIntegrationEnabled)<strong>Student Management</strong>@endif.
            Existing attendance history remains available.
        </div>
    @endif
    <form id="attendance-register-form" method="POST" action="{{ route('plugins.attendance.store') }}" data-sync-url="{{ route('plugins.attendance.sync') }}" data-actor-id="{{ auth()->id() }}" class="bg-white rounded-2xl border border-slate-200 p-5 space-y-4">
        @csrf
        <div class="grid gap-4 md:grid-cols-3">
            <label class="block text-sm font-semibold">Batch
                <select id="attendance-batch" name="batch_id" required class="mt-1 w-full rounded-lg border-slate-300">
                    <option value="">Select batch</option>
                    @foreach ($batches as $batch)
                        <option value="{{ $batch->id }}" data-branch="{{ $batch->branch_id }}" data-mapping="{{ $batch->attendance_batch_mapping_id }}">{{ $batch->name }}</option>
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
        <div class="flex justify-end"><button type="submit" @disabled(!$attendanceReady) class="rounded-lg bg-indigo-600 text-white px-5 py-2 font-semibold disabled:cursor-not-allowed disabled:opacity-50">Save attendance</button></div>
    </form>
    <div class="bg-white rounded-2xl border border-slate-200 p-5">
        <h2 class="font-bold text-lg mb-3">Recent attendance</h2>
        <div class="overflow-x-auto"><table class="w-full text-xs text-left">
            <thead class="text-slate-500 border-b"><tr><th class="py-2">Date</th><th>Batch</th><th>Student ID</th><th>Branch</th><th>Status</th><th>Remarks</th></tr></thead>
            <tbody class="divide-y">
                @forelse ($records as $rec)
                    <tr><td class="py-2 font-mono">{{ $rec->attendance_date?->toDateString() }}</td><td>{{ $rec->batchIdentity?->display_name ?? ('Batch #' . $rec->attendance_batch_mapping_id) }}</td><td>{{ $rec->student_id }}</td><td>{{ $rec->branch_id }}</td><td class="uppercase font-semibold">{{ $rec->status }}</td><td>{{ $rec->remarks ?? '—' }}</td></tr>
                @empty
                    <tr><td colspan="6" class="py-4 text-slate-400">No attendance marked yet.</td></tr>
                @endforelse
            </tbody>
        </table></div>
        <div class="mt-4">{{ $records->links() }}</div>
    </div>
</div>
<link rel="manifest" href="{{ asset('attendance.webmanifest') }}">
<link rel="stylesheet" href="{{ asset('css/attendance-offline.css') }}">
<script src="{{ asset('js/attendance-offline.js') }}" defer></script>

    <section class="bg-white rounded-2xl border border-slate-200 p-5 space-y-4">
        <div>
            <h2 class="font-bold text-lg">Batch roster & QR attendance</h2>
            <p class="text-sm text-slate-500">Assign eligible students, issue a short-lived QR session, or revoke it immediately.</p>
        </div>
        <div class="grid gap-4 md:grid-cols-2">
            <label class="block text-sm font-semibold">Batch identity
                <select id="attendance-roster-batch" class="mt-1 w-full rounded-lg border-slate-300">
                    <option value="">Select batch</option>
                    @foreach ($qrBatches as $batch)
                        <option value="{{ $batch->attendance_batch_mapping_id }}">{{ $batch->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block text-sm font-semibold">QR expiry (minutes)
                <input id="attendance-qr-expiry" type="number" min="1" max="30" value="5" class="mt-1 w-full rounded-lg border-slate-300">
            </label>
        </div>
        <details class="rounded-lg border border-slate-200 p-3">
            <summary class="cursor-pointer font-semibold">Optional GPS geofence</summary>
            <p class="text-xs text-slate-500 my-2">When configured, students must explicitly consent and share a one-time GPS reading. Raw coordinates are not stored.</p>
            <div class="grid gap-3 md:grid-cols-3">
                <input id="attendance-geo-lat" type="number" step="any" min="-90" max="90" placeholder="Latitude" class="rounded-lg border-slate-300">
                <input id="attendance-geo-lon" type="number" step="any" min="-180" max="180" placeholder="Longitude" class="rounded-lg border-slate-300">
                <input id="attendance-geo-radius" type="number" min="20" max="2000" placeholder="Radius (metres)" class="rounded-lg border-slate-300">
            </div>
        </details>
        <div class="max-h-56 overflow-y-auto grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($students as $student)
                <label class="flex items-center gap-2 text-sm font-normal">
                    <input type="checkbox" class="attendance-roster-student" value="{{ $student->id }}">
                    <span>{{ $student->user?->name ?? $student->student_code }} <span class="text-slate-400">#{{ $student->id }}</span></span>
                </label>
            @endforeach
        </div>
        <div class="flex flex-wrap gap-2">
            <button id="attendance-save-roster" type="button" class="rounded-lg bg-slate-800 text-white px-4 py-2 font-semibold">Save roster</button>
            <button id="attendance-create-qr" type="button" class="rounded-lg bg-indigo-600 text-white px-4 py-2 font-semibold">Create QR session</button>
            <button id="attendance-revoke-qr" type="button" disabled class="rounded-lg border border-rose-300 text-rose-700 px-4 py-2 font-semibold disabled:opacity-40">Revoke QR session</button>
        </div>
        <div id="attendance-qr-status" role="status" aria-live="polite" class="text-sm text-slate-600">Choose a batch to manage its roster.</div>
        <div id="attendance-qr-image" class="min-h-2"></div>
    </section>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" crossorigin="anonymous"></script>
    <script>
    (() => {
        const batch = document.getElementById('attendance-roster-batch');
        const status = document.getElementById('attendance-qr-status');
        const qrTarget = document.getElementById('attendance-qr-image');
        const saveRoster = document.getElementById('attendance-save-roster');
        const createQr = document.getElementById('attendance-create-qr');
        const revokeQr = document.getElementById('attendance-revoke-qr');
        const csrf = document.querySelector('input[name="_token"]')?.value || '';
        let activeSession = null;
        const request = async (url, method, payload = {}) => {
            const response = await fetch(url, {
                method, credentials: 'same-origin',
                headers: {'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':csrf},
                body: JSON.stringify(payload)
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(data.message || 'Request failed (' + response.status + ').');
            return data;
        };
        const selectedStudents = () => Array.from(document.querySelectorAll('.attendance-roster-student:checked')).map(el => Number(el.value));
        saveRoster.addEventListener('click', async () => {
            if (!batch.value) { status.textContent = 'Select a batch first.'; return; }
            saveRoster.disabled = true;
            try {
                const data = await request('/admin/attendance/batches/' + batch.value + '/roster', 'PUT', {student_ids:selectedStudents()});
                status.textContent = data.assigned + ' student(s) assigned to the batch roster.';
            } catch (error) { status.textContent = error.message; }
            finally { saveRoster.disabled = false; }
        });
        createQr.addEventListener('click', async () => {
            if (!batch.value) { status.textContent = 'Select a batch first.'; return; }
            createQr.disabled = true;
            qrTarget.replaceChildren();
            try {
                const payload = {expires_in_minutes:Number(document.getElementById('attendance-qr-expiry').value || 5)};
                const lat = document.getElementById('attendance-geo-lat').value;
                const lon = document.getElementById('attendance-geo-lon').value;
                const radius = document.getElementById('attendance-geo-radius').value;
                if (lat !== '' || lon !== '' || radius !== '') {
                    payload.latitude = Number(lat); payload.longitude = Number(lon); payload.radius_meters = Number(radius);
                }
                const data = await request('/admin/attendance/batches/' + batch.value + '/qr', 'POST', payload);
                activeSession = data.session_id;
                const scanUrl = data.checkin_url + '?token=' + encodeURIComponent(data.token);
                if (window.QRCode) new QRCode(qrTarget, {text:scanUrl,width:220,height:220});
                const token = document.createElement('code');
                token.textContent = data.token;
                token.className = 'block break-all mt-3 text-xs';
                qrTarget.appendChild(token);
                status.textContent = 'QR session expires at ' + data.expires_at + '. Share only with students in this batch.';
                revokeQr.disabled = false;
            } catch (error) { status.textContent = error.message; }
            finally { createQr.disabled = false; }
        });
        revokeQr.addEventListener('click', async () => {
            if (!activeSession) return;
            revokeQr.disabled = true;
            try {
                await request('/admin/attendance/qr-sessions/' + activeSession, 'DELETE');
                status.textContent = 'QR session revoked.';
                activeSession = null;
                qrTarget.replaceChildren();
            } catch (error) { status.textContent = error.message; revokeQr.disabled = false; }
        });
    })();
    </script>

@endsection
