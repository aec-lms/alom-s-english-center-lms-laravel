<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Plugins\Modules\CourseManagement\Models\Course;
use Tests\TestCase;

final class CourseTeacherAssignmentSecurityTest extends TestCase
{
    public function test_inactive_teacher_is_not_offered_or_assignable_to_a_course(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['course-management', 'teacher-management']);

        $admin = $this->createUserWithRole('admin');
        $inactiveTeacher = $this->createUserWithRole('teacher');
        $inactiveTeacher->forceFill(['is_active' => false])->save();

        $course = Course::query()->create([
            'code' => 'INACTIVE-TEACHER-COURSE',
            'title' => 'Inactive Teacher Assignment Course',
            'slug' => 'inactive-teacher-assignment-course',
            'price' => 0,
            'status' => 'draft',
            'branch_id' => null,
        ]);

        $this->actingAs($admin)->get('/admin/courses/teacher-assignments')
            ->assertOk()
            ->assertDontSee($inactiveTeacher->email);

        $this->actingAs($admin)->post('/admin/courses/teacher-assignments', [
            'course_id' => $course->id,
            'teacher_user_id' => $inactiveTeacher->id,
        ])->assertStatus(422);

        $this->assertDatabaseMissing('course_teacher_assignments', [
            'course_id' => $course->id,
            'teacher_user_id' => $inactiveTeacher->id,
        ]);
    }

    public function test_inactive_assigning_actor_is_rejected_and_inactive_existing_assignments_are_hidden(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['course-management', 'teacher-management']);
        $admin = $this->createUserWithRole('admin');
        $inactiveAdmin = $this->createUserWithRole('admin');
        $teacher = $this->createUserWithRole('teacher');
        $course = Course::query()->create([
            'code' => 'INACTIVE-ACTOR-COURSE',
            'title' => 'Inactive Actor Assignment Course',
            'slug' => 'inactive-actor-assignment-course',
            'price' => 0,
            'status' => 'draft',
            'branch_id' => null,
        ]);

        $this->actingAs($admin)->post('/admin/courses/teacher-assignments', [
            'course_id' => $course->id,
            'teacher_user_id' => $teacher->id,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $teacher->forceFill(['is_active' => false])->save();
        $this->actingAs($admin)->get('/admin/courses/teacher-assignments')
            ->assertOk()
            ->assertDontSee($teacher->email);

        $inactiveAdmin->forceFill(['is_active' => false])->save();
        $this->actingAs($inactiveAdmin)->post('/admin/courses/teacher-assignments', [
            'course_id' => $course->id,
            'teacher_user_id' => $teacher->id,
        ])->assertStatus(403);

        $this->assertDatabaseHas('course_teacher_assignments', [
            'course_id' => $course->id,
            'teacher_user_id' => $teacher->id,
        ]);
    }


    public function test_active_teacher_can_still_be_assigned_to_a_course(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['course-management', 'teacher-management']);

        $admin = $this->createUserWithRole('admin');
        $teacher = $this->createUserWithRole('teacher');
        $course = Course::query()->create([
            'code' => 'ACTIVE-TEACHER-COURSE',
            'title' => 'Active Teacher Assignment Course',
            'slug' => 'active-teacher-assignment-course',
            'price' => 0,
            'status' => 'draft',
            'branch_id' => null,
        ]);

        $this->actingAs($admin)->get('/admin/courses/teacher-assignments')
            ->assertOk()
            ->assertSee($teacher->email);

        $this->actingAs($admin)->post('/admin/courses/teacher-assignments', [
            'course_id' => $course->id,
            'teacher_user_id' => $teacher->id,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('course_teacher_assignments', [
            'course_id' => $course->id,
            'teacher_user_id' => $teacher->id,
            'assigned_by' => $admin->id,
        ]);
    }
    public function test_mysql_account_deactivation_waits_for_assignment_and_future_assignment_is_denied(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Teacher account/assignment serialization requires MySQL 8.');
        }

        $this->markSystemInstalled();
        $this->activatePlugins(['course-management', 'teacher-management']);
        $admin = $this->createUserWithRole('admin');
        $teacher = $this->createUserWithRole('teacher');

        $firstCourse = Course::query()->create([
            'code' => 'TEACHER-RACE-COURSE-1',
            'title' => 'Teacher Assignment Race One',
            'slug' => 'teacher-assignment-race-one',
            'price' => 0,
            'status' => 'draft',
            'branch_id' => null,
        ]);
        $secondCourse = Course::query()->create([
            'code' => 'TEACHER-RACE-COURSE-2',
            'title' => 'Teacher Assignment Race Two',
            'slug' => 'teacher-assignment-race-two',
            'price' => 0,
            'status' => 'draft',
            'branch_id' => null,
        ]);

        $default = DB::getDefaultConnection();
        $secondaryName = 'teacher_assignment_race_' . bin2hex(random_bytes(3));
        config(['database.connections.' . $secondaryName => config('database.connections.' . $default)]);
        DB::purge($secondaryName);
        $primary = DB::connection($default);
        $secondary = DB::connection($secondaryName);
        $secondary->statement('SET SESSION innodb_lock_wait_timeout = 1');
        $primary->beginTransaction();

        try {
            $this->actingAs($admin)->post('/admin/courses/teacher-assignments', [
                'course_id' => $firstCourse->id,
                'teacher_user_id' => $teacher->id,
            ])->assertRedirect()->assertSessionHasNoErrors();

            try {
                $secondary->table('users')->where('id', $teacher->id)->update([
                    'is_active' => false,
                    'updated_at' => now(),
                ]);
                $this->fail('Teacher deactivation must wait while course assignment holds the teacher account lock.');
            } catch (QueryException $exception) {
                $this->assertSame(1205, (int) ($exception->errorInfo[1] ?? 0), $exception->getMessage());
            }

            $primary->commit();

            $secondary->table('users')->where('id', $teacher->id)->update([
                'is_active' => false,
                'updated_at' => now(),
            ]);

            $this->actingAs($admin)->post('/admin/courses/teacher-assignments', [
                'course_id' => $secondCourse->id,
                'teacher_user_id' => $teacher->id,
            ])->assertStatus(422);

            $this->assertDatabaseHas('course_teacher_assignments', [
                'course_id' => $firstCourse->id,
                'teacher_user_id' => $teacher->id,
            ]);
            $this->assertDatabaseMissing('course_teacher_assignments', [
                'course_id' => $secondCourse->id,
                'teacher_user_id' => $teacher->id,
            ]);
        } catch (\Throwable $exception) {
            if ($primary->transactionLevel() > 0) {
                $primary->rollBack();
            }
            throw $exception;
        } finally {
            DB::purge($secondaryName);
        }
    }

    public function test_mysql_teacher_role_revocation_waits_for_assignment_and_future_assignment_is_denied(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Teacher role/assignment serialization requires MySQL 8.');
        }

        $this->markSystemInstalled();
        $this->activatePlugins(['course-management', 'teacher-management']);
        $admin = $this->createUserWithRole('admin');
        $teacher = $this->createUserWithRole('teacher');
        $teacherRoleId = (int) DB::table('roles')->where('slug', 'teacher')->value('id');
        $this->assertGreaterThan(0, $teacherRoleId);

        $firstCourse = Course::query()->create([
            'code' => 'TEACHER-ROLE-RACE-1',
            'title' => 'Teacher Role Race One',
            'slug' => 'teacher-role-race-one',
            'price' => 0,
            'status' => 'draft',
            'branch_id' => null,
        ]);
        $secondCourse = Course::query()->create([
            'code' => 'TEACHER-ROLE-RACE-2',
            'title' => 'Teacher Role Race Two',
            'slug' => 'teacher-role-race-two',
            'price' => 0,
            'status' => 'draft',
            'branch_id' => null,
        ]);

        $default = DB::getDefaultConnection();
        $secondaryName = 'teacher_role_assignment_race_' . bin2hex(random_bytes(3));
        config(['database.connections.' . $secondaryName => config('database.connections.' . $default)]);
        DB::purge($secondaryName);
        $primary = DB::connection($default);
        $secondary = DB::connection($secondaryName);
        $secondary->statement('SET SESSION innodb_lock_wait_timeout = 1');
        $primary->beginTransaction();

        try {
            $this->actingAs($admin)->post('/admin/courses/teacher-assignments', [
                'course_id' => $firstCourse->id,
                'teacher_user_id' => $teacher->id,
            ])->assertRedirect()->assertSessionHasNoErrors();

            try {
                $secondary->table('role_user')
                    ->where('user_id', $teacher->id)
                    ->where('role_id', $teacherRoleId)
                    ->delete();
                $this->fail('Teacher role revocation must wait while course assignment holds the role-pivot lock.');
            } catch (QueryException $exception) {
                $this->assertSame(1205, (int) ($exception->errorInfo[1] ?? 0), $exception->getMessage());
            }

            $primary->commit();

            $secondary->table('role_user')
                ->where('user_id', $teacher->id)
                ->where('role_id', $teacherRoleId)
                ->delete();

            $this->actingAs($admin)->post('/admin/courses/teacher-assignments', [
                'course_id' => $secondCourse->id,
                'teacher_user_id' => $teacher->id,
            ])->assertStatus(422);

            $this->assertDatabaseHas('course_teacher_assignments', [
                'course_id' => $firstCourse->id,
                'teacher_user_id' => $teacher->id,
            ]);
            $this->assertDatabaseMissing('course_teacher_assignments', [
                'course_id' => $secondCourse->id,
                'teacher_user_id' => $teacher->id,
            ]);
        } catch (\Throwable $exception) {
            if ($primary->transactionLevel() > 0) {
                $primary->rollBack();
            }
            throw $exception;
        } finally {
            DB::purge($secondaryName);
        }
    }

}
