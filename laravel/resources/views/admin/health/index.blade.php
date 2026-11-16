@extends('layouts.admin')
@section('title', 'System Health — AEC LMS')
@section('content')
<div class="max-w-6xl space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div><h1 class="text-2xl font-extrabold text-slate-900">System Health &amp; Recovery</h1><p class="text-sm text-slate-500">Runtime monitoring, safe recovery attempts and operational warnings.</p></div>
        <form method="POST" action="{{ route('admin.health.check') }}">@csrf<button class="rounded-lg bg-sky-600 px-4 py-2 text-sm font-bold text-white">Run Health Check &amp; Safe Recovery</button></form>
    </div>
    <section class="rounded-2xl border border-slate-200 bg-white p-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div><div class="text-xs font-bold uppercase tracking-wide text-slate-500">Overall status</div><div class="mt-1 text-2xl font-extrabold {{ $report['status'] === 'healthy' ? 'text-emerald-700' : 'text-amber-700' }}">{{ strtoupper($report['status']) }}</div></div>
            <div class="text-right text-xs text-slate-500">Last checked<br><span class="font-semibold text-slate-800">{{ $report['checked_at'] }}</span></div>
        </div>
        <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($report['checks'] as $name => $check)
            <div class="rounded-xl border {{ in_array($check['status'], ['ok', 'recovered'], true) ? 'border-emerald-200 bg-emerald-50' : 'border-rose-200 bg-rose-50' }} p-4">
                <div class="flex items-center justify-between gap-2"><h2 class="font-bold text-slate-900">{{ str($name)->replace('_', ' ')->title() }}</h2><span class="text-[10px] font-extrabold uppercase">{{ $check['status'] }}</span></div>
                <p class="mt-2 text-xs leading-5 text-slate-600">{{ $check['message'] }}</p>
            </div>
            @endforeach
        </div>
    </section>
    <section class="rounded-2xl border border-amber-200 bg-amber-50 p-5">
        <h2 class="font-bold text-amber-900">Recovery safety limits</h2>
        <ul class="mt-2 list-disc space-y-1 pl-5 text-xs text-amber-900">
            <li>Safe recovery may recreate missing Laravel runtime directories and reconnect the database.</li>
            <li>The system never changes file permissions, deletes user data, drops tables, runs migrations or overwrites releases automatically.</li>
            <li>Server restarts, PHP-FPM restarts, load balancing and infrastructure failover require hosting-provider or VPS access.</li>
            <li>For full backup and disaster recovery, use Backup &amp; Restore and rehearse on a separate staging database first.</li>
        </ul>
    </section>
    @if (!empty($report['recoveries']))
    <section class="rounded-2xl border border-slate-200 bg-white p-5"><h2 class="font-bold text-slate-900">Recovery actions</h2><ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-slate-600">
        @foreach ($report['recoveries'] as $name => $message)<li><strong>{{ str($name)->replace('_', ' ')->title() }}:</strong> {{ $message }}</li>@endforeach
    </ul></section>
    @endif
</div>
@endsection
