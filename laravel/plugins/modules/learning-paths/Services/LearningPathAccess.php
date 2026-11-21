<?php

declare(strict_types=1);

namespace Plugins\Modules\LearningPaths\Services;

use App\Core\Plugins\PluginManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LearningPathAccess
{
    public function __construct(private readonly PluginManager $plugins) {}

    public function assertCourseUnlocked(int $userId, int $courseId): void
    {
        if (!$this->plugins->isEnabled('learning-paths')
            || !Schema::hasTable('learning_path_courses')
            || !Schema::hasTable('learning_path_enrollments')
        ) {
            return;
        }

        $previousCourseIds = DB::table('learning_path_courses as path_item')
            ->join('learning_path_enrollments as path_enrollment', 'path_enrollment.learning_path_id', '=', 'path_item.learning_path_id')
            ->join('learning_path_courses as previous', function ($join): void {
                $join->on('previous.learning_path_id', '=', 'path_item.learning_path_id')
                    ->whereColumn('previous.position', '<', 'path_item.position');
            })
            ->where('path_enrollment.user_id', $userId)
            ->where('path_enrollment.status', 'active')
            ->where('path_item.course_id', $courseId)
            ->orderBy('previous.position')
            ->pluck('previous.course_id')
            ->unique();

        foreach ($previousCourseIds as $previousCourseId) {
            abort_unless(
                $this->isCourseCompleted($userId, (int) $previousCourseId),
                403,
                'Complete the previous course in your active learning path before accessing this course.'
            );
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
        ) {
            return false;
        }

        $requiredItems = DB::table('course_curriculum_items')
            ->where('course_id', $courseId)
            ->where('is_active', true)
            ->where('is_preview', false)
            ->count();
        if ($requiredItems === 0) {
            return false;
        }

        $completedItems = DB::table('course_content_progress as progress')
            ->join('course_curriculum_items as item', 'item.id', '=', 'progress.course_curriculum_item_id')
            ->where('progress.user_id', $userId)
            ->where('item.course_id', $courseId)
            ->where('item.is_active', true)
            ->where('item.is_preview', false)
            ->whereNotNull('progress.completed_at')
            ->count();

        return $completedItems >= $requiredItems;
    }
}
