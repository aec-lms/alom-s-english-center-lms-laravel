<?php

declare(strict_types=1);

namespace Plugins\Modules\QuestionBank\Http\Controllers;

use App\Core\Plugins\PluginManager;
use App\Core\Security\AuditLogger;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Plugins\Modules\QuestionBank\Models\Question;
use Plugins\Modules\QuestionBank\Models\QuestionPaper;
use Plugins\Modules\QuestionBank\Models\QuestionPaperItem;

class QuestionBankController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): View
    {
        $this->ensureBranchAccess($request->user());
        $filters = $request->validate([
            'exam_category' => ['nullable', 'in:bcs,bank_job,admission'],
            'question_type' => ['nullable', 'in:mcq,written'],
            'subject' => ['nullable', 'string', 'max:120'],
            'difficulty' => ['nullable', 'in:easy,medium,hard'],
            'search' => ['nullable', 'string', 'max:150'],
        ]);

        $questions = $this->scopedQuestions($request->user())
            ->when($filters['exam_category'] ?? null, fn ($q, $v) => $q->where('exam_category', $v))
            ->when($filters['question_type'] ?? null, fn ($q, $v) => $q->where('question_type', $v))
            ->when($filters['subject'] ?? null, fn ($q, $v) => $q->where('subject', $v))
            ->when($filters['difficulty'] ?? null, fn ($q, $v) => $q->where('difficulty', $v))
            ->when($filters['search'] ?? null, fn ($q, $v) => $q->where('prompt', 'like', '%' . $v . '%'))
            ->latest()->paginate(20)->withQueryString();

        $papers = QuestionPaper::query()
            ->when($request->user()?->hasRole('branch_manager'), fn ($q) => $q->where('branch_id', $request->user()->branch_id))
            ->latest()->limit(10)->get();

        return view('question-bank::index', compact('questions', 'papers', 'filters'));
    }

    public function storeQuestion(Request $request): RedirectResponse
    {
        $actor = $request->user();
        $this->ensureBranchAccess($actor);
        $data = $this->validatedQuestion($request);
        $data['branch_id'] = $actor?->hasRole('branch_manager') ? (int) $actor->branch_id : ($data['branch_id'] ?? null);
        $data['created_by'] = $actor?->id;
        $question = Question::query()->create($data);
        $this->audit->log('question_bank.question_created', 'question_bank_question', (string) $question->id, ['category' => $question->exam_category, 'type' => $question->question_type]);

        return back()->with('status', 'Question saved to the question bank.');
    }

    public function updateQuestion(Request $request, Question $question): RedirectResponse
    {
        $this->ensureQuestionBranch($request->user(), $question);
        $data = $this->validatedQuestion($request);
        unset($data['branch_id']);
        $question->update($data);
        $this->audit->log('question_bank.question_updated', 'question_bank_question', (string) $question->id, ['note' => 'Existing paper snapshots remain unchanged.']);

        return back()->with('status', 'Question updated. Existing paper snapshots were not changed.');
    }

    public function deleteQuestion(Request $request, Question $question): RedirectResponse
    {
        $this->ensureQuestionBranch($request->user(), $question);
        $question->delete();
        $this->audit->log('question_bank.question_deleted', 'question_bank_question', (string) $question->id, []);

        return back()->with('status', 'Question moved to the question bank recycle state.');
    }

    public function buildPaper(Request $request): RedirectResponse
    {
        $actor = $request->user();
        $this->ensureBranchAccess($actor);
        $data = $request->validate([
            'exam_category' => ['required', 'in:bcs,bank_job,admission'],
            'title' => ['required', 'string', 'max:180'],
            'instructions' => ['nullable', 'string', 'max:5000'],
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:600'],
            'selection_mode' => ['required', 'in:manual,random'],
            'question_ids' => ['required_if:selection_mode,manual', 'array', 'min:1', 'max:200'],
            'question_ids.*' => ['integer', 'distinct'],
            'random_count' => ['required_if:selection_mode,random', 'integer', 'min:1', 'max:200'],
            'question_type' => ['nullable', 'in:mcq,written'],
            'subject' => ['nullable', 'string', 'max:120'],
            'difficulty' => ['nullable', 'in:easy,medium,hard'],
        ]);

        $query = $this->scopedQuestions($actor)
            ->where('exam_category', $data['exam_category'])
            ->where('is_active', true);

        if (($data['question_type'] ?? null) !== null) $query->where('question_type', $data['question_type']);
        if (($data['subject'] ?? null) !== null) $query->where('subject', $data['subject']);
        if (($data['difficulty'] ?? null) !== null) $query->where('difficulty', $data['difficulty']);

        if ($data['selection_mode'] === 'manual') {
            $ids = array_map('intval', $data['question_ids']);
            $questions = (clone $query)->whereIn('id', $ids)->get();
            abort_unless($questions->count() === count($ids), 422, 'One or more selected questions are unavailable in this category or branch.');
            $order = array_flip($ids);
            $questions = $questions->sortBy(fn ($q) => $order[$q->id])->values();
        } else {
            $questions = $query->inRandomOrder()->limit((int) $data['random_count'])->get();
            abort_if($questions->count() !== (int) $data['random_count'], 422, 'Not enough questions match the selected random filters.');
        }

        $paper = DB::transaction(function () use ($data, $questions, $actor): QuestionPaper {
            $total = $questions->sum(fn ($q) => (float) $q->default_marks);
            $paper = QuestionPaper::query()->create([
                'exam_category' => $data['exam_category'],
                'title' => $data['title'],
                'instructions' => $data['instructions'] ?? null,
                'duration_minutes' => (int) $data['duration_minutes'],
                'total_marks' => $total,
                'selection_mode' => $data['selection_mode'],
                'selection_criteria' => [
                    'question_type' => $data['question_type'] ?? null,
                    'subject' => $data['subject'] ?? null,
                    'difficulty' => $data['difficulty'] ?? null,
                    'requested_count' => $data['selection_mode'] === 'random' ? (int) $data['random_count'] : count($data['question_ids'] ?? []),
                ],
                'branch_id' => $actor?->hasRole('branch_manager') ? (int) $actor->branch_id : null,
                'created_by' => $actor?->id,
            ]);

            foreach ($questions as $index => $question) {
                QuestionPaperItem::query()->create([
                    'paper_id' => $paper->id,
                    'question_id' => $question->id,
                    'position' => $index + 1,
                    'question_snapshot' => [
                        'exam_category' => $question->exam_category,
                        'question_type' => $question->question_type,
                        'subject' => $question->subject,
                        'topic' => $question->topic,
                        'difficulty' => $question->difficulty,
                        'prompt' => $question->prompt,
                        'options' => $question->options,
                        'correct_answer' => $question->correct_answer,
                        'explanation' => $question->explanation,
                        'tags' => $question->tags,
                    ],
                    'marks' => $question->default_marks,
                    'negative_marks' => $question->default_negative_marks,
                ]);
            }

            return $paper;
        });

        $this->audit->log('question_bank.paper_created', 'question_bank_paper', (string) $paper->id, ['question_count' => $questions->count(), 'selection_mode' => $data['selection_mode']]);

        return redirect()->route('plugins.question-bank.papers.show', $paper);
    }

    public function showPaper(Request $request, QuestionPaper $paper): View
    {
        $this->ensurePaperBranch($request->user(), $paper);
        $paper->load('items');

        return view('question-bank::paper', compact('paper'));
    }

    public function printPaper(Request $request, QuestionPaper $paper): View
    {
        $this->ensurePaperBranch($request->user(), $paper);
        $paper->load('items');

        return view('question-bank::print', ['paper' => $paper, 'answerKey' => false]);
    }

    public function printAnswerKey(Request $request, QuestionPaper $paper): View
    {
        $this->ensurePaperBranch($request->user(), $paper);
        $paper->load('items');

        return view('question-bank::print', ['paper' => $paper, 'answerKey' => true]);
    }

    private function validatedQuestion(Request $request): array
    {
        $data = $request->validate([
            'exam_category' => ['required', 'in:bcs,bank_job,admission'],
            'question_type' => ['required', 'in:mcq,written'],
            'subject' => ['required', 'string', 'max:120'],
            'topic' => ['nullable', 'string', 'max:160'],
            'difficulty' => ['required', 'in:easy,medium,hard'],
            'prompt' => ['required', 'string', 'max:20000'],
            'options' => ['nullable', 'array', 'min:2', 'max:8'],
            'options.*' => ['required', 'string', 'max:1000'],
            'correct_answer' => ['nullable', 'string', 'max:10000'],
            'explanation' => ['nullable', 'string', 'max:10000'],
            'default_marks' => ['required', 'numeric', 'gt:0', 'max:1000'],
            'default_negative_marks' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'tags' => ['nullable', 'array', 'max:30'],
            'tags.*' => ['string', 'max:60'],
            'branch_id' => ['nullable', 'integer'],
        ]);

        if ($data['question_type'] === 'mcq') {
            abort_unless(count($data['options'] ?? []) >= 2, 422, 'MCQ questions require at least two options.');
            abort_unless(trim((string) ($data['correct_answer'] ?? '')) !== '', 422, 'MCQ questions require a correct answer.');
        } else {
            $data['options'] = null;
        }

        return $data;
    }

    private function scopedQuestions($actor)
    {
        return Question::query()->when(
            $actor && $actor->hasRole('branch_manager'),
            fn ($q) => $q->where('branch_id', (int) $actor->branch_id)
        );
    }

    private function ensureBranchAccess($actor): void
    {
        if (!$actor || !$actor->hasRole('branch_manager')) return;
        abort_unless(
            $actor->branch_id
            && app(PluginManager::class)->isEnabled('branch-management')
            && \Illuminate\Support\Facades\Schema::hasTable('branches')
            && DB::table('branches')->where('id', $actor->branch_id)->where('is_active', true)->exists(),
            403,
            'Branch manager must be assigned to an active branch.'
        );
    }

    private function ensureQuestionBranch($actor, Question $question): void
    {
        $this->ensureBranchAccess($actor);
        if ($actor?->hasRole('branch_manager')) abort_unless((int) $question->branch_id === (int) $actor->branch_id, 403, 'Branch isolation violation.');
    }

    private function ensurePaperBranch($actor, QuestionPaper $paper): void
    {
        $this->ensureBranchAccess($actor);
        if ($actor?->hasRole('branch_manager')) abort_unless((int) $paper->branch_id === (int) $actor->branch_id, 403, 'Branch isolation violation.');
    }
}
