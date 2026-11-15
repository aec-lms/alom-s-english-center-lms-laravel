@extends('layouts.admin')
@section('title', 'Reports & Analytics — AEC LMS')
@section('content')
<div class="space-y-6 max-w-5xl">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-extrabold text-slate-900">Reports & System Analytics</h1>
        <a href="{{ route('plugins.reports.audit.export') }}" class="px-4 py-2 rounded-lg bg-slate-900 text-white text-xs font-semibold">Export Audit CSV</a>
    </div>
    <div class="grid sm:grid-cols-3 gap-4">
        @foreach ($metrics as $label => $value)
            <div class="bg-white rounded-2xl border border-slate-200 p-5">
                <div class="text-xs uppercase text-slate-500">{{ str_replace('_', ' ', $label) }}</div>
                <div class="text-2xl font-extrabold mt-1">{{ $value }}</div>
            </div>
        @endforeach
    </div>
</div>
@endsection
