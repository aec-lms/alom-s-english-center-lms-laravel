@extends('layouts.admin')
@section('title', 'Course Teacher Assignments')
@section('content')
<div class="mx-auto max-w-6xl space-y-6">
  <div><h1 class="text-2xl font-extrabold">Course Teacher Assignments</h1><p class="text-sm text-slate-500">Assign teachers to courses. Mobile instructor access is limited to explicitly assigned courses.</p></div>
  @if(session('status'))<div class="rounded-lg bg-emerald-50 p-3 text-emerald-800">{{ session('status') }}</div>@endif
  @if($errors->any())<div class="rounded-lg bg-rose-50 p-3 text-rose-700">{{ $errors->first() }}</div>@endif
  <form method="POST" action="{{ route('plugins.courses.teachers.store') }}" class="grid gap-3 rounded-2xl border bg-white p-5 md:grid-cols-3">@csrf
    <label class="text-sm font-medium">Course<select name="course_id" required class="mt-1 w-full rounded-lg border p-2"><option value="">Select course</option>@foreach($courses as $course)<option value="{{ $course->id }}">{{ $course->title }}</option>@endforeach</select></label>
    <label class="text-sm font-medium">Teacher<select name="teacher_user_id" required class="mt-1 w-full rounded-lg border p-2"><option value="">Select teacher</option>@foreach($teachers as $teacher)<option value="{{ $teacher->id }}">{{ $teacher->name }} · {{ $teacher->email }}</option>@endforeach</select></label>
    <div class="flex items-end"><button class="rounded-lg bg-slate-900 px-5 py-2.5 font-semibold text-white">Assign teacher</button></div>
  </form>
  <div class="overflow-x-auto rounded-2xl border bg-white p-4"><table class="w-full text-sm"><thead><tr class="border-b text-left"><th class="py-3">Course</th><th>Teacher</th><th>Email</th><th></th></tr></thead><tbody>
  @forelse($assignments as $item)<tr class="border-b"><td class="py-3">{{ $item->course_title }}</td><td>{{ $item->teacher_name }}</td><td>{{ $item->teacher_email }}</td><td class="text-right"><form method="POST" action="{{ route('plugins.courses.teachers.destroy',$item->id) }}" onsubmit="return confirm('Remove this teacher assignment?')">@csrf @method('DELETE')<button class="text-rose-700 underline">Remove</button></form></td></tr>@empty<tr><td colspan="4" class="py-6 text-center text-slate-500">No course teachers assigned.</td></tr>@endforelse
  </tbody></table>{{ $assignments->links() }}</div>
</div>
@endsection
