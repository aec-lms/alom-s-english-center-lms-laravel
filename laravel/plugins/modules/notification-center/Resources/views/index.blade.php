@extends('layouts.admin')
@section('title', 'Notification Center — AEC LMS')
@section('content')
<div class="space-y-6 max-w-5xl">
    <div>
        <h1 class="text-2xl font-extrabold text-slate-900">Notification Center</h1>
        <p class="text-xs text-slate-500 mt-1">Send in-app notifications, SMTP email, or Greenweb SMS. Push delivery is not yet connected.</p>
    </div>

    <form method="POST" action="{{ route('plugins.notifications.dispatch') }}" class="bg-white rounded-2xl border border-slate-200 p-5 space-y-4">
        @csrf
        <h2 class="text-sm font-bold text-slate-900">Dispatch Notification</h2>
        <div class="grid sm:grid-cols-2 gap-3 text-xs">
            <div>
                <label class="block font-semibold text-slate-700 mb-1">Recipient</label>
                <select name="user_id" required class="w-full rounded-lg border border-slate-300 px-3 py-2 bg-white">
                    <option value="">Select user</option>
                    @foreach ($users as $user)
                        <option value="{{ $user->id }}" @selected((string) old('user_id') === (string) $user->id)>
                            {{ $user->name }} · {{ $user->email ?: ($user->phone ?: 'No contact') }}
                        </option>
                    @endforeach
                </select>
                @if ($users->isEmpty())
                    <p class="mt-1 text-rose-600">No users available.</p>
                @endif
            </div>
            <div>
                <label class="block font-semibold text-slate-700 mb-1">Channel</label>
                <select name="channel" required class="w-full rounded-lg border border-slate-300 px-3 py-2 bg-white">
                    <option value="in_app" @selected(old('channel') === 'in_app')>In-app Inbox</option>
                    <option value="email" @selected(old('channel') === 'email')>Email (SMTP)</option>
                    <option value="sms" @selected(old('channel') === 'sms')>SMS (Greenweb)</option>
                    <option value="push" @selected(old('channel') === 'push')>Push (not connected)</option>
                </select>
            </div>
            <div class="sm:col-span-2">
                <label class="block font-semibold text-slate-700 mb-1">Title</label>
                <input name="title" value="{{ old('title') }}" required maxlength="180" class="w-full rounded-lg border border-slate-300 px-3 py-2">
            </div>
            <div class="sm:col-span-2">
                <label class="block font-semibold text-slate-700 mb-1">Message</label>
                <textarea name="message" rows="4" required maxlength="10000" class="w-full rounded-lg border border-slate-300 px-3 py-2">{{ old('message') }}</textarea>
            </div>
        </div>
        <button type="submit" class="px-5 py-2.5 rounded-xl bg-slate-900 text-white font-bold">Send Notification</button>
    </form>

    <div class="bg-white rounded-2xl border border-slate-200 p-5">
        <h2 class="text-sm font-bold text-slate-900 mb-3">Notification History</h2>
        <div class="overflow-x-auto">
            <table class="w-full text-xs text-left">
                <thead class="text-slate-500 border-b"><tr><th class="py-2">Recipient</th><th>Channel</th><th>Title</th><th>Status</th><th>Created</th></tr></thead>
                <tbody class="divide-y">
                    @forelse ($notifications as $n)
                        <tr>
                            <td class="py-2">{{ $n->user?->name ?? 'Deleted user' }}</td>
                            <td class="uppercase">{{ $n->channel }}</td>
                            <td>{{ $n->title }}</td>
                            <td class="uppercase font-semibold">{{ $n->status }}</td>
                            <td>{{ $n->created_at?->toDateTimeString() }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-4 text-slate-400">No notifications recorded yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $notifications->links() }}</div>
    </div>
</div>
@endsection
