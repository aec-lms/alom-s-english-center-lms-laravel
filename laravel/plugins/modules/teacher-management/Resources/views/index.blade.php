@extends('layouts.admin')
@section('title', 'Instructors & Teachers — AEC LMS')
@section('content')
<div class="space-y-6 max-w-6xl">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-extrabold text-slate-900">Our Expert Instructors &amp; Teachers</h1>
            <p class="text-xs text-slate-500 mt-0.5">Add, edit, upload portraits, toggle status, or move instructors to the Admin Recycle Bin on the public Instructors page.</p>
        </div>
        <a href="/instructors" target="_blank" class="px-4 py-2 rounded-lg bg-white border border-slate-300 text-xs font-bold text-slate-700 hover:bg-slate-50">
            Preview Instructors Page &rarr;
        </a>
    </div>

    <div class="bg-white rounded-2xl border border-slate-200 p-5 space-y-4">
        <h2 class="text-sm font-bold text-slate-900">Add New Instructor</h2>
        <form method="POST" action="/admin/teachers" enctype="multipart/form-data" class="grid sm:grid-cols-3 gap-3 text-xs">
            @csrf
            <input name="name" required placeholder="Full Name *" class="rounded-lg border border-slate-300 px-3 py-2">
            <input name="email" type="email" required placeholder="Email Address *" class="rounded-lg border border-slate-300 px-3 py-2">
            <input name="employee_code" required placeholder="Code (e.g. INS-01) *" class="rounded-lg border border-slate-300 px-3 py-2 font-mono">
            <input name="specialization" required placeholder="Subject / Specialization (e.g. IELTS & Spoken) *" class="rounded-lg border border-slate-300 px-3 py-2">
            <input name="credentials" placeholder="Role / Credentials (e.g. CELTA · Band 8.5)" class="rounded-lg border border-slate-300 px-3 py-2">
            <select name="branch_id" class="rounded-lg border border-slate-300 px-3 py-2 bg-white">
                <option value="">Main / All Branches</option>
                @foreach ($branches as $br)
                    <option value="{{ $br->id }}">{{ $br->name }}</option>
                @endforeach
            </select>
            <div>
                <label class="block font-semibold text-slate-600 mb-1">Upload Portrait Photo</label>
                <input type="file" name="photo" accept="image/*" class="w-full rounded-lg border border-slate-300 px-2 py-1.5 bg-slate-50">
            </div>
            <input name="photo_url" placeholder="Or Portrait URL (/assets/images/...)" class="rounded-lg border border-slate-300 px-3 py-2 self-end">
            <input name="password" type="password" required minlength="12" maxlength="128" autocomplete="new-password" placeholder="Initial Login Password (min. 12 characters) *" class="rounded-lg border border-slate-300 px-3 py-2 self-end">
            <input name="youtube_url" placeholder="YouTube URL (optional)" class="rounded-lg border border-slate-300 px-3 py-2">
            <input name="facebook_url" placeholder="Facebook URL (optional)" class="rounded-lg border border-slate-300 px-3 py-2">
            <div class="flex items-center justify-between gap-2">
                <label class="inline-flex items-center gap-2 font-semibold">
                    <input type="checkbox" name="is_active" value="1" checked> Active
                </label>
                <button type="submit" class="px-5 py-2 rounded-lg bg-[#0284C7] hover:bg-[#0369A1] text-white font-bold">Add Instructor</button>
            </div>
        </form>
    </div>

    <div class="bg-white rounded-2xl border border-slate-200 p-5 space-y-4">
        <h2 class="text-sm font-bold text-slate-900">Instructors Directory ({{ $teachers->total() }})</h2>
        <div class="space-y-3">
            @forelse ($teachers as $teacher)
                <div class="border border-slate-200 rounded-xl p-4 space-y-3 text-xs">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                        <div class="flex items-center gap-3">
                            @if ($teacher->photo_path)
                                <img src="{{ $teacher->photo_path }}" alt="{{ $teacher->user?->name }}" class="w-12 h-12 rounded-full object-cover border">
                            @else
                                <div class="w-12 h-12 rounded-full bg-[#0284C7] text-white font-bold flex items-center justify-center">
                                    {{ strtoupper(substr($teacher->user?->name ?? 'IN', 0, 2)) }}
                                </div>
                            @endif
                            <div>
                                <div class="font-bold text-sm text-slate-900">{{ $teacher->user?->name ?? 'Instructor #' . $teacher->id }}</div>
                                <div class="text-slate-500">{{ $teacher->specialization }} &middot; {{ $teacher->credentials ?: 'Senior Mentor' }}</div>
                                <div class="text-[11px] font-mono text-slate-400">{{ $teacher->employee_code }} &middot; {{ $teacher->user?->email }} &middot; {{ $teacher->is_active ? 'Active' : 'Inactive' }}</div>
                            </div>
                        </div>
                        <form method="POST" action="/admin/teachers/{{ $teacher->id }}" onsubmit="return confirm('Move this instructor to the Admin Recycle Bin? You can restore them later.')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="px-3 py-1.5 rounded-lg border border-rose-200 text-rose-700 font-bold hover:bg-rose-50">Move to Recycle Bin</button>
                        </form>
                    </div>

                    <details class="pt-2 border-t border-slate-100">
                        <summary class="cursor-pointer font-semibold text-[#0284C7]">Edit Profile &amp; Upload Portrait</summary>
                        <form method="POST" action="/admin/teachers/{{ $teacher->id }}" enctype="multipart/form-data" class="mt-3 grid sm:grid-cols-3 gap-3">
                            @csrf
                            @method('PUT')
                            <input name="name" required value="{{ $teacher->user?->name }}" class="rounded-lg border border-slate-300 px-3 py-2">
                            <input name="email" type="email" value="{{ $teacher->user?->email }}" class="rounded-lg border border-slate-300 px-3 py-2">
                            <input name="employee_code" required value="{{ $teacher->employee_code }}" class="rounded-lg border border-slate-300 px-3 py-2 font-mono">
                            <input name="specialization" required value="{{ $teacher->specialization }}" class="rounded-lg border border-slate-300 px-3 py-2">
                            <input name="credentials" value="{{ $teacher->credentials }}" placeholder="Credentials / Title" class="rounded-lg border border-slate-300 px-3 py-2">
                            <select name="branch_id" class="rounded-lg border border-slate-300 px-3 py-2 bg-white">
                                <option value="">Main / All Branches</option>
                                @foreach ($branches as $br)
                                    <option value="{{ $br->id }}" @selected($teacher->branch_id === $br->id)>{{ $br->name }}</option>
                                @endforeach
                            </select>
                            <input type="file" name="photo" accept="image/*" class="rounded-lg border border-slate-300 px-2 py-1.5 bg-slate-50">
                            <input name="photo_url" value="{{ $teacher->photo_path }}" placeholder="Portrait Path / URL" class="rounded-lg border border-slate-300 px-3 py-2">
                            <input name="youtube_url" value="{{ $teacher->youtube_url }}" placeholder="YouTube URL" class="rounded-lg border border-slate-300 px-3 py-2">
                            <input name="facebook_url" value="{{ $teacher->facebook_url }}" placeholder="Facebook URL" class="rounded-lg border border-slate-300 px-3 py-2 sm:col-span-2">
                            <div class="flex items-center justify-between gap-2">
                                <label class="inline-flex items-center gap-2 font-semibold">
                                    <input type="checkbox" name="is_active" value="1" @checked($teacher->is_active)> Active
                                </label>
                                <button type="submit" class="px-4 py-1.5 rounded-lg bg-slate-900 text-white font-bold">Update Instructor</button>
                            </div>
                        </form>
                    </details>
                </div>
            @empty
                <p class="text-xs text-slate-400">No custom instructors created yet. Add one above to customize the public Instructors grid.</p>
            @endforelse
        </div>
        {{ $teachers->links() }}
    </div>
</div>
@endsection
