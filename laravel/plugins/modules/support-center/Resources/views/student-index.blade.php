@extends('layouts.app')

@section('title', 'আমার সাপোর্ট টিকিট')

@section('content')
<div class="page-header">
    <div><h1>আমার সাপোর্ট টিকিট</h1><p class="muted">প্রশ্ন বা সমস্যার জন্য সাপোর্ট টিমের সঙ্গে যোগাযোগ করুন।</p></div>
</div>
<div class="panel">
    <h2>নতুন টিকিট তৈরি করুন</h2>
    <form method="POST" action="{{ route('plugins.support.student.store') }}">
        @csrf
        <label>বিষয়<input name="subject" maxlength="160" minlength="5" required value="{{ old('subject') }}"></label>
        <label>বিস্তারিত<textarea name="message" rows="4" maxlength="5000" minlength="5" required>{{ old('message') }}</textarea></label>
        <button class="btn primary" type="submit">টিকিট পাঠান</button>
    </form>
</div>
<div class="panel" style="margin-top:16px">
    <h2>আগের টিকিট</h2>
    <div class="table-wrap"><table>
        <thead><tr><th>বিষয়</th><th>অবস্থা</th><th>সর্বশেষ আপডেট</th><th></th></tr></thead>
        <tbody>
        @forelse($tickets as $ticket)
            <tr>
                <td>{{ $ticket->subject }}</td>
                <td>{{ $ticket->status }}</td>
                <td>{{ $ticket->last_message_at?->format('d M Y, h:i A') }}</td>
                <td><a class="btn secondary" href="{{ route('plugins.support.student.show', $ticket->id) }}">দেখুন</a></td>
            </tr>
        @empty
            <tr><td colspan="4" class="muted">এখনো কোনো টিকিট নেই।</td></tr>
        @endforelse
        </tbody>
    </table></div>
    {{ $tickets->links() }}
</div>
@endsection
