@extends('layouts.app')
@section('content')
<div class="container py-4">
    <h1>Available Exams</h1>
    @if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
    @forelse($exams as $exam)
        @php($paid = ($exam->learn_earn_type ?? 'free') === 'paid')
        @php($hasAccess = in_array((int)$exam->id, $accessibleExamIds ?? [], true))
        <div class="card mb-3"><div class="card-body">
            <h4>{{ $exam->title }}</h4>
            <p>{{ $paid ? 'Paid' : 'Free' }} exam · {{ $exam->duration_minutes }} minutes · Pass marks {{ $exam->pass_marks }} @if($paid) · ৳{{ number_format((float)$exam->access_price,2) }} @endif</p>
            @if(!$paid || $hasAccess)
                <a class="btn btn-primary" href="{{ url('/learn-and-earn/exams/' . $exam->id) }}">Open Exam</a>
            @elseif($canPurchase)
                <form method="POST" action="{{ url('/learn-and-earn/exams/' . $exam->id . '/checkout') }}" class="d-flex flex-wrap gap-2">
                    @csrf
                    <input class="form-control" style="max-width:260px" name="payer_contact" required maxlength="120" placeholder="Mobile number for payment">
                    <button class="btn btn-success">Pay ৳{{ number_format((float)$exam->access_price,2) }} &amp; Unlock</button>
                </form>
            @else
                <span class="text-muted">Online checkout is not configured. Contact the administrator.</span>
            @endif
        </div></div>
    @empty
        <p>No published exams available.</p>
    @endforelse
    {{ $exams->links() }}
</div>
@endsection
