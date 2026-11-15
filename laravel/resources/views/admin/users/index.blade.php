@extends('layouts.admin')
@section('title', 'Users & Roles — AEC LMS')
@section('content')
<div class="space-y-6 max-w-5xl">
    <h1 class="text-2xl font-extrabold text-slate-900">Users & Roles</h1>

    <form method="POST" action="{{ route('admin.users.store') }}" class="bg-white rounded-2xl border border-slate-200 p-5 grid sm:grid-cols-5 gap-3 text-xs">
        @csrf
        <input name="name" required placeholder="Full Name" class="rounded-lg border border-slate-300 px-3 py-2">
        <input name="email" type="email" required placeholder="Email" class="rounded-lg border border-slate-300 px-3 py-2">
        <input name="password" type="password" required minlength="12" placeholder="Password (12+ chars)" class="rounded-lg border border-slate-300 px-3 py-2">
        <select name="role_id" required class="rounded-lg border border-slate-300 px-3 py-2">
            @foreach ($roles as $role)
                <option value="{{ $role->id }}">{{ $role->name }}</option>
            @endforeach
        </select>
        <button class="rounded-lg bg-sky-600 text-white font-bold px-4 py-2">Create User</button>
    </form>

    <div class="bg-white rounded-2xl border border-slate-200 p-5">
        <table class="w-full text-xs text-left">
            <thead class="text-slate-500 border-b"><tr><th class="py-2">Name</th><th>Email</th><th>Roles</th><th>Status</th></tr></thead>
            <tbody class="divide-y">
                @foreach ($users as $user)
                    <tr>
                        <td class="py-2 font-semibold">{{ $user->name }}</td>
                        <td>{{ $user->email }}</td>
                        <td>{{ $user->roles->pluck('name')->implode(', ') }}</td>
                        <td>{{ $user->is_active ? 'Active' : 'Inactive' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection
