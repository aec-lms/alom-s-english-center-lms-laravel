@extends('layouts.admin')

@section('content')
<div class="container-fluid py-3">
    @if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
    <div class="d-flex justify-content-between flex-wrap gap-2 mb-3"><div><h1 class="h3">{{ $paper->title }}</h1><p class="text-muted mb-0">{{ strtoupper(str_replace('_',' ',$paper->exam_category)) }} · {{ $paper->duration_minutes }} minutes · {{ $paper->total_marks }} marks · Snapshot v{{ $paper->snapshot_version }}</p></div><div class="d-flex gap-2"><a class="btn btn-outline-primary" href="{{ route('plugins.question-bank.papers.print',$paper) }}" target="_blank">Print question paper / PDF</a><a class="btn btn-outline-secondary" href="{{ route('plugins.question-bank.papers.answer-key',$paper) }}" target="_blank">Print answer key</a><a class="btn btn-light" href="{{ route('plugins.question-bank.index') }}">Back</a></div></div>
    @if($paper->instructions)<div class="alert alert-info">{{ $paper->instructions }}</div>@endif
    @foreach($paper->items as $item)<div class="card mb-3"><div class="card-body"><div class="d-flex justify-content-between"><strong>Question {{ $item->position }}</strong><span>{{ $item->marks }} marks @if($item->negative_marks > 0) · Negative {{ $item->negative_marks }} @endif</span></div><p class="mt-3 mb-2">{{ $item->question_snapshot['prompt'] }}</p>@if($item->question_snapshot['question_type']==='mcq')<ol type="A">@foreach($item->question_snapshot['options'] ?? [] as $option)<li>{{ $option }}</li>@endforeach</ol>@else<span class="badge bg-secondary">Written</span>@endif</div></div>@endforeach
</div>
@endsection
