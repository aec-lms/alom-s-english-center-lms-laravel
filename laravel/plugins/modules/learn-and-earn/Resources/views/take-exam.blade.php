@extends('layouts.app')
@section('content')
<div class="container py-4"><h1>{{ $exam->title }}</h1><p>Duration: {{ $exam->duration_minutes }} minutes | Pass marks: {{ $exam->pass_marks }}</p>
<form method="post" action="{{ url('/learn-and-earn/exams/'.$exam->getKey().'/attempt') }}">@csrf
@foreach($exam->questions as $question)<div class="card mb-3"><div class="card-body"><h5>{{ $loop->iteration }}. {{ $question->prompt }}</h5>@foreach($question->options as $i=>$option)<label class="d-block py-1"><input type="radio" name="answers[{{ $question->id }}]" value="{{ $i }}"> {{ $option }}</label>@endforeach<small>Positive: {{ $question->marks }} | Negative: {{ $question->negative_marks }}</small></div></div>@endforeach
<button class="btn btn-success">Submit Exam</button></form></div>
@endsection
