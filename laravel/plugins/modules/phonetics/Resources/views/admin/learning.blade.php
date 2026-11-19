@extends('layouts.admin')
@section('title','Phonetics Learning & Exams')
@section('content')
<div class="mx-auto max-w-7xl space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div><h1 class="text-2xl font-extrabold">Course Vocabulary & Exams</h1><p class="mt-1 text-sm text-slate-500">Assign words by course, category, lesson and batch. Create exams for free, paid or all courses.</p></div>
        <a href="{{ route('plugins.phonetics.admin.index') }}" class="rounded-lg border px-4 py-2 text-sm font-semibold">Vocabulary Library</a>
        <a href="{{ route('plugins.phonetics.admin.learning.recordings') }}" class="rounded-lg border px-4 py-2 text-sm font-semibold">Speaking Review</a>
    </div>

    <section class="rounded-xl border bg-white p-5">
        <h2 class="text-lg font-bold">Assign words to a learning group</h2>
        <form method="POST" action="{{ route('plugins.phonetics.admin.learning.assignments.store') }}" class="mt-4 grid gap-3 md:grid-cols-3">
            @csrf
            <label class="text-sm font-semibold">Course category
                <select name="course_category_ref" class="mt-1 w-full rounded border p-2"><option value="">Any category (use course below)</option>@foreach($categories as $category)<option value="{{ $category['id'] }}">{{ $category['title'] }}</option>@endforeach</select>
            </label>
            <label class="text-sm font-semibold">Course
                <select name="course_ref" id="assignment-course" class="mt-1 w-full rounded border p-2"><option value="">Select a course (or category)</option>@foreach($courses as $course)<option value="{{ $course['id'] }}">{{ $course['title'] }} · {{ $course['price'] > 0 ? 'Paid' : 'Free' }}</option>@endforeach</select>
            </label>
            <label class="text-sm font-semibold">Lesson
                <select name="lesson_ref" id="assignment-lesson" class="mt-1 w-full rounded border p-2"><option value="">All lessons / course level</option></select>
            </label>
            <label class="text-sm font-semibold">Batch
                <select name="batch_ref" class="mt-1 w-full rounded border p-2"><option value="">All batches</option>@foreach($batches as $batch)<option value="{{ $batch['id'] }}">{{ $batch['title'] }}</option>@endforeach</select>
            </label>
            <label class="text-sm font-semibold">Vocabulary word
                <select name="word_id" required class="mt-1 w-full rounded border p-2">@foreach($words as $word)<option value="{{ $word->id }}">{{ $word->word }} · {{ $word->category }}</option>@endforeach</select>
            </label>
            <label class="text-sm font-semibold">Course access type
                <select name="audience" class="mt-1 w-full rounded border p-2"><option value="both">Free and Paid</option><option value="free">Free courses only</option><option value="paid">Paid courses only</option></select>
            </label>
            <label class="text-sm"><input type="checkbox" name="is_required" value="1" checked> Required vocabulary</label>
            <label class="text-sm">Available from <input type="datetime-local" name="available_from" class="mt-1 block w-full rounded border p-2"></label>
            <label class="text-sm">Available until <input type="datetime-local" name="available_until" class="mt-1 block w-full rounded border p-2"></label>
            <button class="rounded-lg bg-blue-600 px-4 py-2 font-bold text-white md:col-span-3">Assign word to course / lesson / batch</button>
        </form>
    </section>

    <section class="rounded-xl border bg-white p-5">
        <h2 class="text-lg font-bold">Create a vocabulary examination</h2>
        <form method="POST" action="{{ route('plugins.phonetics.admin.learning.exams.store') }}" class="mt-4 grid gap-3 md:grid-cols-3">
            @csrf
            <label class="text-sm font-semibold md:col-span-2">Exam title<input name="title" required maxlength="180" class="mt-1 w-full rounded border p-2" placeholder="Lesson 1 Vocabulary Test"></label>
            <label class="text-sm font-semibold">Status<select name="status" class="mt-1 w-full rounded border p-2"><option value="draft">Draft</option><option value="published">Publish immediately</option></select></label>
            <label class="text-sm font-semibold">Course category<select name="course_category_ref" class="mt-1 w-full rounded border p-2"><option value="">Select category</option>@foreach($categories as $category)<option value="{{ $category['id'] }}">{{ $category['title'] }}</option>@endforeach</select></label>
            <label class="text-sm font-semibold">Course<select name="course_ref" id="exam-course" class="mt-1 w-full rounded border p-2"><option value="">Select course</option>@foreach($courses as $course)<option value="{{ $course['id'] }}">{{ $course['title'] }}</option>@endforeach</select></label>
            <label class="text-sm font-semibold">Batch<select name="batch_ref" class="mt-1 w-full rounded border p-2"><option value="">All batches</option>@foreach($batches as $batch)<option value="{{ $batch['id'] }}">{{ $batch['title'] }}</option>@endforeach</select></label>
            <label class="text-sm font-semibold">Lesson<select name="lesson_ref" id="exam-lesson" class="mt-1 w-full rounded border p-2"><option value="">All lessons / course level</option></select></label>
            <label class="text-sm font-semibold">Course type<select name="audience" class="mt-1 w-full rounded border p-2"><option value="both">Free and Paid</option><option value="free">Free only</option><option value="paid">Paid only</option></select></label>
            <label class="text-sm font-semibold">Time limit (minutes)<input type="number" name="duration_minutes" min="0" max="300" value="0" class="mt-1 w-full rounded border p-2"></label>
            <label class="text-sm font-semibold">Pass mark (%)<input type="number" name="pass_percentage" min="1" max="100" value="60" class="mt-1 w-full rounded border p-2"></label>
            <label class="text-sm font-semibold">Maximum attempts (0 = unlimited)<input type="number" name="max_attempts" min="0" max="50" value="0" class="mt-1 w-full rounded border p-2"></label>
            <label class="text-sm font-semibold">Number of questions<input type="number" name="question_count" min="1" max="100" value="10" class="mt-1 w-full rounded border p-2"></label>
            <label class="text-sm font-semibold">Question type<select name="question_type" class="mt-1 w-full rounded border p-2"><option value="identify-ipa">Choose the correct IPA</option><option value="identify-accent">Identify British or American accent</option></select></label>
            <label class="text-sm font-semibold">Available from<input type="datetime-local" name="available_from" class="mt-1 w-full rounded border p-2"></label>
            <label class="text-sm font-semibold">Available until<input type="datetime-local" name="available_until" class="mt-1 w-full rounded border p-2"></label>
            <label class="text-sm font-semibold md:col-span-3">Choose vocabulary words (select at least the question count)
                <select name="word_ids[]" multiple required size="8" class="mt-1 w-full rounded border p-2">@foreach($words as $word)<option value="{{ $word->id }}">{{ $word->word }} — {{ $word->category }} — {{ $word->uk_ipa }} / {{ $word->us_ipa }}</option>@endforeach</select>
            </label>
            <div class="flex flex-wrap gap-4 text-sm md:col-span-3"><label><input type="checkbox" name="shuffle_questions" value="1" checked> Shuffle questions</label><label><input type="checkbox" name="shuffle_options" value="1" checked> Shuffle options</label><label><input type="checkbox" name="show_result_immediately" value="1" checked> Show result immediately</label></div>
            <label class="text-sm md:col-span-3">Description<textarea name="description" rows="2" class="mt-1 w-full rounded border p-2"></textarea></label>
            <button class="rounded-lg bg-emerald-600 px-4 py-2 font-bold text-white md:col-span-3">Create examination from selected words</button>
        </form>
    </section>

    <section class="rounded-xl border bg-white p-5">
        <h2 class="text-lg font-bold">Batch student roster</h2>
        <p class="mt-1 text-sm text-slate-500">Connect learners to the batch roster used by batch-specific vocabulary and exams. If your host LMS already has a membership table, map it in Config/phonetics.php instead.</p>
        <form method="POST" action="{{ route('plugins.phonetics.admin.learning.batch-members.store') }}" class="mt-3 grid gap-3 md:grid-cols-3">@csrf
            <label class="text-sm font-semibold">Batch<select name="batch_ref" required class="mt-1 w-full rounded border p-2"><option value="">Choose batch</option>@foreach($batches as $batch)<option value="{{ $batch['id'] }}">{{ $batch['title'] }}</option>@endforeach</select></label>
            <label class="text-sm font-semibold md:col-span-2">Student account<select name="user_id" required class="mt-1 w-full rounded border p-2"><option value="">Choose student</option>@foreach($students as $student)<option value="{{ $student->id }}">{{ $student->name }} · {{ $student->email }}</option>@endforeach</select></label>
            <button class="rounded-lg bg-slate-800 px-4 py-2 font-bold text-white md:col-span-3">Add student to batch roster</button>
        </form>
        <div class="mt-4 overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr class="border-b"><th class="p-2">Student</th><th>Batch ID</th><th>Status</th><th></th></tr></thead><tbody>@forelse($batchMembers as $member)<tr class="border-b"><td class="p-2">{{ data_get($member->user, $userNameColumn, 'Deleted user') }}</td><td>{{ $member->batch_ref }}</td><td>{{ $member->is_active ? 'Active' : 'Removed' }}</td><td>@if($member->is_active)<form method="POST" action="{{ route('plugins.phonetics.admin.learning.batch-members.destroy',$member) }}">@csrf @method('DELETE')<button class="text-red-600">Remove</button></form>@endif</td></tr>@empty<tr><td colspan="4" class="p-3 text-slate-500">No batch roster entries yet.</td></tr>@endforelse</tbody></table></div>{{ $batchMembers->links() }}
    </section>

    <section class="rounded-xl border bg-white p-5">
        <h2 class="text-lg font-bold">Current word assignments</h2>
        <div class="mt-3 overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr class="border-b"><th class="p-2">Word</th><th>Course</th><th>Category</th><th>Lesson</th><th>Batch</th><th>Access</th><th></th></tr></thead><tbody>
        @forelse($assignments as $assignment)<tr class="border-b"><td class="p-2 font-semibold">{{ $assignment->word?->word ?? 'Archived word' }}</td><td>{{ $assignment->course_ref ?? '—' }}</td><td>{{ $assignment->course_category_ref ?? '—' }}</td><td>{{ $assignment->lesson_ref ?? 'All' }}</td><td>{{ $assignment->batch_ref ?? 'All' }}</td><td>{{ ucfirst($assignment->audience) }}</td><td><form method="POST" action="{{ route('plugins.phonetics.admin.learning.assignments.destroy',$assignment) }}">@csrf @method('DELETE')<button class="text-red-600">Remove</button></form></td></tr>@empty<tr><td colspan="7" class="p-4 text-slate-500">No word assignments yet.</td></tr>@endforelse
        </tbody></table></div>{{ $assignments->links() }}
    </section>

    <section class="rounded-xl border bg-white p-5">
        <h2 class="text-lg font-bold">Examinations</h2>
        <div class="mt-3 overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr class="border-b"><th class="p-2">Exam</th><th>Questions</th><th>Course</th><th>Batch</th><th>Access</th><th>Status</th><th></th></tr></thead><tbody>
        @forelse($exams as $exam)<tr class="border-b"><td class="p-2 font-semibold">{{ $exam->title }}</td><td>{{ $exam->questions_count }}</td><td>{{ $exam->course_ref ?? 'Category-wide' }}</td><td>{{ $exam->batch_ref ?? 'All' }}</td><td>{{ ucfirst($exam->audience) }}</td><td>{{ ucfirst($exam->status) }}</td><td><form method="POST" action="{{ route('plugins.phonetics.admin.learning.exams.status',$exam) }}">@csrf @method('PATCH')<select name="status" class="rounded border p-1"><option value="draft" @selected($exam->status==='draft')>Draft</option><option value="published" @selected($exam->status==='published')>Published</option><option value="archived" @selected($exam->status==='archived')>Archived</option></select><button class="ml-1 text-blue-700">Save</button></form></td></tr>@empty<tr><td colspan="7" class="p-4 text-slate-500">No exams created yet.</td></tr>@endforelse
        </tbody></table></div>{{ $exams->links() }}
    </section>
</div>
<script>
(() => {
 const endpoint=@json(route('plugins.phonetics.admin.learning.lessons'));
 async function loadLessons(course,lesson){
  if(!course||!lesson)return;
  lesson.innerHTML='<option value="">All lessons / course level</option>';
  if(!course.value)return;
  try{const url=new URL(endpoint,window.location.origin);url.searchParams.set('course_ref',course.value);const response=await fetch(url,{headers:{Accept:'application/json'},credentials:'same-origin'});if(!response.ok)return;const rows=await response.json();rows.forEach(row=>{const option=document.createElement('option');option.value=row.id;option.textContent=row.title;lesson.appendChild(option);});}catch(_){}
 }
 const assignmentCourse=document.getElementById('assignment-course'),assignmentLesson=document.getElementById('assignment-lesson');
 const examCourse=document.getElementById('exam-course'),examLesson=document.getElementById('exam-lesson');
 if(assignmentCourse)assignmentCourse.addEventListener('change',()=>loadLessons(assignmentCourse,assignmentLesson));
 if(examCourse)examCourse.addEventListener('change',()=>loadLessons(examCourse,examLesson));
})();
</script>
@endsection
