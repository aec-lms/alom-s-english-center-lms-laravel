<?php

declare(strict_types=1);

namespace Plugins\Modules\QuizContest\Http\Controllers;

use App\Core\Plugins\PluginManager;
use App\Core\Security\AuditLogger;
use App\Http\Controllers\Controller;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Plugins\Modules\QuizContest\Models\QuizContest;
use Plugins\Modules\QuizContest\Models\QuizContestEntry;
use Plugins\Modules\QuizContest\Models\QuizContestWinner;

class QuizContestController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): View
    {
        $contests = $this->visibleContestQuery($request->user())
            ->whereIn('status', ['published', 'closed'])
            ->withCount(['entries', 'questions'])
            ->with('winners.user')
            ->latest()
            ->paginate(12);

        return view('quiz-contest::index', compact('contests'));
    }

    public function show(Request $request, int $contest): View
    {
        $record = $this->visibleContestQuery($request->user())
            ->whereIn('status', ['published', 'closed'])
            ->with(['winners.user'])
            ->withCount('entries')
            ->findOrFail($contest);

        $entry = $request->user()
            ? $record->entries()->where('user_id', $request->user()->id)->first()
            : null;
        $answerReviewOpen = $record->drawn_at !== null || now()->greaterThanOrEqualTo($record->ends_at);

        $record->setRelation('questions', $record->questions()
            ->get(['id', 'quiz_contest_id', 'question', 'options', 'position'] + ($answerReviewOpen ? ['correct_option'] : [])));

        return view('quiz-contest::show', compact('record', 'entry', 'answerReviewOpen'));
    }

    public function submit(Request $request, int $contest): RedirectResponse
    {
        $student = $request->user();
        abort_unless($student && $student->hasRole('student'), 403);
        $validated = $request->validate([
            'answers' => ['required', 'array', 'min:1', 'max:50'],
            'answers.*' => ['required', 'integer', 'between:0,3'],
        ]);

        try {
            DB::transaction(function () use ($contest, $student, $validated): void {
                $record = $this->visibleContestQuery($student)->lockForUpdate()->findOrFail($contest);
                abort_unless($record->status === 'published'
                    && now()->between($record->starts_at, $record->ends_at), 422, 'This quiz is not accepting answers now.');

                $questions = $record->questions()->get();
                $expectedIds = $questions->pluck('id')->map(fn ($id) => (string) $id)->sort()->values();
                $submittedIds = collect(array_keys($validated['answers']))->map(fn ($id) => (string) $id)->sort()->values();
                abort_unless($expectedIds->isNotEmpty() && $expectedIds->all() === $submittedIds->all(), 422, 'Please answer every question in this quiz.');

                abort_if($record->entries()->where('user_id', $student->id)->exists(), 409, 'You have already participated in this contest.');

                $score = $questions->sum(fn ($question): int =>
                    (int) ((int) $validated['answers'][$question->id] === (int) $question->correct_option)
                );

                $entry = QuizContestEntry::query()->create([
                    'quiz_contest_id' => $record->id,
                    'user_id' => $student->id,
                    'answers' => $validated['answers'],
                    'score' => $score,
                    'submitted_at' => now(),
                ]);
                $this->audit->log('quiz_contest.entry_submitted', 'quiz_contest_entry', (string) $entry->id, ['score' => $score], $student->id);
            });
        } catch (QueryException $exception) {
            // The unique (contest, user) index is the final guard against concurrent duplicate entries.
            if (str_contains(strtolower($exception->getMessage()), 'unique') || str_contains(strtolower($exception->getMessage()), 'duplicate entry')) {
                abort(409, 'You have already participated in this contest.');
            }
            throw $exception;
        }

        return redirect()->route('plugins.quiz-contest.show', $contest)->with('status', 'আপনার উত্তর জমা হয়েছে। ফলাফল স্কোর ও জমা দেওয়ার সময় অনুযায়ী নির্ধারিত হবে।');
    }

    public function adminIndex(Request $request): View
    {
        $actor = $request->user();
        $this->authorizeContestManager($actor);
        $contests = QuizContest::query()
            ->when($actor->hasRole('branch_manager'), fn ($q) => $q->where('branch_id', (int) $actor->branch_id))
            ->withCount(['entries', 'questions'])
            ->latest()
            ->paginate(20);

        return view('quiz-contest::admin-index', compact('contests'));
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $request->user();
        $this->authorizeContestManager($actor);
        $data = $request->validate([
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'title' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:5000'],
            'prize_description' => ['required', 'string', 'max:500'],
            'winner_count' => ['required', 'integer', 'min:1', 'max:100'],
            'starts_at' => ['required', 'date', 'after:now'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'questions' => ['required', 'array', 'min:1', 'max:50'],
            'questions.*.question' => ['required', 'string', 'max:2000'],
            'questions.*.options' => ['required', 'array', 'size:4'],
            'questions.*.options.*' => ['required', 'string', 'max:300'],
            'questions.*.correct_option' => ['required', 'integer', 'between:0,3'],
        ]);

        $branchId = $this->resolvedBranchId($actor, isset($data['branch_id']) ? (int) $data['branch_id'] : null);
        $contest = DB::transaction(function () use ($actor, $data, $branchId): QuizContest {
            $contest = QuizContest::query()->create([
                'created_by' => $actor->id,
                'branch_id' => $branchId,
                'title' => trim($data['title']),
                'description' => $data['description'] ?? null,
                'prize_description' => trim($data['prize_description']),
                'winner_count' => $data['winner_count'],
                'starts_at' => $data['starts_at'],
                'ends_at' => $data['ends_at'],
                'status' => 'published',
            ]);

            foreach ($data['questions'] as $position => $question) {
                $contest->questions()->create([
                    'question' => trim($question['question']),
                    'options' => array_values($question['options']),
                    'correct_option' => $question['correct_option'],
                    'position' => $position + 1,
                ]);
            }
            return $contest;
        });

        $this->audit->log('quiz_contest.created', 'quiz_contest', (string) $contest->id, ['branch_id' => $branchId], $actor->id);

        return redirect()->route('plugins.quiz-contest.admin.index')->with('status', 'Quiz contest প্রকাশ করা হয়েছে।');
    }

    public function adminEntries(Request $request, int $contest): View
    {
        $actor = $request->user();
        $this->authorizeContestManager($actor);
        $record = $this->staffContestQuery($actor)->with('questions')->findOrFail($contest);
        $entries = $record->entries()->with('user:id,name,email')
            ->orderByDesc('score')->orderBy('submitted_at')->orderBy('id')
            ->paginate(50);

        return view('quiz-contest::admin-entries', compact('record', 'entries'));
    }

    public function edit(Request $request, int $contest): View
    {
        $actor = $request->user();
        $this->authorizeContestManager($actor);
        $record = $this->staffContestQuery($actor)->with('questions')->findOrFail($contest);
        abort_if(
            $record->drawn_at !== null
                || $record->starts_at->lessThanOrEqualTo(now())
                || $record->entries()->exists(),
            409,
            'A contest can only be edited before it starts and before any entries are submitted.'
        );

        return view('quiz-contest::admin-edit', compact('record'));
    }

    public function update(Request $request, int $contest): RedirectResponse
    {
        $actor = $request->user();
        $this->authorizeContestManager($actor);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:5000'],
            'prize_description' => ['required', 'string', 'max:500'],
            'winner_count' => ['required', 'integer', 'min:1', 'max:100'],
            'starts_at' => ['required', 'date', 'after:now'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'questions' => ['required', 'array', 'min:1', 'max:50'],
            'questions.*.question' => ['required', 'string', 'max:2000'],
            'questions.*.options' => ['required', 'array', 'size:4'],
            'questions.*.options.*' => ['required', 'string', 'max:300'],
            'questions.*.correct_option' => ['required', 'integer', 'between:0,3'],
        ]);

        DB::transaction(function () use ($actor, $contest, $data): void {
            $record = $this->staffContestQuery($actor)->lockForUpdate()->findOrFail($contest);
            abort_if(
                $record->drawn_at !== null
                    || $record->starts_at->lessThanOrEqualTo(now())
                    || $record->entries()->exists()
                    || $record->status !== 'published',
                409,
                'A contest can only be edited before it starts and before any entries are submitted.'
            );

            $record->update([
                'title' => trim($data['title']),
                'description' => $data['description'] ?? null,
                'prize_description' => trim($data['prize_description']),
                'winner_count' => $data['winner_count'],
                'starts_at' => $data['starts_at'],
                'ends_at' => $data['ends_at'],
            ]);
            $record->questions()->delete();
            foreach ($data['questions'] as $position => $question) {
                $record->questions()->create([
                    'question' => trim($question['question']),
                    'options' => array_values($question['options']),
                    'correct_option' => $question['correct_option'],
                    'position' => $position + 1,
                ]);
            }
        });

        $this->audit->log('quiz_contest.updated', 'quiz_contest', (string) $contest, [], $actor->id);

        return redirect()->route('plugins.quiz-contest.admin.index')->with('status', 'প্রতিযোগিতার তথ্য আপডেট হয়েছে।');
    }

    public function updateStatus(Request $request, int $contest): RedirectResponse
    {
        $actor = $request->user();
        $this->authorizeContestManager($actor);
        $data = $request->validate(['status' => ['required', 'in:published,paused,closed']]);

        DB::transaction(function () use ($actor, $contest, $data): void {
            $record = $this->staffContestQuery($actor)->lockForUpdate()->findOrFail($contest);
            abort_if($record->drawn_at !== null, 409, 'Published contest results cannot be changed.');
            $next = $data['status'];

            if ($next === 'paused') {
                abort_unless(
                    $record->status === 'published'
                        && now()->greaterThanOrEqualTo($record->starts_at)
                        && now()->lessThan($record->ends_at),
                    422,
                    'Only an active contest can be paused.'
                );
            } elseif ($next === 'published') {
                abort_unless(
                    $record->status === 'paused'
                        && now()->lessThan($record->ends_at),
                    422,
                    'Only a paused contest before its deadline can be resumed.'
                );
            } else {
                abort_unless(
                    in_array($record->status, ['published', 'paused'], true)
                        && now()->greaterThanOrEqualTo($record->ends_at),
                    422,
                    'A contest can only be closed after its scheduled end time.'
                );
            }

            $record->update(['status' => $next]);
        });

        $this->audit->log('quiz_contest.status_changed', 'quiz_contest', (string) $contest, ['status' => $data['status']], $actor->id);

        return back()->with('status', 'প্রতিযোগিতার অবস্থা আপডেট হয়েছে।');
    }

    public function publishResults(Request $request, int $contest): RedirectResponse
    {
        $actor = $request->user();
        $this->authorizeContestManager($actor);
        $winnerCount = DB::transaction(function () use ($actor, $contest): int {
            $record = $this->staffContestQuery($actor)->lockForUpdate()->findOrFail($contest);
            abort_if($record->drawn_at !== null, 409, 'Contest results have already been published.');
            abort_unless(in_array($record->status, ['published', 'paused', 'closed'], true)
                && now()->greaterThanOrEqualTo($record->ends_at), 422, 'The contest must end before results can be published.');

            $entries = $record->entries()->orderByDesc('score')->orderBy('submitted_at')->orderBy('id')->get();
            abort_if($entries->isEmpty(), 422, 'There are no quiz submissions to rank.');
            $selected = $entries->take(min($record->winner_count, $entries->count()));

            foreach ($selected as $index => $entry) {
                QuizContestWinner::query()->create([
                    'quiz_contest_id' => $record->id,
                    'quiz_contest_entry_id' => $entry->id,
                    'user_id' => $entry->user_id,
                    'rank' => $index + 1,
                    'prize_snapshot' => $record->prize_description,
                    'drawn_by' => $actor->id,
                    'drawn_at' => now(),
                ]);
            }

            $record->update(['status' => 'closed', 'drawn_at' => now()]);
            return count($selected);
        });

        $this->audit->log('quiz_contest.results_published', 'quiz_contest', (string) $contest, ['winner_count' => $winnerCount], $actor->id);

        return back()->with('status', $winnerCount . ' জন বিজয়ীর ফলাফল প্রকাশ করা হয়েছে।');
    }

    private function visibleContestQuery($actor)
    {
        return QuizContest::query()->where(function ($query) use ($actor): void {
            $query->whereNull('branch_id');
            if ($actor && $actor->branch_id !== null) {
                $query->orWhere('branch_id', (int) $actor->branch_id);
            }
        });
    }

    private function staffContestQuery($actor)
    {
        return QuizContest::query()->when(
            $actor->hasRole('branch_manager'),
            fn ($q) => $q->where('branch_id', (int) $actor->branch_id)
        );
    }

    private function authorizeContestManager($actor): void
    {
        abort_unless($actor && (
            $actor->hasRole('admin')
            || ($actor->hasRole('branch_manager') && $actor->hasPermission('quiz.contests.manage'))
        ), 403);

        if ($actor->hasRole('branch_manager')) {
            abort_unless(
                (int) ($actor->branch_id ?? 0) > 0
                && app(PluginManager::class)->isEnabled('branch-management')
                && Schema::hasTable('branches')
                && DB::table('branches')->where('id', $actor->branch_id)->where('is_active', true)->exists(),
                403,
                'Branch manager must be assigned to an active branch.'
            );
        }
    }

    private function resolvedBranchId($actor, ?int $requested): ?int
    {
        if ($actor->hasRole('branch_manager')) {
            return (int) $actor->branch_id;
        }
        if ($requested === null) {
            return null;
        }
        abort_unless(
            app(PluginManager::class)->isEnabled('branch-management')
            && Schema::hasTable('branches')
            && DB::table('branches')->where('id', $requested)->where('is_active', true)->exists(),
            422,
            'Select an active branch.'
        );
        return $requested;
    }
}
