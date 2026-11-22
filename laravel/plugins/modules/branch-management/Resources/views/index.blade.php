@extends('layouts.admin')
@section('title', 'Branch Management — AEC LMS')
@section('content')
<div class="space-y-6 max-w-6xl">
    <div>
        <h1 class="text-2xl font-extrabold text-slate-900">Branch Management</h1>
        <p class="text-xs text-slate-500">Add, edit, activate/deactivate, or safely delete campus branches without harming student or LMS records.</p>
    </div>

    @unless (auth()->user()?->hasRole('branch_manager'))
    <form method="POST" action="{{ route('plugins.branches.store') }}" class="bg-white rounded-2xl border border-slate-200 p-5 grid sm:grid-cols-6 gap-3 text-xs">
        @csrf
        <input name="code" required placeholder="Code (DHK-01)" class="rounded-lg border border-slate-300 px-3 py-2 font-mono">
        <input name="name" required placeholder="Branch Name" class="rounded-lg border border-slate-300 px-3 py-2">
        <input name="city" placeholder="City" class="rounded-lg border border-slate-300 px-3 py-2">
        <input name="phone" placeholder="Phone" class="rounded-lg border border-slate-300 px-3 py-2">
        <input name="address" placeholder="Address" class="rounded-lg border border-slate-300 px-3 py-2">
        <button class="rounded-lg bg-[#0057FF] hover:bg-blue-700 text-white font-bold px-4 py-2">Add Branch</button>
    </form>
    @endunless

    <div class="space-y-3">
        @forelse ($branches as $branch)
            <div class="bg-white rounded-2xl border border-slate-200 p-4 text-xs space-y-3">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center gap-2">
                        <span class="font-mono font-bold text-blue-700 bg-blue-50 px-2 py-0.5 rounded">{{ $branch->code }}</span>
                        <strong class="text-sm text-slate-900">{{ $branch->name }}</strong>
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold {{ $branch->is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-600' }}">
                            {{ $branch->is_active ? 'Active' : 'Inactive' }}
                        </span>
                    </div>
                    @unless (auth()->user()?->hasRole('branch_manager'))
                    <div class="flex items-center gap-2">
                        <form method="POST" action="{{ route('plugins.branches.toggle', $branch) }}">
                            @csrf
                            <button class="px-3 py-1.5 rounded-lg border {{ $branch->is_active ? 'border-amber-300 text-amber-800 hover:bg-amber-50' : 'border-emerald-300 text-emerald-800 hover:bg-emerald-50' }} font-semibold">
                                {{ $branch->is_active ? 'Deactivate' : 'Activate' }}
                            </button>
                        </form>
                        <form method="POST" action="{{ route('plugins.branches.destroy', $branch) }}" onsubmit="return confirm('Delete branch {{ $branch->name }}? Associated users will remain intact.')">
                            @csrf @method('DELETE')
                            <button class="px-3 py-1.5 rounded-lg border border-rose-200 text-rose-700 font-semibold hover:bg-rose-50">Delete</button>
                        </form>
                    </div>
                    @endunless
                </div>

                <form method="POST" action="{{ route('plugins.branches.update', $branch) }}" class="grid sm:grid-cols-6 gap-2 pt-2 border-t border-slate-100">
                    @csrf @method('PUT')
                    <input name="code" value="{{ $branch->code }}" required class="rounded-lg border border-slate-300 px-2.5 py-1.5 font-mono">
                    <input name="name" value="{{ $branch->name }}" required class="rounded-lg border border-slate-300 px-2.5 py-1.5">
                    <input name="city" value="{{ $branch->city }}" placeholder="City" class="rounded-lg border border-slate-300 px-2.5 py-1.5">
                    <input name="phone" value="{{ $branch->phone }}" placeholder="Phone" class="rounded-lg border border-slate-300 px-2.5 py-1.5">
                    <input name="address" value="{{ $branch->address }}" placeholder="Address" class="rounded-lg border border-slate-300 px-2.5 py-1.5">
                    <button class="rounded-lg bg-slate-900 hover:bg-slate-800 text-white font-semibold px-3 py-1.5">Save Edit</button>
                </form>
            </div>
        @empty
            <div class="bg-white rounded-2xl border border-slate-200 p-6 text-xs text-slate-400">No branches configured yet.</div>
        @endforelse
    </div>
</div>
@endsection
