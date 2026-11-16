<?php

declare(strict_types=1);

namespace Plugins\Modules\StudentTracking\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Plugins\Modules\StudentTracking\Models\StudentActivityEvent;
use Plugins\Modules\StudentTracking\Services\StudentActivityTracker;

class StudentTrackingController extends Controller
{
    public function index(Request $request): View
    {
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);

        $events = StudentActivityEvent::query()
            ->when($request->filled('student_id'), fn ($query) => $query->where('user_id', (int) $request->integer('student_id')))
            ->when($request->filled('event_type'), fn ($query) => $query->where('event_type', $request->string('event_type')->toString()))
            ->when($actor->hasRole('branch_manager'), function ($query) use ($actor): void {
                $query->whereIn('user_id', fn ($subquery) => $subquery->select('id')->from('users')->where('branch_id', $actor->branch_id));
            })
            ->latest('occurred_at')
            ->paginate(40)
            ->withQueryString();

        $summaryQuery = StudentActivityEvent::query()
            ->when($actor->hasRole('branch_manager'), function ($query) use ($actor): void {
                $query->whereIn('user_id', fn ($subquery) => $subquery->select('id')->from('users')->where('branch_id', $actor->branch_id));
            });

        $summary = [
            'active_students' => (clone $summaryQuery)->where('occurred_at', '>=', now()->subDays(7))->distinct('user_id')->count('user_id'),
            'events_today' => (clone $summaryQuery)->whereDate('occurred_at', today())->count(),
            'lessons_completed' => (clone $summaryQuery)->where('event_type', 'lesson_completed')->where('occurred_at', '>=', now()->subDays(30))->count(),
            'quiz_submissions' => (clone $summaryQuery)->where('event_type', 'quiz_submitted')->where('occurred_at', '>=', now()->subDays(30))->count(),
        ];

        return view('student-tracking::index', compact('events', 'summary'));
    }

    public function mine(Request $request): View
    {
        $events = StudentActivityEvent::query()->where('user_id', $request->user()->id)->latest('occurred_at')->paginate(30);
        return view('student-tracking::mine', compact('events'));
    }

    public function record(Request $request, StudentActivityTracker $tracker): JsonResponse
    {
        abort_unless($request->user()->hasRole('student'), 403, 'Only student accounts can submit their own learning activity.');
        $validated = $request->validate([
            'event_type' => ['required', 'string', 'in:lesson_completed'],
            'subject_type' => ['nullable', 'string', 'max:60'],
            'subject_id' => ['nullable', 'string', 'max:100'],
            'duration_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'metadata' => ['nullable', 'array'],
        ]);

        $event = $tracker->record(
            (int) $request->user()->id,
            $validated['event_type'],
            $validated['subject_type'] ?? null,
            $validated['subject_id'] ?? null,
            $validated['duration_seconds'] ?? null,
            $validated['metadata'] ?? [],
        );

        return response()->json(['recorded' => true, 'event_id' => $event->id], 201);
    }

    private function ensureAssignedBranch($actor): void
    {
        if (!$actor || !$actor->hasRole('branch_manager')) {
            return;
        }
        if ($actor->branch_id === null || !Schema::hasTable('branches')
            || !app('db')->table('branches')->where('id', $actor->branch_id)->where('is_active', true)->exists()) {
            abort(403, 'Branch manager must be assigned to an active branch.');
        }
    }
}
