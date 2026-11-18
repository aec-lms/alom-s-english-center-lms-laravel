@extends('layouts.admin')

@section('title', 'Course Views & Purchase Display')

@section('content')
<div class="max-w-4xl mx-auto space-y-6">
    <div>
        <h1 class="text-2xl font-extrabold text-slate-900">Course Views &amp; Purchase Display</h1>
        <p class="mt-1 text-sm text-slate-500">Control which course popularity metrics visitors can see. Analytics continue collecting while public display is disabled.</p>
    </div>

    @if (session('status'))
        <div role="status" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</div>
    @endif

    <form method="POST" action="{{ route('admin.course-social-proof.update') }}" class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm space-y-5">
        @csrf
        <label class="flex items-start justify-between gap-4 rounded-xl bg-slate-50 p-4">
            <span><strong class="block text-sm text-slate-900">Enable course social proof</strong><span class="mt-1 block text-xs text-slate-500">Master switch for public views and purchase counts.</span></span>
            <input type="checkbox" name="enabled" value="1" @checked($settings['enabled']) class="mt-1 h-5 w-5 rounded border-slate-300 text-sky-600">
        </label>

        <div class="grid gap-3 sm:grid-cols-2">
            @foreach ([
                'show_views' => ['Show view count', 'Display unique daily visitor views.'],
                'show_purchases' => ['Show purchased count', 'Display confirmed active/completed enrollments only.'],
                'show_on_cards' => ['Show on course cards', 'Display metrics in the public course catalog.'],
                'show_on_details' => ['Show on course details', 'Display metrics on individual course pages.'],
            ] as $key => $copy)
                <label class="flex items-start gap-3 rounded-xl border border-slate-200 p-4">
                    <input type="checkbox" name="{{ $key }}" value="1" @checked($settings[$key]) class="mt-0.5 h-4 w-4 rounded border-slate-300 text-sky-600">
                    <span><strong class="block text-sm text-slate-900">{{ $copy[0] }}</strong><span class="mt-1 block text-xs text-slate-500">{{ $copy[1] }}</span></span>
                </label>
            @endforeach
        </div>

        <div class="flex justify-end">
            <button type="submit" class="rounded-xl bg-sky-600 px-5 py-3 text-sm font-bold text-white shadow-sm hover:bg-sky-700 focus:outline-none focus:ring-2 focus:ring-sky-500 focus:ring-offset-2">Save display settings</button>
        </div>
    </form>

    <div class="rounded-xl border border-sky-100 bg-sky-50 p-4 text-sm text-sky-900">
        <strong>Counting rules</strong>
        <ul class="mt-2 list-disc space-y-1 pl-5 text-xs leading-relaxed">
            <li>Views are counted once per visitor/session per course per day; visitor identifiers are stored as keyed hashes, not raw IP addresses.</li>
            <li>Paid enrollments count only after the linked invoice is fully paid and the enrollment is active or completed.</li>
            <li>Pending requests, unpaid invoices and failed payments are excluded.</li>
        </ul>
    </div>
</div>
@endsection
