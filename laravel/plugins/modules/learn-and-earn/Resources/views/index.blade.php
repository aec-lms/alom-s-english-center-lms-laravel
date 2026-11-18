@extends('layouts.admin')
@section('title', 'Learn & Earn')
@section('content')
<div class="container-fluid py-4">
    <h1 class="h3 mb-3">Learn &amp; Earn</h1>
    @if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif

    <div class="card mb-3">
        <div class="card-header">New Category</div>
        <div class="card-body">
            <form method="post" action="{{ route('plugins.learn_earn.categories.store') }}">
                @csrf
                <input class="form-control mb-2" name="name" placeholder="Category" required>
                <input class="form-control mb-2" name="description" placeholder="Description">
                <button class="btn btn-primary">Save category</button>
            </form>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header">Create MCQ</div>
        <div class="card-body">
            <form method="post" action="{{ route('plugins.learn_earn.questions.store') }}">
                @csrf
                <select class="form-select mb-2" name="category_id">
                    <option value="">No category</option>
                    @foreach($categories as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach
                </select>
                <div class="row g-2 mb-2">
                    <div class="col"><input class="form-control" name="subject" maxlength="120" placeholder="Subject / Topic"></div>
                    <div class="col"><select class="form-select" name="difficulty"><option value="easy">Easy</option><option value="medium" selected>Medium</option><option value="hard">Hard</option></select></div>
                </div>
                <textarea class="form-control mb-2" name="question" maxlength="5000" required placeholder="Question"></textarea>
                <div class="row">
                    @for($i = 0; $i < 4; $i++)
                        <div class="col-md-6 mb-2"><input class="form-control" name="options[]" required placeholder="Option {{ $i + 1 }}"></div>
                    @endfor
                </div>
                <div class="row g-2">
                    <div class="col"><label class="form-label">Correct option index (0–3)</label><input class="form-control" type="number" min="0" max="3" name="correct_option_index" required></div>
                    <div class="col"><label class="form-label">Positive marks</label><input class="form-control" type="number" step=".01" min=".01" name="positive_marks" value="1" required></div>
                    <div class="col"><label class="form-label">Negative marks</label><input class="form-control" type="number" step=".01" min="0" name="negative_marks" value="0" required></div>
                </div>
                <textarea class="form-control mt-2" name="explanation" maxlength="5000" rows="2" placeholder="Explanation / learning note (optional)"></textarea>
                <button class="btn btn-primary mt-2">Save MCQ</button>
            </form>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header">Question Bank</div>
        <div class="table-responsive">
            <table class="table mb-0">
                <thead><tr><th>Question / Subject</th><th>Category</th><th>Level</th><th>Marks</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                @forelse($questions as $question)
                    <tr>
                        <td><div class="fw-semibold">{{ $question->question }}</div><small class="text-muted">{{ $question->subject ?: 'General' }}</small></td>
                        <td>{{ $question->category?->name ?? '—' }}</td>
                        <td>{{ ucfirst($question->difficulty ?? 'medium') }}</td>
                        <td>+{{ $question->positive_marks }} / −{{ $question->negative_marks }}</td>
                        <td>{{ $question->is_active ? 'Active' : 'Archived' }}</td>
                        <td>
                            <details>
                                <summary class="btn btn-sm btn-outline-primary">Edit MCQ</summary>
                                <form method="POST" action="{{ route('plugins.learn_earn.questions.update', $question->id) }}" class="border rounded p-3 mt-2">
                                    @csrf @method('PUT')
                                    <select class="form-select mb-2" name="category_id"><option value="">No category</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected((int)$question->category_id === (int)$category->id)>{{ $category->name }}</option>@endforeach</select>
                                    <div class="row g-2 mb-2">
                                        <div class="col"><input class="form-control" name="subject" maxlength="120" value="{{ $question->subject }}" placeholder="Subject / Topic"></div>
                                        <div class="col"><select class="form-select" name="difficulty">@foreach(['easy'=>'Easy','medium'=>'Medium','hard'=>'Hard'] as $value=>$label)<option value="{{ $value }}" @selected(($question->difficulty ?? 'medium') === $value)>{{ $label }}</option>@endforeach</select></div>
                                    </div>
                                    <textarea class="form-control mb-2" name="question" maxlength="5000" required>{{ $question->question }}</textarea>
                                    <div class="row g-2">
                                        @foreach(($question->options ?? []) as $optionIndex=>$option)
                                            <div class="col-md-6"><input class="form-control mb-2" name="options[{{ $optionIndex }}]" maxlength="500" required value="{{ $option }}"></div>
                                        @endforeach
                                    </div>
                                    <div class="row g-2">
                                        <div class="col"><label class="form-label">Correct option</label><select class="form-select" name="correct_option_index">@for($i=0;$i<4;$i++)<option value="{{ $i }}" @selected((int)$question->correct_option_index === $i)>Option {{ $i+1 }}</option>@endfor</select></div>
                                        <div class="col"><label class="form-label">Positive marks</label><input class="form-control" type="number" step=".01" min=".01" max="1000" name="positive_marks" value="{{ $question->positive_marks }}" required></div>
                                        <div class="col"><label class="form-label">Negative marks</label><input class="form-control" type="number" step=".01" min="0" max="1000" name="negative_marks" value="{{ $question->negative_marks }}" required></div>
                                    </div>
                                    <textarea class="form-control mt-2" name="explanation" maxlength="5000" rows="2" placeholder="Explanation">{{ $question->explanation }}</textarea>
                                    <button class="btn btn-primary btn-sm mt-2">Save changes</button>
                                </form>
                            </details>
                            <form method="POST" action="{{ route('plugins.learn_earn.questions.status', $question->id) }}" class="mt-2">
                                @csrf @method('PATCH')
                                <input type="hidden" name="is_active" value="{{ $question->is_active ? '0' : '1' }}">
                                <button class="btn btn-sm {{ $question->is_active ? 'btn-outline-danger' : 'btn-outline-success' }}">{{ $question->is_active ? 'Archive' : 'Restore' }}</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-muted text-center">No MCQs yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">{{ $questions->links() }}</div>
    </div>

    @if($examIntegrationEnabled)
        <div class="card mb-3">
            <div class="card-header">Build Exam from Selected MCQs</div>
            <div class="card-body">
                <form method="post" action="{{ route('plugins.learn_earn.exams.store') }}">
                    @csrf
                    @if($courseIntegrationEnabled)
                        <label class="form-label">Course (optional)</label>
                        <select class="form-select mb-2" name="course_id">
                            <option value="">General exam (not attached to a course)</option>
                            @foreach($courses as $course)<option value="{{ $course->id }}">{{ $course->title }}</option>@endforeach
                        </select>
                    @endif
                    <input class="form-control mb-2" name="title" placeholder="Exam title" required>
                    <div class="row g-2">
                        <div class="col"><label class="form-label">Minutes</label><input class="form-control" type="number" name="duration_minutes" min="5" max="360" value="30" required></div>
                        <div class="col"><label class="form-label">Pass marks</label><input class="form-control" type="number" step=".01" name="pass_marks" value="10" required></div>
                        <div class="col"><label class="form-label">Access</label><select class="form-select" name="type"><option value="free">Free</option><option value="paid">Paid</option></select></div>
                        <div class="col"><label class="form-label">Price (paid)</label><input class="form-control" type="number" step=".01" min=".01" name="access_price"></div>
                    </div>
                    <p class="mt-3 mb-1">Select MCQs:</p>
                    @foreach($questions as $question)
                        @if($question->is_active)
                            <label class="d-block"><input type="checkbox" name="question_ids[]" value="{{ $question->id }}"> {{ \Illuminate\Support\Str::limit($question->question, 130) }} (+{{ $question->positive_marks }} / −{{ $question->negative_marks }})</label>
                        @endif
                    @endforeach
                    <label class="d-block mt-2"><input type="checkbox" name="is_published" value="1"> Publish</label>
                    <button class="btn btn-success mt-2">Create exam</button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header">Created Exams &amp; Paid Access</div>
            <div class="table-responsive">
                <table class="table mb-0">
                    <thead><tr><th>Exam</th><th>Type</th><th>Questions</th><th>Published</th><th>Grant paid access</th></tr></thead>
                    <tbody>
                    @forelse($exams as $exam)
                        <tr>
                            <td>{{ $exam->title }}</td>
                            <td>{{ $exam->learn_earn_type ?? 'free' }}</td>
                            <td>{{ $exam->questions_count }}</td>
                            <td>{{ $exam->is_published ? 'Yes' : 'No' }}</td>
                            <td>
                                @if(($exam->learn_earn_type ?? 'free') === 'paid')
                                    <form method="post" action="{{ route('plugins.learn_earn.exams.grant', $exam->id) }}">
                                        @csrf
                                        <input class="form-control form-control-sm mb-1" type="number" name="user_id" placeholder="Student user ID" required>
                                        <input class="form-control form-control-sm mb-1" name="payment_reference" placeholder="Verified payment reference" required>
                                        <button class="btn btn-sm btn-outline-primary">Grant access</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-muted text-center">No exams have been created.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            <div class="card-footer">{{ $exams->links() }}</div>
        </div>
    @else
        <div class="alert alert-info">Your MCQ bank is ready. Enable the optional Exam Management plugin to build, publish, and deliver exams.</div>
    @endif
</div>
@endsection
