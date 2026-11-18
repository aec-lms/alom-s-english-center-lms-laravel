<?php

declare(strict_types=1);

namespace Plugins\Modules\Phonetics\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * Host-LMS bridge. The plugin stores only opaque host IDs and never alters
 * the host LMS's course, lesson, batch, category, enrollment or billing tables.
 * A different Laravel LMS can map its own table/column names in Config/phonetics.php.
 */
final class CourseCatalog
{
    public function driver(): string
    {
        return (string) config('phonetics.driver', 'aec');
    }

    public function categories(): array
    {
        $map = config('phonetics.catalog.categories', []);
        return $this->options($map, ['id' => 'id', 'title' => 'name']);
    }

    public function courses(?string $categoryRef = null): array
    {
        $map = config('phonetics.catalog.courses', []);
        if (!$this->available($map)) {
            return [];
        }
        $query = $this->table($map);
        if ($categoryRef !== null && $categoryRef !== '' && $this->hasColumn($map, $map['category_id'] ?? 'category_id')) {
            $query->where($map['category_id'] ?? 'category_id', $categoryRef);
        }
        $rows = $query->orderBy($map['title'] ?? 'title')->get();
        return $rows->map(fn ($row) => [
            'id' => (string) data_get($row, $map['id'] ?? 'id'),
            'title' => (string) data_get($row, $map['title'] ?? 'title'),
            'slug' => (string) data_get($row, $map['slug'] ?? 'slug', ''),
            'category_id' => (string) data_get($row, $map['category_id'] ?? 'category_id', ''),
            'price' => (float) data_get($row, $map['price'] ?? 'price', 0),
        ])->all();
    }

    public function lessons(string $courseRef): array
    {
        $map = config('phonetics.catalog.lessons', []);
        if (!$this->available($map) || !$this->hasColumn($map, $map['course_id'] ?? 'course_id')) {
            return [];
        }
        $query = $this->table($map)->where($map['course_id'] ?? 'course_id', $courseRef);
        $title = $map['title'] ?? 'title';
        $id = $map['id'] ?? 'id';
        $sort = $map['sort_order'] ?? 'sort_order';
        if ($this->hasColumn($map, $sort)) {
            $query->orderBy($sort);
        }
        return $query->orderBy($title)->get()->map(fn ($row) => [
            'id' => (string) data_get($row, $id),
            'title' => (string) data_get($row, $title),
        ])->all();
    }

    public function batches(?string $courseTitle = null): array
    {
        $map = config('phonetics.catalog.batches', []);
        if (!$this->available($map)) {
            return [];
        }
        $query = $this->table($map);
        $courseTitleColumn = $map['course_title'] ?? null;
        if ($courseTitle && $courseTitleColumn && $this->hasColumn($map, $courseTitleColumn)) {
            $query->where($courseTitleColumn, $courseTitle);
        }
        $id = $map['id'] ?? 'id';
        $title = $map['title'] ?? 'name';
        return $query->orderBy($title)->get()->map(fn ($row) => [
            'id' => (string) data_get($row, $id),
            'title' => (string) data_get($row, $title),
        ])->all();
    }

    public function course(string $courseRef): object
    {
        $map = config('phonetics.catalog.courses', []);
        $query = $this->table($map);
        $idColumn = $map['id'] ?? 'id';
        $course = $query->where($idColumn, $courseRef)->first();
        if (!$course && !empty($map['slug']) && $this->hasColumn($map, $map['slug'])) {
            $course = $this->table($map)->where($map['slug'], $courseRef)->first();
        }
        abort_unless($course, 404, 'Course not found in the connected LMS.');
        return $course;
    }

    public function batch(string $batchRef): object
    {
        $map = config('phonetics.catalog.batches', []);
        $batch = $this->table($map)->where($map['id'] ?? 'id', $batchRef)->first();
        abort_unless($batch, 404, 'Batch not found in the connected LMS.');
        return $batch;
    }

    public function courseId(object $course): string
    {
        $map = config('phonetics.catalog.courses', []);
        return (string) data_get($course, $map['id'] ?? 'id');
    }

    public function courseCategoryId(object $course): ?string
    {
        $map = config('phonetics.catalog.courses', []);
        $column = $map['category_id'] ?? null;
        if (!$column || !$this->hasColumn($map, $column)) {
            return null;
        }
        $value = data_get($course, $column);
        return $value === null ? null : (string) $value;
    }

    public function assertCourseAvailable(object $course): void
    {
        $map = config('phonetics.catalog.courses', []);
        $statusColumn = $map['status'] ?? null;
        $activeStatuses = config('phonetics.access.course_active_statuses', ['published', 'active']);
        if ($statusColumn && $this->hasColumn($map, $statusColumn)) {
            abort_unless(
                in_array((string) data_get($course, $statusColumn), $activeStatuses, true),
                404,
                'This course is not currently available.'
            );
        }
    }

    public function isPaid(object $course): bool
    {
        $map = config('phonetics.catalog.courses', []);
        $column = $map['price'] ?? null;
        if (!$column || !$this->hasColumn($map, $column)) {
            throw new ServiceUnavailableHttpException(null, 'Course price mapping is required to enforce free/paid learning access.');
        }

        return (float) data_get($course, $column, 0) > 0;
    }

    public function isBatchMember(object $user, string $batchRef): bool
    {
        $membership = config('phonetics.catalog.batch_memberships', []);
        if (!$this->available($membership)) {
            throw new ServiceUnavailableHttpException(null, 'Batch membership mapping is not configured for this LMS.');
        }
        $query = $this->table($membership)
            ->where($membership['user_id'] ?? 'user_id', $user->getAuthIdentifier())
            ->where($membership['batch_id'] ?? 'batch_id', $batchRef);
        $driverColumn = $membership['driver_column'] ?? null;
        if ($driverColumn && $this->hasColumn($membership, $driverColumn)) {
            $query->where($driverColumn, $this->driver());
        }
        if (!empty($membership['active_column']) && $this->hasColumn($membership, $membership['active_column'])) {
            $query->where($membership['active_column'], $membership['active_value'] ?? 1);
        }
        return $query->exists();
    }

    public function assertAccess(object $user, string $courseRef, string $audience = 'both', ?string $batchRef = null): void
    {
        $course = $this->course($courseRef);
        $this->assertCourseAvailable($course);

        $adminRoles = config('phonetics.access.admin_roles', ['admin']);
        if (method_exists($user, 'hasRole')) {
            foreach ($adminRoles as $role) {
                if ($user->hasRole($role)) {
                    return;
                }
            }
        }
        if ((bool) data_get($user, 'is_admin', false)) {
            return;
        }

        $courseMap = config('phonetics.catalog.courses', []);
        if ($audience !== 'both') {
            $isPaid = $this->isPaid($course);
            if (($audience === 'paid' && !$isPaid) || ($audience === 'free' && $isPaid)) {
                throw new AccessDeniedHttpException('This learning activity is not available for this course type.');
            }
        }

        $enrollment = config('phonetics.catalog.enrollments', []);
        if (!$this->available($enrollment)) {
            throw new ServiceUnavailableHttpException(null, 'The connected LMS enrollment adapter is not configured.');
        }
        $query = $this->table($enrollment)
            ->where($enrollment['user_id'] ?? 'user_id', $user->getAuthIdentifier())
            ->where($enrollment['course_id'] ?? 'course_id', $this->courseId($course));

        // Preserve the host LMS branch boundary when its catalog exposes branch IDs.
        $branchId = data_get($user, 'branch_id');
        if ($branchId !== null) {
            $courseBranchColumn = $courseMap['branch_id'] ?? 'branch_id';
            if ($this->hasColumn($courseMap, $courseBranchColumn)) {
                $courseBranchId = data_get($course, $courseBranchColumn);
                abort_unless(
                    $courseBranchId === null || (string) $courseBranchId === (string) $branchId,
                    403,
                    'Course belongs to another branch.'
                );
            }

            $enrollmentBranchColumn = $enrollment['branch_id'] ?? 'branch_id';
            if ($this->hasColumn($enrollment, $enrollmentBranchColumn)) {
                $query->where(fn ($branch) => $branch
                    ->whereNull($enrollmentBranchColumn)
                    ->orWhere($enrollmentBranchColumn, $branchId));
            }
        }

        $statusColumn = $enrollment['status'] ?? null;
        $activeStatuses = $enrollment['active_statuses'] ?? ['active', 'completed'];
        if ($statusColumn && $this->hasColumn($enrollment, $statusColumn)) {
            $query->whereIn($statusColumn, $activeStatuses);
        }
        abort_unless($query->exists(), 403, 'An active course enrollment is required.');

        if ($batchRef !== null && $batchRef !== '') {
            abort_unless($this->isBatchMember($user, $batchRef), 403, 'This activity is restricted to another batch.');
        }
    }

    private function options(array $map, array $defaults): array
    {
        if (!$this->available($map)) {
            return [];
        }
        $id = $map['id'] ?? $defaults['id'];
        $title = $map['title'] ?? $defaults['title'];
        return $this->table($map)->orderBy($title)->get()->map(fn ($row) => [
            'id' => (string) data_get($row, $id),
            'title' => (string) data_get($row, $title),
        ])->all();
    }

    private function table(array $map): Builder
    {
        abort_unless($this->available($map), 503, 'Connected LMS catalog table is unavailable.');
        return DB::table($map['table']);
    }

    private function available(array $map): bool
    {
        $table = $map['table'] ?? '';
        return is_string($table)
            && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) === 1
            && Schema::hasTable($table);
    }

    private function hasColumn(array $map, string $column): bool
    {
        $table = $map['table'] ?? '';
        return $this->available($map)
            && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column) === 1
            && Schema::hasColumn($table, $column);
    }
}
