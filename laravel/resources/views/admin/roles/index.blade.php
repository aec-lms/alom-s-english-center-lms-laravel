@extends('layouts.admin')
@section('title', 'Roles & Permissions — AEC LMS')
@section('content')
<div class="mx-auto max-w-7xl space-y-6">
    <div>
        <h1 class="text-2xl font-extrabold text-slate-900">Roles &amp; Permissions</h1>
        <p class="mt-1 text-sm text-slate-500">Create custom staff roles and control access to LMS modules. Permission checks remain enforced on the server.</p>
    </div>

    <section class="rounded-2xl border border-slate-200 bg-white p-5">
        <h2 class="font-bold text-slate-900">Create Custom Role</h2>
        <form method="POST" action="{{ route('admin.roles.store') }}" class="mt-4 grid gap-3 md:grid-cols-4">
            @csrf
            <label class="text-xs font-semibold text-slate-600">Role name
                <input name="name" required maxlength="120" placeholder="Academic Coordinator" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            </label>
            <label class="text-xs font-semibold text-slate-600">Role key
                <input name="slug" required maxlength="64" pattern="[a-z][a-z0-9_-]*" placeholder="academic_coordinator" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            </label>
            <label class="text-xs font-semibold text-slate-600 md:col-span-2">Description
                <input name="description" maxlength="500" placeholder="What this role is responsible for" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            </label>
            <div class="md:col-span-4"><button class="rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-bold text-white">Create Role</button></div>
        </form>
    </section>

    <div class="space-y-5">
        @foreach ($roles as $role)
            <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-5 py-4">
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="font-extrabold text-slate-900">{{ $role->name }}</h2>
                            <span class="rounded-full bg-slate-100 px-2.5 py-1 text-[10px] font-bold text-slate-600">{{ $role->slug }}</span>
                            @if ($role->slug === 'admin')
                                <span class="rounded-full bg-amber-50 px-2.5 py-1 text-[10px] font-bold text-amber-700">Protected Core Role</span>
                            @elseif ($role->is_system)
                                <span class="rounded-full bg-blue-50 px-2.5 py-1 text-[10px] font-bold text-blue-700">System Role</span>
                            @else
                                <span class="rounded-full bg-emerald-50 px-2.5 py-1 text-[10px] font-bold text-emerald-700">Custom Role</span>
                            @endif
                        </div>
                        @if ($role->description)
                            <p class="mt-1 text-xs text-slate-500">{{ $role->description }}</p>
                        @endif
                    </div>
                    <span class="text-xs text-slate-500">{{ $role->permissions->count() }} assigned permissions</span>
                </div>

                @if ($role->slug === 'admin')
                    <div class="px-5 py-4 text-sm text-slate-600">Administrator access is protected and cannot be reduced from this screen.</div>
                @else
                    <form method="POST" action="{{ route('admin.roles.permissions.update', $role) }}" class="p-5">
                        @csrf
                        @method('PUT')
                        <div class="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
                            @foreach ($permissions as $module => $modulePermissions)
                                <fieldset class="rounded-xl border border-slate-200 p-4">
                                    <legend class="px-2 text-xs font-extrabold uppercase tracking-wide text-slate-600">{{ str_replace(['-', '_'], ' ', $module) }}</legend>
                                    <div class="space-y-2">
                                        @foreach ($modulePermissions as $permission)
                                            <label class="flex items-start gap-2 text-sm text-slate-700">
                                                <input type="checkbox" name="permissions[]" value="{{ $permission->id }}" @checked($role->permissions->contains('id', $permission->id)) class="mt-0.5 rounded border-slate-300">
                                                <span>
                                                    <span class="block font-medium">{{ $permission->name }}</span>
                                                    <span class="block text-[10px] text-slate-400">{{ $permission->slug }}</span>
                                                </span>
                                            </label>
                                        @endforeach
                                    </div>
                                </fieldset>
                            @endforeach
                        </div>
                        <div class="mt-5 flex flex-wrap items-center justify-between gap-3">
                            <p class="text-xs text-slate-500">Changes apply to all users assigned this role. Use least-privilege access.</p>
                            <button class="rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-bold text-white">Save {{ $role->name }} Permissions</button>
                        </div>
                    </form>
                @endif
            </section>
        @endforeach
    </div>
</div>
@endsection
