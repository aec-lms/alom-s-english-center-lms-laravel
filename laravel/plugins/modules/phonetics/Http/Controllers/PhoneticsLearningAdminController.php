<?php

declare(strict_types=1);

namespace Plugins\Modules\Phonetics\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Plugins\Modules\Phonetics\Models\PhoneticEntry;
use Plugins\Modules\Phonetics\Models\PhoneticExam;
use Plugins\Modules\Phonetics\Models\PhoneticExamQuestion;
use Plugins\Modules\Phonetics\Models\PhoneticLearningAssignment;
use Plugins\Modules\Phonetics\Services\CourseCatalog;

final class PhoneticsLearningAdminController extends Controller
{
    public function __construct(private readonly CourseCatalog $catalog) {}

    public function index(): View
    {
        return view('phonetics::admin.learning', [
            'categories' => $this->catalog->categories(),
            'courses' => $this->catalog->courses(),
            'batches' => $this->catalog->batches(),
            'words' => PhoneticEntry::query()->where('is_active', true)->orderBy('word')->get(),
            'assignments' => PhoneticLearningAssignment::query()->with('word')->latest()->paginate(20, ['*'], 'assignments'),
            'exams' => PhoneticExam::query()->withCount('questions')->latest()->paginate(20, ['*'], 'exams'),
        ]);
    }

    public function lessons(Request $request)
    {
        $data = $request->validate(['course_ref' => ['required', 'string', 'max:128']]);
        return response()->json($this->catalog->lessons($data['course_ref']));
    }

    public function storeAssignment(Request $request)
    {
        $data = $request->validate([
            'course_ref' => ['nullable', 'string', 'max:128'],
            'course_category_ref' => ['nullable', 'string', 'max:128'],
            'lesson_ref' => ['nullable', 'string', 'max:128'],
            'batch_ref' => ['nullable', 'string', 'max:128'],
            'word_id' => ['required', 'integer', 'exists:alom_english_center_phonetic_entries,id'],
            'audience' => ['required', 'in:both,free,paid'],
            'is_required' => ['nullable', 'boolean'],
            'available_from' => ['nullable', 'date'],
            'available_until' => ['nullable', 'date', 'after_or_equal:available_from'],
        ]);
        abort_if(
            empty($data['course_ref']) && empty($data['course_category_ref']),
            422,
            'Choose a course or a course category for this word assignment.'
        );

        PhoneticLearningAssignment::query()->create([
            ...$data,
            'lms_driver' => $this->catalog->driver(),
            'is_required' => $request->boolean('is_required'),
            'sort_order' => (int) PhoneticLearningAssignment::query()->max('sort_order') + 1,
        ]);

        return back()->with('status', 'Word assigned to the selected course, lesson and batch.');
    }

    public function destroyAssignment(PhoneticLearningAssignment $assignment)
    {
        $assignment->delete();
        return back()->with('status', 'Word assignment removed. The vocabulary word itself was preserved.');
    }

    public function storeExam(Request $request)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:2000'],
            'course_ref' => ['nullable', 'string', 'max:128'],
            'course_category_ref' => ['nullable', 'string', 'max:128'],
            'lesson_ref' => ['nullable', 'string', 'max:128'],
            'batch_ref' => ['nullable', 'string', 'max:128'],
            'audience' => ['required', 'in:both,free,paid'],
            'duration_minutes' => ['required', 'integer', 'min:0', 'max:300'],
            'pass_percentage' => ['required', 'integer', 'min:1', 'max:100'],
            'max_attempts' => ['required', 'integer', 'min:0', 'max:50'],
            'question_count' => ['required', 'integer', 'min:1', 'max:100'],
            'word_ids' => ['required', 'array', 'min:1'],
            'word_ids.*' => ['integer', 'distinct', 'exists:alom_english_center_phonetic_entries,id'],
            'shuffle_questions' => ['nullable', 'boolean'],
            'shuffle_options' => ['nullable', 'boolean'],
            'show_result_immediately' => ['nullable', 'boolean'],
            'status' => ['required', 'in:draft,published'],
        ]);
        abort_if(empty($data['course_ref']) && empty($data['course_category_ref']), 422, 'Choose a course or course category for this exam.');
        abort_if(count($data['word_ids']) < $data['question_count'], 422, 'Select at least as many words as the exam question count.');

        $exam = DB::transaction(function () use ($request, $data): PhoneticExam {
            $slug = Str::slug($data['title']);
            $base = $slug;
            for ($suffix = 2; PhoneticExam::query()->where('slug', $slug)->exists(); $suffix++) {
                $slug = $base.'-'.$suffix;
            }
            $exam = PhoneticExam::query()->create([
                'title' => $data['title'],
                'slug' => $slug,
                'description' => $data['description'] ?? null,
                'lms_driver' => $this->catalog->driver(),
                'course_ref' => $data['course_ref'] ?? null,
                'course_category_ref' => $data['course_category_ref'] ?? null,
                'lesson_ref' => $data['lesson_ref'] ?? null,
                'batch_ref' => $data['batch_ref'] ?? null,
                'audience' => $data['audience'],
                'duration_minutes' => $data['duration_minutes'],
                'pass_percentage' => $data['pass_percentage'],
                'max_attempts' => $data['max_attempts'],
                'question_count' => $data['question_count'],
                'shuffle_questions' => $request->boolean('shuffle_questions'),
                'shuffle_options' => $request->boolean('shuffle_options'),
                'show_result_immediately' => $request->boolean('show_result_immediately'),
                'status' => $data['status'],
            ]);

            $words = PhoneticEntry::query()->whereIn('id', $data['word_ids'])->where('is_active', true)->get()->shuffle()->take($data['question_count']);
            foreach ($words->values() as $index => $word) {
                $accent = random_int(0, 1) === 0 ? 'uk' : 'us';
                $answer = $accent === 'uk' ? (string) $word->uk_ipa : (string) $word->us_ipa;
                $options = [
                    ['value' => (string) $word->uk_ipa, 'label' => 'British English — '.$word->uk_ipa],
                    ['value' => (string) $word->us_ipa, 'label' => 'American English — '.$word->us_ipa],
                ];
                if ($request->boolean('shuffle_options')) {
                    shuffle($options);
                }
                PhoneticExamQuestion::query()->create([
                    'exam_id' => $exam->id,
                    'word_id' => $word->id,
                    'question_type' => 'identify-ipa',
                    'prompt' => 'Choose the '.($accent === 'uk' ? 'British' : 'American').' IPA pronunciation for “'.$word->word.'”.',
                    'options' => $options,
                    'answer_key' => $answer,
                    'marks' => 1,
                    'sort_order' => $index + 1,
                ]);
            }
            return $exam;
        });

        return back()->with('status', 'Exam created with '.$exam->questions()->count().' questions.');
    }

    public function updateExamStatus(Request $request, PhoneticExam $exam)
    {
        $data = $request->validate(['status' => ['required', 'in:draft,published,archived']]);
        $exam->update(['status' => $data['status']]);
        return back()->with('status', 'Exam status updated.');
    }
}
