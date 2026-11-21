<?php

declare(strict_types=1);

namespace Plugins\Modules\LearningPaths\Services;

use App\Core\Plugins\PluginManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LearningPathAccess
{
    public function __construct(private readonly PluginManager $plugins) {}

    /**
     * Resolve the learner's current branch from the active account/profile.
     * Conflicting or inactive assignments fail closed.
     */
    public function resolveStudentBranch(int $userId): ?int
    {
        $account = DB::table('users')->where('id', $userId)->lockForUpdate()->first();
        abort_unless($account, 403, 'Student account is unavailable.');
        if (Schema::hasColumn('users', 'is_active')) {
            abort_unless((bool) $account->is_active, 403, 'Student account is inactive.');
        }

        $accountBranchId = Schema::hasColumn('users', 'branch_id') && $account->branch_id !== null
            ? (int) $account->branch_id
            : null;
        $profileBranchId = null;

        if ($this->plugins->isEnabled('student-management')) {
            abort_unless(Schema::hasTable('students') && Schema::hasColumn('students', 'user_id'),
                403, 'An active student profile is required for learning-path access.');
            $profileQuery = DB::table('students')->where('user_id', $userId);
            if (Schema::hasColumn('students', 'deleted_at')) {
                $profileQuery->whereNull('deleted_at');
            }
            $profile = $profileQuery->lockForUpdate()->first();
            abort_unless($profile, 403, 'An active student profile is required for learning-path access.');
            if (Schema::hasColumn('students', 'status')) {
                abort_unless($profile->status === 'active', 403, 'Student profile is inactive.');
            }
            if (Schema::hasColumn('students', 'branch_id') && $profile->branch_id !== null) {
                $profileBranchId = (int) $profile->branch_id;
            }
        }

        if ($accountBranchId !== null && $profileBranchId !== null) {
            abort_unless($accountBranchId === $profileBranchId, 403,
                'Student account and profile branch assignments conflict.');
        }

        $currentBranchId = $profileBranchId ?? $accountBranchId;
        if ($currentBranchId !== null) {
            abort_unless($this->plugins->isEnabled('branch-management')
                && Schema::hasTable('branches')
                && DB::table('branches')->where('id', $currentBranchId)->where('is_active', true)->exists(),
                403, 'Student branch is unavailable or inactive.');
        }

        return $currentBranchId;
    }

    public function assertCourseUnlocked(int $userId, int $courseId): void
    {
        if (!$this->plugins->isEnabled('learning-paths')
            || !Schema::hasTable('learning_path_courses')
            || !Schema::hasTable('learning_path_enrollments')
            || !Schema::hasTable('learning_paths')
        ) {
            return;
        }

        DB::transaction(function () use ($userId, $courseId): void {
            $this->assertCourseUnlockedWithinTransaction($userId, $courseId);
        });
    }

    private function assertCourseUnlockedWithinTransaction(int $userId, int $courseId): void
    {
        $currentBranchId = $this->resolveStudentBranch($userId);
        $courseBranchId = Schema::hasColumn('courses', 'branch_id')
            ? DB::table('courses')->where('id', $courseId)->value('branch_id')
            : null;
        if ($courseBranchId !== null) {
            abort_unless($currentBranchId !== null && (int) $currentBranchId === (int) $courseBranchId,
                403, 'Course is outside your current student branch.');
        }

        $memberships = DB::table('learning_path_courses as current')
            ->join('learning_path_enrollments as path_enrollment', 'path_enrollment.learning_path_id', '=', 'current.learning_path_id')
            ->join('learning_paths as path', 'path.id', '=', 'current.learning_path_id')
            ->where('path_enrollment.user_id', $userId)
            ->whereIn('path_enrollment.status', ['active', 'completed'])
            ->where('current.course_id', $courseId)
            ->orderBy('path.id')
            ->get([
                'path.id as path_id',
                'path.branch_id as path_branch_id',
                'path_enrollment.branch_id as enrollment_branch_id',
                'path_enrollment.status as enrollment_status',
                'current.position',
            ]);

        foreach ($memberships as $membership) {
            $pathBranchId = $membership->path_branch_id !== null ? (int) $membership->path_branch_id : null;
            $enrollmentBranchId = $membership->enrollment_branch_id !== null ? (int) $membership->enrollment_branch_id : null;
            if ($pathBranchId !== null && $enrollmentBranchId !== null) {
                abort_unless($pathBranchId === $enrollmentBranchId, 403,
                    'Learning-path and enrollment branch assignments conflict.');
            }
            $expectedBranchId = $pathBranchId ?? $enrollmentBranchId;
            if ($expectedBranchId !== null) {
                abort_unless($currentBranchId !== null && $currentBranchId === $expectedBranchId,
                    403, 'This learning path is outside your current student branch.');
            }
            if ($membership->enrollment_status !== 'active') {
                continue;
            }

            $previousCourseIds = DB::table('learning_path_courses')
                ->where('learning_path_id', $membership->path_id)
                ->where('position', '<', $membership->position)
                ->orderBy('position')
                ->pluck('course_id');

            foreach ($previousCourseIds as $previousCourseId) {
                abort_unless(
                    $this->isCourseCompleted($userId, (int) $previousCourseId),
                    403,
                    'Complete the previous course in your active learning path before accessing this course.'
                );
            }
        }
    }

    public function isCourseCompleted(int $userId, int $courseId): bool
    {
        $enrollment = DB::table('enrollments')
            ->where('user_id', $userId)
            ->where('course_id', $courseId)
            ->whereIn('status', ['active', 'completed'])
            ->first();

        if (!$enrollment) {
            return false;
        }
        if ($enrollment->status === 'completed') {
            return true;
        }

        if (!$this->plugins->isEnabled('course-content-studio')
            || !Schema::hasTable('course_curriculum_items')
            || !Schema::hasTable('course_content_progress')
            || !Schema::hasTable('course_content_posts')
        ) {
            return false;
        }

        $requiredItems = DB::table('course_curriculum_items as item')
            ->join('course_content_posts as post', 'post.id', '=', 'item.course_content_post_id')
            ->where('item.course_id', $courseId)
            ->where('item.is_active', true)
            ->where('item.is_preview', false)
            ->where('post.status', 'published')
            ->whereNotNull('post.published_at')
            ->where('post.published_at', '<=', now())
            ->count();
        if ($requiredItems === 0) {
            return false;
        }

        $completedItems = DB::table('course_content_progress as progress')
            ->join('course_curriculum_items as item', 'item.id', '=', 'progress.course_curriculum_item_id')
            ->join('course_content_posts as post', 'post.id', '=', 'item.course_content_post_id')
            ->where('progress.user_id', $userId)
            ->where('item.course_id', $courseId)
            ->where('item.is_active', true)
            ->where('item.is_preview', false)
            ->where('post.status', 'published')
            ->whereNotNull('post.published_at')
            ->where('post.published_at', '<=', now())
            ->whereNotNull('progress.completed_at')
            ->count();

        return $completedItems >= $requiredItems;
    }
}
