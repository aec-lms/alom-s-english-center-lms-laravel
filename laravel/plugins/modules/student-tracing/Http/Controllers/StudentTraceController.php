<?php

declare(strict_types=1);

namespace Plugins\Modules\StudentTracing\Http\Controllers;

use App\Core\Plugins\PluginManager;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StudentTraceController extends Controller
{
    public function index(Request $request): View
    {
        $validated = $request->validate([
            'student_id' => ['nullable', 'integer', 'min:1'],
            'event_type' => ['nullable', 'in:student.created,student.updated,student.deleted,attendance.created,attendance.updated,attendance.deleted,enrollment.created,enrollment.updated,enrollment.deleted,exam_attempt.created,exam_attempt.updated,exam_attempt.deleted'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);

        $events = collect();
        $total = 0;
        if (Schema::hasTable('student_activity_events')) {
            $query = DB::table('student_activity_events')
                ->when($actor && $actor->hasRole('branch_manager'), fn ($q) => $q->where('branch_id', (int) $actor->branch_id))
                ->when($request->filled('student_id'), fn ($q) => $q->where('student_id', (int) $request->input('student_id')))
                ->when($request->filled('event_type'), fn ($q) => $q->where('event_type', $request->string('event_type')->toString()))
                ->when($request->filled('from'), fn ($q) => $q->whereDate('occurred_at', '>=', $request->input('from')))
                ->when($request->filled('to'), fn ($q) => $q->whereDate('occurred_at', '<=', $request->input('to')))
                ->orderByDesc('occurred_at');
            $total = (clone $query)->count();
            $events = $query->paginate(30)->withQueryString();
        }

        return view('student-tracing::index', compact('events', 'total'));
    }

    public function export(Request $request): StreamedResponse
    {
        abort_unless(Schema::hasTable('student_activity_events'), 404);
        $validated = $request->validate([
            'student_id' => ['nullable', 'integer', 'min:1'],
            'event_type' => ['nullable', 'in:student.created,student.updated,student.deleted,attendance.created,attendance.updated,attendance.deleted,enrollment.created,enrollment.updated,enrollment.deleted,exam_attempt.created,exam_attempt.updated,exam_attempt.deleted'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);

        $query = DB::table('student_activity_events')
            ->when($actor && $actor->hasRole('branch_manager'), fn ($q) => $q->where('branch_id', (int) $actor->branch_id))
            ->when($request->filled('student_id'), fn ($q) => $q->where('student_id', (int) $request->input('student_id')))
            ->when($request->filled('event_type'), fn ($q) => $q->where('event_type', $validated['event_type']))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('occurred_at', '>=', $validated['from']))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('occurred_at', '<=', $validated['to']))
            ->orderByDesc('occurred_at')
            ->limit(5000);
        $events = $query->get();

        return response()->streamDownload(function () use ($events): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Event ID', 'Student ID', 'Branch ID', 'Actor ID', 'Event', 'Changed Fields', 'Occurred At']);
            foreach ($events as $event) {
                $metadata = json_decode((string) $event->metadata, true) ?: [];
                fputcsv($handle, [
                    $event->id,
                    $event->student_id,
                    $event->branch_id,
                    $event->actor_id,
                    $event->event_type,
                    implode(', ', $metadata['changed_fields'] ?? []),
                    $event->occurred_at,
                ]);
            }
            fclose($handle);
        }, 'aec-lms-student-trace.csv', ['Content-Type' => 'text/csv']);
    }

    private function ensureAssignedBranch($actor): void
    {
        if (!$actor || !$actor->hasRole('branch_manager')) {
            return;
        }
        if ($actor->branch_id === null
            || !app(PluginManager::class)->isEnabled('branch-management')
            || !Schema::hasTable('branches')
            || !DB::table('branches')->where('id', $actor->branch_id)->where('is_active', true)->exists()) {
            abort(403, 'Branch manager must be assigned to an active branch.');
        }
    }
}
