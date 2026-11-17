<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Plugins\PluginManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StudentTracingPluginTest extends TestCase
{
    public function test_student_tracing_installs_without_student_management_and_records_student_lifecycle_events(): void
    {
        $this->markSystemInstalled();

        $manager = app(PluginManager::class);
        $manager->syncCatalog();

        $manifest = $manager->discoverManifests()['student-tracing'] ?? null;
        $this->assertNotNull($manifest);
        $this->assertSame([], $manifest->dependencies);

        $manager->install('student-tracing');
        $this->assertTrue($manager->isEnabled('student-tracing'));
        $this->assertFalse($manager->isEnabled('student-management'));
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('student_activity_events'));

        $student = (object) ['id' => 901, 'branch_id' => 12];
        Event::dispatch(
            'eloquent.created: Plugins\Modules\StudentManagement\Models\Student',
            [$student]
        );

        $this->assertDatabaseHas('student_activity_events', [
            'student_id' => 901,
            'branch_id' => 12,
            'event_type' => 'student.created',
        ]);

        $deletedStudent = new class {
            public int $id = 901;

            public function getOriginal(string $key): mixed
            {
                return $key === 'branch_id' ? 12 : null;
            }

            public function getAttribute(string $key): mixed
            {
                return null;
            }
        };
        Event::dispatch(
            'eloquent.deleted: Plugins\\Modules\\StudentManagement\\Models\\Student',
            [$deletedStudent]
        );
        $this->assertDatabaseHas('student_activity_events', [
            'student_id' => 901,
            'branch_id' => 12,
            'event_type' => 'student.deleted',
        ]);

        Schema::create('students', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('branch_id')->nullable();
        });
        DB::table('students')->insert(['id' => 903, 'user_id' => 904, 'branch_id' => 14]);

        Event::dispatch(
            'eloquent.created: Plugins\Modules\AttendanceManagement\Models\AttendanceRecord',
            [(object) ['id' => 905, 'student_id' => 903, 'branch_id' => 14]]
        );
        Event::dispatch(
            'eloquent.created: Plugins\Modules\CourseManagement\Models\Enrollment',
            [(object) ['id' => 906, 'user_id' => 904]]
        );
        Event::dispatch(
            'eloquent.created: Plugins\Modules\ExamManagement\Models\ExamAttempt',
            [(object) ['id' => 907, 'user_id' => 904]]
        );

        $this->assertDatabaseHas('student_activity_events', ['student_id' => 903, 'event_type' => 'attendance.created']);
        $this->assertDatabaseHas('student_activity_events', ['student_id' => 903, 'event_type' => 'enrollment.created']);
        $this->assertDatabaseHas('student_activity_events', ['student_id' => 903, 'event_type' => 'exam_attempt.created']);
    }

    public function test_branch_manager_trace_timeline_and_csv_are_limited_to_assigned_branch(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'student-tracing']);

        $branchA = DB::table('branches')->insertGetId([
            'code' => 'TRACE-A', 'name' => 'Trace Branch A', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $branchB = DB::table('branches')->insertGetId([
            'code' => 'TRACE-B', 'name' => 'Trace Branch B', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $manager = $this->createUserWithRole('branch_manager', $branchA);
        $viewPermission = DB::table('permissions')->where('slug', 'students.trace.view')->value('id');
        $exportPermission = DB::table('permissions')->where('slug', 'students.trace.export')->value('id');
        $this->assertNotNull($viewPermission);
        $this->assertNotNull($exportPermission);
        $manager->roles()->first()->permissions()->syncWithoutDetaching([$viewPermission]);

        foreach ([['student_id' => 1101, 'branch_id' => $branchA], ['student_id' => 2202, 'branch_id' => $branchB]] as $row) {
            DB::table('student_activity_events')->insert($row + [
                'actor_id' => null,
                'event_type' => 'student.created',
                'metadata' => json_encode(['changed_fields' => []]),
                'occurred_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->actingAs($manager)->get('/admin/student-tracing')
            ->assertOk()
            ->assertSee('#1101')
            ->assertDontSee('#2202');

        $this->actingAs($manager)->get('/admin/student-tracing/export')->assertForbidden();

        $manager->roles()->first()->permissions()->syncWithoutDetaching([$exportPermission]);
        $response = $this->actingAs($manager)->get('/admin/student-tracing/export')->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString('1101', $csv);
        $this->assertStringNotContainsString('2202', $csv);
    }

    public function test_branch_manager_is_denied_when_branch_management_storage_is_not_installed(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['student-tracing']);

        $manager = $this->createUserWithRole('branch_manager');
        $manager->branch_id = 99;
        $manager->save();
        $permissionId = DB::table('permissions')->where('slug', 'students.trace.view')->value('id');
        $this->assertNotNull($permissionId);
        $manager->roles()->first()->permissions()->syncWithoutDetaching([$permissionId]);

        $this->assertFalse(Schema::hasTable('branches'));
        $this->actingAs($manager)->get('/admin/student-tracing')->assertForbidden();
    }

    public function test_student_trace_uninstall_preserves_history(): void
    {
        $this->markSystemInstalled();
        $manager = app(PluginManager::class);
        $manager->syncCatalog();
        $manager->install('student-tracing');

        DB::table('student_activity_events')->insert([
            'student_id' => 902,
            'branch_id' => 12,
            'actor_id' => null,
            'event_type' => 'student.created',
            'metadata' => json_encode(['changed_fields' => []]),
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $manager->disable('student-tracing');
        $manager->uninstall('student-tracing', false);

        $this->assertDatabaseHas('student_activity_events', [
            'student_id' => 902,
            'event_type' => 'student.created',
        ]);
    }
}
