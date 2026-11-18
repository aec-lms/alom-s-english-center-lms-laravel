@extends('layouts.app')
@section('title','My Assessments')
@section('content')
@include('public.partials.navbar')
<main class="mx-auto max-w-5xl space-y-5 px-4 py-8"><h1 class="text-3xl font-extrabold">My Assessments</h1><p class="text-slate-600">Course quizzes, model tests and written assessments</p>
@if(session('status'))<div class="rounded-xl bg-emerald-50 p-3 text-emerald-800">{{ session('status') }}</div>@endif
<div class="grid gap-4 md:grid-cols-2">@forelse($sets as $set)<article class="rounded-2xl border bg-white p-5"><h2 class="text-xl font-bold">{{ $set->title }}</h2><p class="mt-2 text-sm text-slate-600">{{ $set->description }}</p><p class="mt-3 text-xs text-slate-500">{{ $set->questions_count }} questions · {{ $set->time_limit_minutes ? $set->time_limit_minutes.' minutes' : 'No time limit' }} · {{ $set->max_attempts ? $set->max_attempts.' attempts max' : 'Unlimited attempts' }}</p><form method="POST" action="{{ route('plugins.course-assessments.student.start',$set) }}" class="mt-4">@csrf<button class="rounded-lg bg-slate-900 px-4 py-2 font-semibold text-white">Start / Resume</button></form></article>@empty<p class="text-slate-500">No assessments are currently available.</p>@endforelse</div>{{ $sets->links() }}</main>
@endsection
