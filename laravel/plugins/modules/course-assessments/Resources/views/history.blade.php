@extends('layouts.app')
@section('title','Assessment History')
@section('content')
@include('public.partials.navbar')
<main class="mx-auto max-w-5xl space-y-5 px-4 py-8"><h1 class="text-3xl font-extrabold">Assessment History</h1>@if(session('status'))<div class="rounded-xl bg-emerald-50 p-3 text-emerald-800">{{ session('status') }}</div>@endif
<div class="overflow-x-auto rounded-2xl border bg-white p-4"><table class="w-full text-sm"><thead><tr class="border-b text-left"><th class="py-3">Assessment</th><th>Attempt</th><th>Submitted</th><th>Status</th><th>Score</th></tr></thead><tbody>@forelse($attempts as $attempt)<tr class="border-b"><td class="py-3">{{ \Plugins\Modules\CourseAssessments\Models\AssessmentSet::find($attempt->assessment_set_id)?->title ?? 'Assessment' }}</td><td>{{ $attempt->attempt_number }}</td><td>{{ $attempt->submitted_at?->format('d M Y H:i') ?? 'In progress' }}</td><td>{{ str_replace('_',' ',$attempt->status) }}</td><td>{{ $attempt->final_score ?? $attempt->auto_score }} / {{ $attempt->max_score }}</td></tr>@empty<tr><td colspan="5" class="py-6 text-center text-slate-500">No attempts yet.</td></tr>@endforelse</tbody></table>{{ $attempts->links() }}</div></main>
@endsection
