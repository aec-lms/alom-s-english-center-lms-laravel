@extends('layouts.admin')

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div><h1 class="h3 mb-1">BCS, Bank Job & Admission Question Bank</h1><p class="text-muted mb-0">Manage reusable questions and generate immutable exam papers.</p></div>
    </div>

    @if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

    <div class="card mb-4"><div class="card-header fw-semibold">Add a question</div><div class="card-body">
        <form method="POST" action="{{ route('plugins.question-bank.questions.store') }}" class="row g-3">@csrf
            <div class="col-md-3"><label class="form-label">Exam category</label><select name="exam_category" class="form-select" required><option value="bcs">BCS</option><option value="bank_job">Bank Job</option><option value="admission">Admission</option></select></div>
            <div class="col-md-3"><label class="form-label">Question type</label><select name="question_type" class="form-select" required><option value="mcq">MCQ</option><option value="written">Written</option></select></div>
            <div class="col-md-3"><label class="form-label">Subject</label><input name="subject" class="form-control" required maxlength="120"></div>
            <div class="col-md-3"><label class="form-label">Topic</label><input name="topic" class="form-control" maxlength="160"></div>
            <div class="col-md-3"><label class="form-label">Difficulty</label><select name="difficulty" class="form-select"><option value="easy">Easy</option><option value="medium" selected>Medium</option><option value="hard">Hard</option></select></div>
            <div class="col-md-3"><label class="form-label">Marks</label><input name="default_marks" type="number" min="0.01" step="0.01" value="1" class="form-control" required></div>
            <div class="col-md-3"><label class="form-label">Negative marks</label><input name="default_negative_marks" type="number" min="0" step="0.01" value="0" class="form-control"></div>
            <div class="col-12"><label class="form-label">Question</label><textarea name="prompt" class="form-control" rows="3" required></textarea></div>
            <div class="col-md-6"><label class="form-label">MCQ options (one per line)</label><textarea name="options_text" id="qb-options" class="form-control" rows="4"></textarea></div>
            <div class="col-md-6"><label class="form-label">Correct answer / written marking guide</label><textarea name="correct_answer" class="form-control" rows="4"></textarea></div>
            <div class="col-12"><label class="form-label">Explanation (optional)</label><textarea name="explanation" class="form-control" rows="2"></textarea></div>
            <div class="col-12"><button class="btn btn-primary">Save question</button></div>
        </form>
    </div></div>

    <div class="card mb-4"><div class="card-header fw-semibold">Build a question paper</div><div class="card-body">
        <form method="POST" action="{{ route('plugins.question-bank.papers.store') }}" class="row g-3">@csrf
            <div class="col-md-3"><label class="form-label">Category</label><select name="exam_category" class="form-select"><option value="bcs">BCS</option><option value="bank_job">Bank Job</option><option value="admission">Admission</option></select></div>
            <div class="col-md-5"><label class="form-label">Paper title</label><input name="title" class="form-control" required maxlength="180"></div>
            <div class="col-md-2"><label class="form-label">Duration (min)</label><input name="duration_minutes" type="number" min="5" max="600" value="60" class="form-control"></div>
            <div class="col-md-2"><label class="form-label">Selection</label><select name="selection_mode" id="qb-mode" class="form-select"><option value="random">Random</option><option value="manual">Manual IDs</option></select></div>
            <div class="col-md-3" id="qb-count"><label class="form-label">Random question count</label><input name="random_count" type="number" min="1" max="200" value="20" class="form-control"></div>
            <div class="col-md-5" id="qb-manual"><label class="form-label">Question IDs (comma separated)</label><input id="qb-ids" class="form-control" placeholder="e.g. 2,5,8"><div class="form-text">Select IDs from the question list below.</div></div>
            <div class="col-md-2"><label class="form-label">Question type</label><select name="question_type" class="form-select"><option value="">Any</option><option value="mcq">MCQ</option><option value="written">Written</option></select></div>
            <div class="col-md-2"><label class="form-label">Difficulty</label><select name="difficulty" class="form-select"><option value="">Any</option><option value="easy">Easy</option><option value="medium">Medium</option><option value="hard">Hard</option></select></div>
            <div class="col-md-4"><label class="form-label">Subject (optional)</label><input name="subject" class="form-control"></div>
            <div class="col-12"><label class="form-label">Instructions</label><textarea name="instructions" class="form-control" rows="2"></textarea></div>
            <div class="col-12"><button class="btn btn-success">Generate paper snapshot</button></div>
            <input type="hidden" name="question_ids[]" id="qb-question-ids" value="">
        </form>
    </div></div>

    <div class="card"><div class="card-header fw-semibold">Question bank</div><div class="card-body border-bottom"><form method="GET" class="row g-2"><div class="col-md-2"><select name="exam_category" class="form-select"><option value="">All categories</option><option value="bcs">BCS</option><option value="bank_job">Bank Job</option><option value="admission">Admission</option></select></div><div class="col-md-2"><select name="question_type" class="form-select"><option value="">All types</option><option value="mcq">MCQ</option><option value="written">Written</option></select></div><div class="col-md-2"><input name="subject" class="form-control" placeholder="Subject"></div><div class="col-md-2"><select name="difficulty" class="form-select"><option value="">All levels</option><option value="easy">Easy</option><option value="medium">Medium</option><option value="hard">Hard</option></select></div><div class="col-md-3"><input name="search" class="form-control" placeholder="Search question"></div><div class="col-md-1"><button class="btn btn-outline-primary">Filter</button></div></form></div><div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead><tr><th>ID</th><th>Category</th><th>Type</th><th>Subject / Topic</th><th>Question</th><th>Level</th><th>Marks</th></tr></thead>
        <tbody>@forelse($questions as $question)<tr><td>{{ $question->id }}</td><td>{{ strtoupper(str_replace('_',' ',$question->exam_category)) }}</td><td>{{ strtoupper($question->question_type) }}</td><td>{{ $question->subject }}<small class="d-block text-muted">{{ $question->topic }}</small></td><td>{{ \Illuminate\Support\Str::limit($question->prompt,100) }}</td><td>{{ ucfirst($question->difficulty) }}</td><td>{{ $question->default_marks }}</td></tr>
<tr><td colspan="7"><details><summary class="text-primary">Edit / delete question #{{ $question->id }}</summary>
<form method="POST" action="{{ route('plugins.question-bank.questions.update',$question) }}" class="row g-2 p-3">@csrf @method('PUT')
<div class="col-md-2"><select name="exam_category" class="form-select"><option value="bcs" @selected($question->exam_category==='bcs')>BCS</option><option value="bank_job" @selected($question->exam_category==='bank_job')>Bank Job</option><option value="admission" @selected($question->exam_category==='admission')>Admission</option></select></div>
<div class="col-md-2"><select name="question_type" class="form-select"><option value="mcq" @selected($question->question_type==='mcq')>MCQ</option><option value="written" @selected($question->question_type==='written')>Written</option></select></div>
<div class="col-md-2"><input name="subject" class="form-control" value="{{ $question->subject }}" required></div><div class="col-md-2"><input name="topic" class="form-control" value="{{ $question->topic }}" placeholder="Topic"></div>
<div class="col-md-2"><select name="difficulty" class="form-select"><option value="easy" @selected($question->difficulty==='easy')>Easy</option><option value="medium" @selected($question->difficulty==='medium')>Medium</option><option value="hard" @selected($question->difficulty==='hard')>Hard</option></select></div>
<div class="col-md-1"><input name="default_marks" type="number" step="0.01" min="0.01" class="form-control" value="{{ $question->default_marks }}"></div><div class="col-md-1"><input name="default_negative_marks" type="number" step="0.01" min="0" class="form-control" value="{{ $question->default_negative_marks }}"></div>
<div class="col-12"><textarea name="prompt" class="form-control" rows="2" required>{{ $question->prompt }}</textarea></div>
<div class="col-md-6"><label class="form-label">MCQ options (one per line)</label><textarea name="options_text" class="form-control qb-edit-options" rows="3">{{ implode("\\n",$question->options ?? []) }}</textarea></div>
<div class="col-md-6"><label class="form-label">Correct answer / marking guide</label><textarea name="correct_answer" class="form-control" rows="3">{{ $question->correct_answer }}</textarea></div>
<div class="col-12"><textarea name="explanation" class="form-control" rows="2" placeholder="Explanation">{{ $question->explanation }}</textarea></div>
<div class="col-12 d-flex gap-2"><button class="btn btn-sm btn-primary">Update question</button></form>
<form method="POST" action="{{ route('plugins.question-bank.questions.destroy',$question) }}" onsubmit="return confirm('Move this question to recycle state?')">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger">Delete</button></form></div>
</details></td></tr>@empty<tr><td colspan="7" class="text-center text-muted py-4">No questions found. Add the first question above.</td></tr>@endforelse</tbody>
    </table></div><div class="card-footer">{{ $questions->links() }}</div></div>

    @if($papers->isNotEmpty())<div class="card mt-4"><div class="card-header fw-semibold">Recent generated papers</div><div class="list-group list-group-flush">@foreach($papers as $paper)<a class="list-group-item list-group-item-action" href="{{ route('plugins.question-bank.papers.show',$paper) }}">{{ $paper->title }} <span class="text-muted">({{ strtoupper(str_replace('_',' ',$paper->exam_category)) }})</span></a>@endforeach</div></div>@endif
</div>
<script>
document.querySelectorAll('form[action="{{ route('plugins.question-bank.questions.store') }}"], form[action*="/questions/"]').forEach(form => {
    form.addEventListener('submit', () => {
        const textarea = form.querySelector('[name="options_text"]');
        if (!textarea) return;
        form.querySelectorAll('input[name="options[]"]').forEach(input => input.remove());
        textarea.value.split(/\\r?\\n/).map(x => x.trim()).filter(Boolean).forEach(option => {
            const input = document.createElement('input');
            input.type = 'hidden'; input.name = 'options[]'; input.value = option; form.appendChild(input);
        });
    });
});
const mode = document.getElementById('qb-mode');
const manual = document.getElementById('qb-manual');
const count = document.getElementById('qb-count');
mode?.addEventListener('change', () => { manual.hidden = mode.value !== 'manual'; count.hidden = mode.value !== 'random'; });
manual.hidden = true;
document.querySelector('form[action="{{ route('plugins.question-bank.papers.store') }}"]')?.addEventListener('submit', () => {
    const holder = document.getElementById('qb-question-ids').parentElement;
    document.getElementById('qb-question-ids').remove();
    if (mode.value === 'manual') {
        const ids = document.getElementById('qb-ids').value.split(',').map(x => x.trim()).filter(Boolean);
        ids.forEach(id => { const input = document.createElement('input'); input.type='hidden'; input.name='question_ids[]'; input.value=id; holder.appendChild(input); });
    }
});
</script>
@endsection
