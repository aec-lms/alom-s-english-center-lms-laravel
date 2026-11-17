<?php

declare(strict_types=1);

namespace Plugins\Modules\CourseManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Plugins\Modules\CourseManagement\Models\Enrollment;
use Plugins\Modules\CourseManagement\Models\LiveClass;
use Plugins\Modules\CourseManagement\Models\LiveClassParticipant;
use Illuminate\View\View;

class LiveClassAccessController extends Controller
{
    public function join(Request $request, LiveClass $liveClass): View
    {
        $student = $request->user();
        abort_unless($student && $student->hasRole('student'), 403, 'Only enrolled students can join a live class.');

        $course = $liveClass->course()->firstOrFail();
        abort_unless(
            Enrollment::query()->where('user_id', $student->id)
                ->where('course_id', $course->id)
                ->where('status', 'active')
                ->exists(),
            403,
            'An active course enrollment is required to join this live class.'
        );

        if ($course->branch_id !== null && (int) $student->branch_id !== (int) $course->branch_id) {
            abort(403, 'This live class is outside your assigned branch.');
        }
        abort_unless(in_array($liveClass->status, ['upcoming', 'live'], true), 403, 'This live class is not available.');
        abort_if($liveClass->starts_at && now()->lt($liveClass->starts_at->copy()->subMinutes(15)), 403, 'Live class access opens 15 minutes before the scheduled start.');

        $participant = DB::transaction(function () use ($liveClass, $student): LiveClassParticipant {
            $lockedClass = LiveClass::query()->whereKey($liveClass->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($lockedClass->status, ['upcoming', 'live'], true), 403, 'This live class is not available.');

            $now = now();
            $cutoff = $now->copy()->subMinutes(2);
            $participant = LiveClassParticipant::query()
                ->where('live_class_id', $lockedClass->id)
                ->where('user_id', $student->id)
                ->lockForUpdate()
                ->first();

            if ($participant && $participant->left_at === null && $participant->last_seen_at && $participant->last_seen_at->greaterThanOrEqualTo($cutoff)) {
                $participant->update(['last_seen_at' => $now]);
                return $participant->fresh();
            }

            Event::dispatch('lms.live_class.joined', [$student, $liveClass]);

        $activeCount = LiveClassParticipant::query()
                ->where('live_class_id', $lockedClass->id)
                ->whereNull('left_at')
                ->where('last_seen_at', '>=', $cutoff)
                ->count();

            abort_if($activeCount >= max(1, (int) $lockedClass->capacity), 429, 'This live class has reached its LMS join capacity. Please try again shortly.');

            if ($participant) {
                $participant->update(['joined_at' => $now, 'last_seen_at' => $now, 'left_at' => null]);
                return $participant->fresh();
            }

            return LiveClassParticipant::query()->create([
                'live_class_id' => $lockedClass->id,
                'user_id' => $student->id,
                'joined_at' => $now,
                'last_seen_at' => $now,
                'left_at' => null,
            ]);
        });

        $activeCount = LiveClassParticipant::query()
            ->where('live_class_id', $liveClass->id)
            ->whereNull('left_at')
            ->where('last_seen_at', '>=', now()->subMinutes(2))
            ->count();

        return view('course-management::live-class-join', [
            'liveClass' => $liveClass,
            'participant' => $participant,
            'activeCount' => $activeCount,
            'heartbeatUrl' => route('plugins.live-classes.heartbeat', $liveClass),
            'leaveUrl' => route('plugins.live-classes.leave', $liveClass),
        ]);
    }

    public function heartbeat(Request $request, LiveClass $liveClass): JsonResponse
    {
        $student = $request->user();
        abort_unless($student && $student->hasRole('student'), 403);
        $participant = LiveClassParticipant::query()
            ->where('live_class_id', $liveClass->id)
            ->where('user_id', $student->id)
            ->whereNull('left_at')
            ->firstOrFail();
        $participant->update(['last_seen_at' => now()]);
        $activeCount = LiveClassParticipant::query()
            ->where('live_class_id', $liveClass->id)
            ->whereNull('left_at')
            ->where('last_seen_at', '>=', now()->subMinutes(2))
            ->count();

        return response()->json(['active' => true, 'active_count' => $activeCount, 'last_seen_at' => now()->toIso8601String()]);
    }

    public function leave(Request $request, LiveClass $liveClass): JsonResponse
    {
        $student = $request->user();
        abort_unless($student && $student->hasRole('student'), 403);
        LiveClassParticipant::query()
            ->where('live_class_id', $liveClass->id)
            ->where('user_id', $student->id)
            ->whereNull('left_at')
            ->update(['left_at' => now(), 'last_seen_at' => now()]);

        return response()->json(['left' => true]);
    }
}
