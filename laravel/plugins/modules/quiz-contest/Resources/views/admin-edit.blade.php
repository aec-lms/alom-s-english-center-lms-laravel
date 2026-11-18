@extends('layouts.app')

@section('title', 'Edit Quiz Contest')

@section('content')
<div class="page-header"><div><a href="{{ route('plugins.quiz-contest.admin.index') }}">← সব প্রতিযোগিতা</a><h1>প্রতিযোগিতা সম্পাদনা</h1><p class="muted">শুরু হওয়ার আগে এবং কোনো অংশগ্রহণ জমা পড়ার আগে সম্পাদনা করা যাবে।</p></div></div>
<div class="panel">
    <form method="POST" action="{{ route('plugins.quiz-contest.admin.update', $record->id) }}" id="quiz-contest-edit-form">
        @csrf
        @method('PUT')
        <div class="form-grid">
            <label>শিরোনাম<input name="title" maxlength="180" required value="{{ old('title', $record->title) }}"></label>
            <label>পুরস্কার<input name="prize_description" maxlength="500" required value="{{ old('prize_description', $record->prize_description) }}"></label>
            <label>বিজয়ীর সংখ্যা<input type="number" name="winner_count" min="1" max="100" required value="{{ old('winner_count', $record->winner_count) }}"></label>
            <label>শুরুর সময়<input type="datetime-local" name="starts_at" required value="{{ old('starts_at', $record->starts_at->format('Y-m-d\\TH:i')) }}"></label>
            <label>শেষ সময়<input type="datetime-local" name="ends_at" required value="{{ old('ends_at', $record->ends_at->format('Y-m-d\\TH:i')) }}"></label>
        </div>
        <label>বিবরণ<textarea name="description" rows="2" maxlength="5000">{{ old('description', $record->description) }}</textarea></label>
        <div id="contest-questions">
            <h3>প্রশ্ন</h3>
            @foreach($record->questions as $position => $question)
                <fieldset class="panel contest-question">
                    <legend>প্রশ্ন {{ $position + 1 }}</legend>
                    <label>প্রশ্ন<input name="questions[{{ $position }}][question]" required maxlength="2000" value="{{ old("questions.$position.question", $question->question) }}"></label>
                    <div class="form-grid">
                        @for($i=0;$i<4;$i++)
                            <label>Option {{ $i+1 }}<input name="questions[{{ $position }}][options][{{ $i }}]" required maxlength="300" value="{{ old("questions.$position.options.$i", $question->options[$i] ?? '') }}"></label>
                        @endfor
                    </div>
                    <label>সঠিক Option<select name="questions[{{ $position }}][correct_option]" required>
                        @for($i=0;$i<4;$i++)<option value="{{ $i }}" @selected((int) old("questions.$position.correct_option", $question->correct_option) === $i)>Option {{ $i+1 }}</option>@endfor
                    </select></label>
                    @if($position > 0)<button type="button" class="btn secondary remove-question">Remove</button>@endif
                </fieldset>
            @endforeach
        </div>
        <button class="btn secondary" type="button" id="add-contest-question">+ প্রশ্ন যোগ করুন</button>
        <button class="btn primary" type="submit">পরিবর্তন সংরক্ষণ</button>
    </form>
</div>
<script>
(() => {
    const list = document.getElementById('contest-questions');
    const add = document.getElementById('add-contest-question');
    if (!list || !add) return;
    let index = list.querySelectorAll('.contest-question').length;
    const bindRemove = (fieldset) => fieldset.querySelector('.remove-question')?.addEventListener('click', () => fieldset.remove());
    list.querySelectorAll('.contest-question').forEach(bindRemove);
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
        bindRemove(fieldset);
        list.appendChild(fieldset);
        index++;
    });
})();
</script>
@endsection
