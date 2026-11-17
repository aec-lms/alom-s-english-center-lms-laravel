<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Permission;
use Illuminate\Support\Facades\DB;
use Plugins\Modules\StudentManagement\Models\Student;
use Tests\TestCase;

class AttendanceRegisterWorkflowTest extends TestCase
{
    public function test_batch_attendance_is_audited_and_edits_require_a_reason(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'student-management', 'course-management', 'batch-management', 'attendance-management']);

        $branch = DB::table('branches')->insertGetId(['code' => 'ATT-REG-01', 'name' => 'Attendance Register', 'created_at' => now(), 'updated_at' => now()]);
        $admin = $this->createUserWithRole('admin', $branch);
        $permission = Permission::query()->where('slug', 'attendance.manage')->value('id');
        $admin->roles()->first()->permissions()->syncWithoutDetaching([$permission]);

        $studentUser = $this->createUserWithRole('student', $branch);
        $student = Student::query()->create([
            'user_id' => $studentUser->id,
            'branch_id' => $branch,
            'student_code' => 'ATT-REG-STU-01',
            'admission_date' => now()->toDateString(),
            'status' => 'active',
        ]);
        $course = DB::table('courses')->insertGetId([
            'branch_id' => $branch,
            'code' => 'ATT-REG-COURSE',
            'title' => 'Attendance Test Course',
            'slug' => 'attendance-test-course',
            'price' => 0,
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $batch = DB::table('batches')->insertGetId([
            'course_id' => $course,
            'branch_id' => $branch,
            'name' => 'Attendance Test Batch',
            'capacity' => 20,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = [
            'batch_id' => $batch,
            'attendance_date' => now()->toDateString(),
            'source' => 'offline',
            'records' => [['student_id' => $student->id, 'status' => 'present', 'sync_uuid' => 'd1b9e1c0-6d4a-4c51-91a0-5c5d7b2c98f1']],
        ];

        $this->actingAs($admin)->post('/admin/attendance', $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('attendance_records', ['student_id' => $student->id, 'status' => 'present', 'source' => 'offline']);
        $this->assertDatabaseHas('attendance_audit_logs', ['new_status' => 'present', 'event' => 'created']);

        $payload['records'][0]['status'] = 'late';
        $payload['records'][0]['sync_uuid'] = null;
        $this->actingAs($admin)->post('/admin/attendance', $payload)->assertStatus(422);
        $this->assertDatabaseHas('attendance_records', ['student_id' => $student->id, 'status' => 'present']);

        $payload['records'][0]['edit_reason'] = 'Teacher corrected register';
        $this->actingAs($admin)->post('/admin/attendance', $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('attendance_records', ['student_id' => $student->id, 'status' => 'late', 'edited_reason' => 'Teacher corrected register']);
        $this->assertDatabaseHas('attendance_audit_logs', ['new_status' => 'late', 'event' => 'updated', 'reason' => 'Teacher corrected register']);
    }
}
