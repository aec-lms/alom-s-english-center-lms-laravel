@extends('layouts.admin')
@section('title', 'Library Management — AEC LMS')
@section('content')
<div class="space-y-6 max-w-5xl">
    <div>
        <h1 class="text-2xl font-extrabold text-slate-900">Library &amp; Resource Inventory</h1>
        <p class="text-xs text-slate-500 mt-0.5">Manage physical English &amp; IELTS books, member borrowing, and overdue fines.</p>
    </div>

    <div class="grid md:grid-cols-2 gap-6">
        <div class="bg-white rounded-2xl border border-slate-200 p-5 space-y-4">
            <h2 class="text-sm font-bold text-slate-900">Add Book to Catalog</h2>
            <form method="POST" action="{{ url('/admin/library/books') }}" class="grid gap-3 text-xs">
                @csrf
                <input name="isbn" required placeholder="ISBN / Catalog Code *" class="rounded-lg border border-slate-300 px-3 py-2 font-mono">
                <input name="title" required placeholder="Book Title *" class="rounded-lg border border-slate-300 px-3 py-2">
                <input name="author" required placeholder="Author *" class="rounded-lg border border-slate-300 px-3 py-2">
                <div class="grid grid-cols-2 gap-3">
                    <input name="category" required value="IELTS & Academic" class="rounded-lg border border-slate-300 px-3 py-2">
                    <input name="total_copies" type="number" min="1" value="5" required class="rounded-lg border border-slate-300 px-3 py-2">
                </div>
                <button type="submit" class="px-4 py-2 rounded-lg bg-[#0284C7] text-white font-bold">Add Book</button>
            </form>
        </div>

        <div class="bg-white rounded-2xl border border-slate-200 p-5 space-y-4">
            <h2 class="text-sm font-bold text-slate-900">Issue Book Loan</h2>
            <form method="POST" action="{{ url('/admin/library/loans') }}" class="grid gap-3 text-xs">
                @csrf
                <select name="library_book_id" required class="rounded-lg border border-slate-300 px-3 py-2 bg-white">
                    <option value="">Select Book...</option>
                    @foreach ($books as $book)
                        <option value="{{ $book->id }}">{{ $book->title }} ({{ $book->available_copies }}/{{ $book->total_copies }} avail)</option>
                    @endforeach
                </select>
                <input name="borrower_name" required placeholder="Borrower Full Name *" class="rounded-lg border border-slate-300 px-3 py-2">
                <input name="borrower_code" required placeholder="Student / Staff ID *" class="rounded-lg border border-slate-300 px-3 py-2 font-mono">
                <input name="due_at" type="date" required value="{{ now()->addDays(14)->toDateString() }}" class="rounded-lg border border-slate-300 px-3 py-2">
                <button type="submit" class="px-4 py-2 rounded-lg bg-emerald-600 text-white font-bold">Issue Loan</button>
            </form>
        </div>
    </div>

    <div class="bg-white rounded-2xl border border-slate-200 p-5 space-y-3 text-xs">
        <h2 class="text-sm font-bold text-slate-900">Recent Loans ({{ $loans->count() }})</h2>
        @forelse ($loans as $loan)
            <div class="border border-slate-200 rounded-xl p-4 flex items-center justify-between gap-3">
                <div>
                    <div class="font-bold text-sm text-slate-900">{{ $loan->book?->title ?? 'Book #' . $loan->library_book_id }}</div>
                    <div class="text-slate-500">{{ $loan->borrower_name }} ({{ $loan->borrower_code }}) &middot; Due {{ $loan->due_at?->toDateString() }} &middot; Fine: ৳{{ $loan->fine_amount }} &middot; <strong class="uppercase">{{ $loan->status }}</strong></div>
                </div>
                @if ($loan->status === 'borrowed')
                    <form method="POST" action="{{ url('/admin/library/loans/' . $loan->id . '/return') }}">
                        @csrf
                        <button type="submit" class="px-3 py-1.5 rounded-lg bg-slate-900 text-white font-bold">Mark Returned</button>
                    </form>
                @endif
            </div>
        @empty
            <p class="text-slate-400">No active or past book loans.</p>
        @endforelse
    </div>
</div>
@endsection
