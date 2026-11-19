<?php

declare(strict_types=1);

namespace Plugins\Modules\CourseManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Plugins\Modules\CourseManagement\Models\Enrollment;
use Plugins\Modules\CourseManagement\Models\LiveClass;
use Plugins\Modules\CourseManagement\Models\LiveClassParticipant;

class LiveClassAccessController extends Controller
{
    public function join(Request $request, LiveClass $liveClass): View
    {
        $student = $request->user();
        abort_unless($student && $student->hasRole('student'), 403, 'Only enrolled students can join a live class.');

        $participant = DB::transaction(function () use ($liveClass, $student): LiveClassParticipant {
            // Lock order: live class -> course -> user -> student profile ->
            // enrollment -> paid entitlement -> participant.
            $lockedClass = LiveClass::query()->whereKey($liveClass->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($lockedClass->status, ['upcoming', 'live'], true), 403, 'This live class is not available.');

            $course = $lockedClass->course()->lockForUpdate()->firstOrFail();
            $this->assertEligibleStudent($student, $course->id, $course->branch_id, (int) $lockedClass->id);
            abort_if(
                $lockedClass->starts_at && now()->lt($lockedClass->starts_at->copy()->subMinutes(15)),
                403,
                'Live class access opens 15 minutes before the scheduled start.'
            );

            $now = now();
            $cutoff = $now->copy()->subMinutes(2);
            $participant = LiveClassParticipant::query()
                ->where('live_class_id', $lockedClass->id)
                ->where('user_id', $student->id)
                ->lockForUpdate()
                ->first();

            // A repeat request from an already-present student refreshes their seat.
            if ($participant && $participant->left_at === null && $participant->last_seen_at && $participant->last_seen_at->greaterThanOrEqualTo($cutoff)) {
                $participant->update(['last_seen_at' => $now]);
                return $participant->fresh();
            }

            $activeCount = LiveClassParticipant::query()
                ->where('live_class_id', $lockedClass->id)
                ->whereNull('left_at')
                ->where('last_seen_at', '>=', $cutoff)
                ->count();

            abort_if(
                $activeCount >= max(1, (int) $lockedClass->capacity),
                429,
                'This live class has reached its LMS join capacity. Please try again shortly.'
            );

            if ($participant) {
                $participant->update(['joined_at' => $now, 'last_seen_at' => $now, 'left_at' => null]);
                $participant = $participant->fresh();
            } else {
                $participant = LiveClassParticipant::query()->create([
                    'live_class_id' => $lockedClass->id,
                    'user_id' => $student->id,
                    'joined_at' => $now,
                    'last_seen_at' => $now,
                    'left_at' => null,
                ]);
            }

            // Record a join only after a seat has actually been granted.
            Event::dispatch('lms.live_class.joined', [$student, $lockedClass]);

            return $participant;
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

        $presence = DB::transaction(function () use ($liveClass, $student): array {
            // Use the same lock order and fresh entitlement checks as join().
            $lockedClass = LiveClass::query()->whereKey($liveClass->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($lockedClass->status, ['upcoming', 'live'], true), 403, 'This live class is not available.');

            $course = $lockedClass->course()->lockForUpdate()->firstOrFail();
            $this->assertEligibleStudent($student, $course->id, $course->branch_id, (int) $lockedClass->id);
            $now = now();

            $participant = LiveClassParticipant::query()
                ->where('live_class_id', $lockedClass->id)
                ->where('user_id', $student->id)
                ->whereNull('left_at')
                ->lockForUpdate()
                ->firstOrFail();

            $cutoff = $now->copy()->subMinutes(2);
            $wasPresent = $participant->last_seen_at && $participant->last_seen_at->greaterThanOrEqualTo($cutoff);

            if (!$wasPresent) {
                $activeCount = LiveClassParticipant::query()
                    ->where('live_class_id', $lockedClass->id)
                    ->whereNull('left_at')
                    ->where('last_seen_at', '>=', $cutoff)
                    ->count();

                abort_if(
                    $activeCount >= max(1, (int) $lockedClass->capacity),
                    429,
                    'This live class has reached its LMS join capacity. Please rejoin when a seat is available.'
                );
            }

            $participant->update(['last_seen_at' => $now]);

            return [
                'active_count' => LiveClassParticipant::query()
                    ->where('live_class_id', $lockedClass->id)
                    ->whereNull('left_at')
                    ->where('last_seen_at', '>=', $cutoff)
                    ->count(),
                'last_seen_at' => $now->toIso8601String(),
            ];
        });

        return response()->json([
            'active' => true,
            'active_count' => $presence['active_count'],
            'last_seen_at' => $presence['last_seen_at'],
        ]);
    }

    public function leave(Request $request, LiveClass $liveClass): JsonResponse
    {
        $student = $request->user();
        abort_unless($student && $student->hasRole('student'), 403);

        // A participant may always release their own seat, including after a
        // course access change or when the live class has just ended.
        LiveClassParticipant::query()
            ->where('live_class_id', $liveClass->id)
            ->where('user_id', $student->id)
            ->whereNull('left_at')
            ->update(['left_at' => now(), 'last_seen_at' => now()]);

        return response()->json(['left' => true]);
    }

    private function assertEligibleStudent(object $student, int $courseId, ?int $courseBranchId, ?int $liveClassId = null): void
    {
        abort_unless($student->hasRole('student'), 403, 'Only active student accounts can access a live class.');

        // Student profile updates write the linked user first and profile second;
        // retain that lock order so branch changes cannot race a seat grant.
        $userQuery = DB::table('users')->where('id', $student->id);
        if (Schema::hasColumn('users', 'is_active')) {
            $userQuery->lockForUpdate();
        }
        $user = $userQuery->first();
        abort_unless($user, 403, 'Student account is unavailable.');
        if (Schema::hasColumn('users', 'is_active')) {
            abort_unless((bool) $user->is_active, 403, 'This student account is inactive.');
        }

        $profile = null;
        $studentManagementEnabled = app(\App\Core\Plugins\PluginManager::class)->isEnabled('student-management')
            && Schema::hasTable('students');
        if ($studentManagementEnabled) {
            $profileQuery = DB::table('students')->where('user_id', $student->id);
            if (Schema::hasColumn('students', 'deleted_at')) {
                $profileQuery->whereNull('deleted_at');
            }
            $profileQuery->lockForUpdate();
            $profile = $profileQuery->first();
            if ($profile && Schema::hasColumn('students', 'status')) {
                abort_unless($profile->status === 'active', 403, 'This student profile is inactive.');
            }
        }

        $assignedBranchId = $profile && $profile->branch_id !== null
            ? (int) $profile->branch_id
            : (Schema::hasColumn('users', 'branch_id') && $user->branch_id !== null
                ? (int) $user->branch_id
                : null);

        // Lock the enrollment row even when it is cancelled. Filtering by status
        // before locking could miss the exact row being changed by an admin.
        $enrollment = Enrollment::query()
            ->where('user_id', $student->id)
            ->where('course_id', $courseId)
            ->lockForUpdate()
            ->first();
        $enrolled = $enrollment !== null && $enrollment->status === 'active';

        $hasPaidPass = false;
        if ($liveClassId !== null && Schema::hasTable('commerce_entitlements')) {
            $at = now()->toDateTimeString();
            $entitlements = DB::table('commerce_entitlements')
                ->where('user_id', $student->id)
                ->where('entitlement_type', 'live_class')
                ->where('resource_id', $liveClassId)
                ->lockForUpdate()
                ->get();

            $hasPaidPass = $entitlements->contains(static fn (object $entitlement): bool =>
                $entitlement->status === 'active'
                && ($entitlement->starts_at === null || $entitlement->starts_at <= $at)
                && ($entitlement->expires_at === null || $entitlement->expires_at > $at)
            );
        }

        abort_unless($enrolled || $hasPaidPass, 403,
            'An active course enrollment or a valid paid live-class pass is required to access this class.');

        if ($courseBranchId !== null && $assignedBranchId !== $courseBranchId) {
            abort(403, 'This live class is outside your assigned branch.');
        }
    }


}
