@extends('layouts.app')

@section('title', 'Quiz Contest Management')

@section('content')
<div class="page-header"><div><h1>Quiz Contest Management</h1><p class="muted">প্রতিযোগিতা তৈরি করুন, অংশগ্রহণকারীদের ফলাফল দেখুন এবং সময় শেষে বিজয়ী প্রকাশ করুন।</p></div></div>
<div class="panel">
    <h2>নতুন প্রতিযোগিতা</h2>
    <form method="POST" action="{{ route('plugins.quiz-contest.admin.store') }}" id="quiz-contest-form">
        @csrf
        <div class="form-grid">
            <label>শিরোনাম<input name="title" maxlength="180" required></label>
            <label>পুরস্কার<input name="prize_description" maxlength="500" required></label>
            <label>বিজয়ীর সংখ্যা<input type="number" name="winner_count" min="1" max="100" value="1" required></label>
            <label>Branch ID (ঐচ্ছিক)<input type="number" name="branch_id" min="1"></label>
            <label>শুরুর সময়<input type="datetime-local" name="starts_at" required></label>
            <label>শেষ সময়<input type="datetime-local" name="ends_at" required></label>
        </div>
        <label>বিবরণ<textarea name="description" rows="2" maxlength="5000"></textarea></label>
        <div id="contest-questions">
            <h3>প্রশ্ন</h3>
            <fieldset class="panel contest-question" data-index="0">
                <legend>প্রশ্ন ১</legend>
                <label>প্রশ্ন<input name="questions[0][question]" required maxlength="2000"></label>
                <div class="form-grid">
                    @for($i=0;$i<4;$i++)<label>Option {{ $i+1 }}<input name="questions[0][options][{{ $i }}]" required maxlength="300"></label>@endfor
                </div>
                <label>সঠিক Option<select name="questions[0][correct_option]" required>@for($i=0;$i<4;$i++)<option value="{{ $i }}">Option {{ $i+1 }}</option>@endfor</select></label>
            </fieldset>
        </div>
        <button class="btn secondary" type="button" id="add-contest-question">+ প্রশ্ন যোগ করুন</button>
        <button class="btn primary" type="submit">প্রতিযোগিতা প্রকাশ করুন</button>
    </form>
</div>
<div class="panel" style="margin-top:16px">
    <h2>প্রতিযোগিতার তালিকা</h2>
    <div class="table-wrap"><table><thead><tr><th>শিরোনাম</th><th>Branch</th><th>অবস্থা</th><th>অংশগ্রহণ</th><th></th></tr></thead><tbody>
    @forelse($contests as $contest)
        <tr><td>{{ $contest->title }}</td><td>{{ $contest->branch_id ?? 'সব Branch' }}</td><td>{{ $contest->status }}</td><td>{{ $contest->entries_count }}</td><td>
        <a class="btn secondary" href="{{ route('plugins.quiz-contest.admin.entries', $contest->id) }}">Entries</a>
        @if(!$contest->drawn_at && $contest->entries_count === 0 && $contest->status === 'published' && now()->lessThan($contest->starts_at))
            <a class="btn secondary" href="{{ route('plugins.quiz-contest.admin.edit', $contest->id) }}">Edit</a>
        @endif
        @if(!$contest->drawn_at && $contest->status === 'published' && now()->between($contest->starts_at, $contest->ends_at))
            <form method="POST" action="{{ route('plugins.quiz-contest.admin.status', $contest->id) }}" style="display:inline">@csrf @method('PATCH')<input type="hidden" name="status" value="paused"><button class="btn secondary" type="submit">Pause</button></form>
        @elseif(!$contest->drawn_at && $contest->status === 'paused' && now()->lessThan($contest->ends_at))
            <form method="POST" action="{{ route('plugins.quiz-contest.admin.status', $contest->id) }}" style="display:inline">@csrf @method('PATCH')<input type="hidden" name="status" value="published"><button class="btn secondary" type="submit">Resume</button></form>
        @elseif(!$contest->drawn_at && in_array($contest->status, ['published', 'paused'], true) && now()->greaterThanOrEqualTo($contest->ends_at))
            <form method="POST" action="{{ route('plugins.quiz-contest.admin.status', $contest->id) }}" style="display:inline">@csrf @method('PATCH')<input type="hidden" name="status" value="closed"><button class="btn secondary" type="submit">Close</button></form>
        @endif
        @if(!$contest->drawn_at && now()->greaterThanOrEqualTo($contest->ends_at))
            <form method="POST" action="{{ route('plugins.quiz-contest.admin.publish-results', $contest->id) }}" style="display:inline">@csrf<button class="btn primary" type="submit">বিজয়ী প্রকাশ</button></form>
        @endif</td></tr>
    @empty
        <tr><td colspan="5" class="muted">কোনো প্রতিযোগিতা নেই।</td></tr>
    @endforelse
    </tbody></table></div>
    {{ $contests->links() }}
</div>
<script>
(() => {
    const list = document.getElementById('contest-questions');
    const add = document.getElementById('add-contest-question');
    if (!list || !add) return;
    let index = 1;
    add.addEventListener('click', () => {
        if (index >= 50) return;
        const fieldset = document.createElement('fieldset');
        fieldset.className = 'panel contest-question';
        fieldset.innerHTML = '<legend>প্রশ্ন ' + (index + 1) + '</legend>' +
            '<label>প্রশ্ন<input required maxlength="2000" name="questions[' + index + '][question]"></label>' +
            '<div class="form-grid">' +
            [0,1,2,3].map(i => '<label>Option ' + (i+1) + '<input required maxlength="300" name="questions[' + index + '][options][' + i + ']"></label>').join('') +
            '</div><label>সঠিক Option<select name="questions[' + index + '][correct_option]">' +
            [0,1,2,3].map(i => '<option value="' + i + '">Option ' + (i+1) + '</option>').join('') +
            '</select></label><button type="button" class="btn secondary remove-question">Remove</button>';
        fieldset.querySelector('.remove-question').addEventListener('click', () => fieldset.remove());
        list.appendChild(fieldset);
        index++;
    });
})();
</script>
@endsection
