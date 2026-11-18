@extends('layouts.app')

@section('title', 'Support Center')

@section('content')
<div class="page-header"><div><h1>Support Center</h1><p class="muted">শিক্ষার্থী ও অভিভাবকদের সহায়তার অনুরোধ পরিচালনা করুন।</p></div></div>
<div class="panel">
    <form method="GET" class="form-grid" style="grid-template-columns:1fr auto;align-items:end">
        <label>অবস্থা<select name="status"><option value="">সব</option>@foreach(['open','in_progress','closed'] as $status)<option value="{{ $status }}" @selected(request('status') === $status)>{{ $status }}</option>@endforeach</select></label>
        <button class="btn secondary" type="submit">ফিল্টার</button>
    </form>
    <div class="table-wrap" style="margin-top:16px"><table>
        <thead><tr><th>টিকিট</th><th>ব্যবহারকারী</th><th>Branch ID</th><th>অবস্থা</th><th>শেষ আপডেট</th><th></th></tr></thead>
        <tbody>
        @forelse($tickets as $ticket)
            <tr><td>#{{ $ticket->id }} · {{ $ticket->subject }}</td><td>{{ $ticket->user?->name }}<br><small class="muted">{{ $ticket->user?->email }}</small></td><td>{{ $ticket->branch_id ?? '—' }}</td><td>{{ $ticket->status }}</td><td>{{ $ticket->last_message_at?->format('d M Y, h:i A') }}</td><td><a class="btn secondary" href="{{ route('plugins.support.admin.show', $ticket->id) }}">খুলুন</a></td></tr>
        @empty
            <tr><td colspan="6" class="muted">কোনো টিকিট পাওয়া যায়নি।</td></tr>
        @endforelse
        </tbody>
    </table></div>
    {{ $tickets->links() }}
</div>
@endsection
