<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} — AEC LMS</title>
    <style>
        body{font-family:system-ui,sans-serif;background:#f8fafc;color:#0f172a;margin:0;padding:24px}
        main{max-width:960px;margin:0 auto;background:white;border:1px solid #e2e8f0;border-radius:16px;padding:24px}
        h1{font-size:1.5rem;margin:0 0 16px}table{width:100%;border-collapse:collapse;font-size:.9rem}
        th,td{text-align:left;padding:12px;border-bottom:1px solid #e2e8f0}th{color:#64748b}
        .status{text-transform:capitalize;font-weight:700}.muted{color:#64748b}
    </style>
</head>
<body>
<main>
    <h1>{{ $title }}</h1>
    <p class="muted">Attendance records linked to your authorized student profile.</p>
    <div style="overflow-x:auto">
        <table>
            <thead><tr><th>Date</th><th>Batch</th><th>Status</th><th>Remarks</th></tr></thead>
            <tbody>
            @forelse ($records as $record)
                <tr>
                    <td>{{ $record->attendance_date?->toDateString() }}</td>
                    <td>{{ $record->batchIdentity?->display_name ?? ('Batch #' . $record->batch_id) }}</td>
                    <td class="status">{{ $record->status }}</td>
                    <td>{{ $record->remarks ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="muted">No attendance records yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div style="margin-top:16px">{{ $records->links() }}</div>
</main>
</body>
</html>
