@extends('layouts.admin')
@section('title', 'CMS Contributors — Alom\'s English Center')
@section('content')
<div class="mx-auto max-w-6xl space-y-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-extrabold text-slate-900">CMS Contributor Management</h1>
            <p class="mt-1 text-sm text-slate-500">Grant and revoke CMS-specific roles without changing a user's other LMS roles.</p>
        </div>
        <a href="{{ route('plugins.cms.editorial') }}" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-xs font-bold text-slate-700">Editorial Workspace</a>
    </div>

    <div class="rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-900">
        CMS access is additive: updating these checkboxes changes only CMS roles. Student, teacher, branch and other LMS roles are preserved. Administrator accounts are managed separately.
    </div>

    @forelse ($contributors as $contributor)
        <article class="rounded-2xl border border-slate-200 bg-white p-5">
            <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="font-bold text-slate-900">{{ $contributor->name }}</h2>
                    <p class="text-xs text-slate-500">{{ $contributor->email }}</p>
                </div>
                <span class="text-xs text-slate-500">User #{{ $contributor->id }}</span>
            </div>
            <form method="POST" action="{{ route('plugins.cms.contributors.update', $contributor) }}" class="mt-4 space-y-3">
                @csrf @method('PUT')
                @php($assigned = $contributor->roles->pluck('slug')->all())
                <div class="grid gap-3 sm:grid-cols-2">
                    @foreach ([
                        'cms_contributor' => ['Contributor', 'Create and edit own drafts; submit for review.'],
                        'cms_editor' => ['Editor', 'Edit all CMS posts, assign collaborators, review and publish approved posts.'],
                        'cms_reviewer' => ['Reviewer', 'Review assigned or unassigned submissions; cannot approve own content.'],
                        'cms_author_manager' => ['Contributor Manager', 'Manage CMS contributor access and post assignments.'],
                    ] as $slug => $details)
                        <label class="flex items-start gap-3 rounded-xl border border-slate-200 p-3">
                            <input type="checkbox" name="roles[]" value="{{ $slug }}" @checked(in_array($slug, $assigned, true)) class="mt-1 rounded border-slate-300">
                            <span><strong class="block text-sm text-slate-800">{{ $details[0] }}</strong><span class="mt-1 block text-xs text-slate-500">{{ $details[1] }}</span></span>
                        </label>
                    @endforeach
                </div>
                <div class="flex justify-end"><button class="rounded-lg bg-slate-900 px-4 py-2 text-xs font-bold text-white">Save CMS Roles</button></div>
            </form>
        </article>
    @empty
        <div class="rounded-2xl border border-dashed border-slate-300 bg-white p-10 text-center text-sm text-slate-500">
            No users currently have CMS contributor roles. Assign a CMS role through the LMS user management system first, or add eligible users to this page in the next access-management phase.
        </div>
    @endforelse
    <div>{{ $contributors->links() }}</div>
</div>
@endsection
