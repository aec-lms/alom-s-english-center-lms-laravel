@extends('layouts.admin')
@section('title','Course Assessments')
@section('content')
<div class="mx-auto max-w-7xl space-y-6">
  <header><h1 class="text-2xl font-extrabold">Course Assessments & Question Sets</h1><p class="text-sm text-slate-500">Create reusable quizzes and written assessments, attach them to courses, and track student attempts</p></header>
  @if(session('status'))<div class="rounded-xl bg-emerald-50 p-3 text-emerald-800">{{ session('status') }}</div>@endif
  @if($errors->any())<div class="rounded-xl bg-rose-50 p-3 text-rose-700">{{ $errors->first() }}</div>@endif
  <form method="POST" action="{{ route('plugins.course-assessments.admin.store') }}" class="space-y-4 rounded-2xl border bg-white p-5">@csrf
    <h2 class="font-bold">Create assessment set</h2>
    <div class="grid gap-3 md:grid-cols-2">
      <label class="text-sm font-medium">Title<input name="title" required maxlength="180" class="mt-1 w-full rounded-lg border p-2"></label>
      @if($courses->isNotEmpty())<label class="text-sm font-medium">Attach to course (optional)<select name="course_id" class="mt-1 w-full rounded-lg border p-2"><option value="">Standalone assessment</option>@foreach($courses as $course)<option value="{{ $course->id }}">{{ $course->title }}</option>@endforeach</select></label>@endif
      <label class="text-sm font-medium">Status<select name="status" class="mt-1 w-full rounded-lg border p-2"><option value="draft">Draft</option><option value="published">Published</option></select></label>
      <label class="text-sm font-medium">Time limit (minutes)<input name="time_limit_minutes" type="number" min="1" max="600" class="mt-1 w-full rounded-lg border p-2"></label>
      <label class="text-sm font-medium">Maximum attempts<input name="max_attempts" type="number" min="1" max="50" class="mt-1 w-full rounded-lg border p-2"></label>
      <label class="text-sm font-medium">Questions per attempt (blank = all)<input name="questions_per_attempt" type="number" min="1" max="200" class="mt-1 w-full rounded-lg border p-2"></label>
      <label class="text-sm font-medium">Available from<input name="available_from" type="datetime-local" class="mt-1 w-full rounded-lg border p-2"></label>
      <label class="text-sm font-medium">Available until<input name="available_until" type="datetime-local" class="mt-1 w-full rounded-lg border p-2"></label>
    </div>
    <label class="block text-sm font-medium">Description<textarea name="description" rows="2" class="mt-1 w-full rounded-lg border p-2"></textarea></label>
    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="randomize_questions" value="1" checked> Randomize question order / selection</label>
    @if($bankQuestions->isNotEmpty())
      <div class="rounded-xl border p-4"><label class="block text-sm font-semibold">Import from Question Bank (snapshot)</label><select name="source_question_ids[]" multiple size="6" class="mt-2 w-full rounded-lg border p-2">@foreach($bankQuestions as $q)<option value="{{ $q->id }}">#{{ $q->id }} · {{ $q->subject }} · {{ $q->question_type }} · {{ \Illuminate\Support\Str::limit($q->prompt,100) }}</option>@endforeach</select><p class="mt-1 text-xs text-slate-500">Hold Ctrl/Cmd to select multiple. Imported questions are copied as snapshots; later bank edits do not change this assessment.</p></div>
    @endif
    <div class="rounded-xl border p-4 space-y-3"><h3 class="font-semibold">Add one question manually</h3>
      <label class="block text-sm">Question<textarea name="questions[0][question]" rows="2" class="mt-1 w-full rounded-lg border p-2"></textarea></label>
      <div class="grid gap-3 md:grid-cols-3">
        <label class="text-sm">Type<select name="questions[0][type]" class="mt-1 w-full rounded-lg border p-2"><option value="mcq">MCQ</option><option value="true_false">True / False</option><option value="short_answer">Short answer</option><option value="written">Written</option></select></label>
        <label class="text-sm">Options (one per line)<textarea name="questions[0][options_text]" rows="3" class="mt-1 w-full rounded-lg border p-2" placeholder="Option A&#10;Option B"></textarea></label>
        <label class="text-sm">Correct option index (0-based)<input name="questions[0][correct_option]" type="number" min="0" max="7" class="mt-1 w-full rounded-lg border p-2"></label>
        <label class="text-sm">Reference answer<input name="questions[0][correct_answer]" class="mt-1 w-full rounded-lg border p-2"></label>
        <label class="text-sm">Marks<input name="questions[0][marks]" type="number" step="0.25" min="0.25" value="1" class="mt-1 w-full rounded-lg border p-2"></label>
        <label class="text-sm">Difficulty<select name="questions[0][difficulty]" class="mt-1 w-full rounded-lg border p-2"><option>easy</option><option selected>medium</option><option>hard</option></select></label>
      </div>
      <p class="text-xs text-slate-500">Leave the question field blank to skip the manual question. MCQ / True-False need options and correct option; written questions need a reference answer.</p>
    </div>
    <button class="rounded-lg bg-slate-900 px-5 py-2.5 font-semibold text-white">Save assessment set</button>
  </form>
  <section class="overflow-x-auto rounded-2xl border bg-white p-4"><div class="mb-3 flex items-center justify-between"><h2 class="font-bold">Existing assessment sets</h2><a href="{{ route('plugins.course-assessments.grading.index') }}" class="text-sm text-sky-700 underline">Written answer grading</a></div><table class="w-full text-sm"><thead><tr class="border-b text-left"><th class="py-3">Assessment</th><th>Course</th><th>Status</th><th>Questions</th><th>Attempts</th><th></th></tr></thead><tbody>
  @forelse($sets as $set)<tr class="border-b"><td class="py-3"><strong>{{ $set->title }}</strong><div class="text-xs text-slate-500">{{ $set->description }}</div></td><td>{{ $set->course_id ? ($courses->firstWhere('id',$set->course_id)->title ?? 'Course #'.$set->course_id) : 'Standalone' }}</td><td>{{ $set->status }}</td><td>{{ $set->questions_count }}</td><td>{{ $set->attempts_count }}</td><td>@if($set->status!=='published')<form method="POST" action="{{ route('plugins.course-assessments.admin.publish',$set) }}">@csrf @method('PATCH')<button class="text-sky-700 underline">Publish</button></form>@endif</td></tr>@empty<tr><td colspan="6" class="py-6 text-center text-slate-500">No assessment sets yet.</td></tr>@endforelse
  </tbody></table>{{ $sets->links() }}</section>
</div>
<script>
document.querySelector('form[action="{{ route('plugins.course-assessments.admin.store') }}"]')?.addEventListener('submit',function(){
 const q=this.querySelector('[name="questions[0][question]"]');
 const options=this.querySelector('[name="questions[0][options_text]"]')?.value||'';
 if(q&&q.value.trim()!==''){
   const hidden=document.createElement('input');hidden.type='hidden';hidden.name='questions[0][options]';hidden.value='';
   const list=options.split('\n').map(x=>x.trim()).filter(Boolean);
   list.forEach((value,index)=>{const i=document.createElement('input');i.type='hidden';i.name='questions[0][options]['+index+']';i.value=value;this.appendChild(i);});
 } else {
   q?.removeAttribute('name');
   this.querySelectorAll('[name^="questions[0]"]').forEach(el=>el.removeAttribute('name'));
 }
});
</script>
@endsection
