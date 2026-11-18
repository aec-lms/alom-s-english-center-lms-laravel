<?php

declare(strict_types=1);

namespace Tests\\Feature;

use Illuminate\\Support\\Facades\\DB;
use Plugins\\Modules\\AttendanceManagement\\Models\\AttendanceBatchMapping;
use Plugins\\Modules\\StudentManagement\\Models\\Student;
use Tests\\TestCase;

class InstructorAttendanceOfflineSyncTest extends TestCase
{
    public function test_teacher_must_consent_before_mobile_offline_sync_and_can_sync_idempotently(): void
    {
        [$teacher, $student, $batch, $branch] = $this->fixture();
        $token = $teacher->createToken('instructor-offline-test')->plainTextToken;
        $auth = ['Authorization' => 'Bearer '.$token];

        $this->withHeaders($auth)->getJson('/api/v1/instructor/attendance/policy')
            ->assertOk()->assertJsonPath('offline_sync_enabled', false)->assertJsonPath('consent_required', true);

        $this->withHeaders($auth)->postJson('/api/v1/instructor/attendance/gps-consent', [
            'policy_version' => 'instructor-offline-gps-v1',
        ])->assertOk()->assertJsonPath('offline_sync_enabled', true);

        $this->withHeaders($auth)->getJson('/api/v1/instructor/attendance/policy')
            ->assertOk()->assertJsonPath('offline_sync_enabled', true)->assertJsonPath('gps_consent_granted', true);

        $payload = $this->payload($batch, $student);
        $this->withHeaders($auth)->postJson('/api/v1/instructor/attendance/sync', $payload)
            ->assertOk()->assertJsonPath('synced', 1)->assertJsonPath('results.0.status', 'synced');

        $this->withHeaders($auth)->postJson('/api/v1/instructor/attendance/sync', $payload)
            ->assertOk()->assertJsonPath('duplicates', 1)->assertJsonPath('results.0.status', 'duplicate');

        $this->assertDatabaseHas('attendance_entries', [
            'student_id' => $student->id,
            'branch_id' => $branch,
            'status' => 'present',
            'source' => 'offline',
        ]);
        $this->assertDatabaseHas('attendance_records', [
            'student_id' => $student->id,
            'batch_id' => $batch,
            'status' => 'present',
            'source' => 'offline',
        ]);
        $this->assertDatabaseHas('attendance_entry_audit_logs', ['new_status' => 'present', 'event' => 'created']);
    }

    public function test_conflicting_mobile_attendance_requires_a_reasoned_teacher_resolution(): void
    {
        [$teacher, $student, $batch] = $this->fixture();
        $token = $teacher->createToken('instructor-conflict-test')->plainTextToken;
        $auth = ['Authorization' => 'Bearer '.$token];
        $mapping = AttendanceBatchMapping::resolve('course_batch', $batch, (int) $teacher->branch_id, 'Mobile sync batch');
        DB::table('attendance_entries')->insert([
            'attendance_batch_mapping_id' => $mapping->id,
            'student_id' => $student->id,
            'branch_id' => $teacher->branch_id,
            'attendance_date' => now()->toDateString(),
            'status' => 'absent',
            'source' => 'online',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = $this->withHeaders($auth)->postJson('/api/v1/instructor/attendance/sync', $this->payload($batch, $student))
            ->assertOk()->assertJsonPath('conflicts', 1)->assertJsonPath('results.0.status', 'conflict')
            ->json('results.0');

        $this->withHeaders($auth)->patchJson('/api/v1/instructor/attendance/'.$result['attendance_id'].'/resolve', [
            'status' => 'late',
            'reason' => 'Verified from the signed class register',
        ])->assertOk()->assertJsonPath('status', 'late');

        $this->assertDatabaseHas('attendance_entries', ['id' => $result['attendance_id'], 'status' => 'late', 'edited_reason' => 'Verified from the signed class register']);
        $this->assertDatabaseHas('attendance_entry_audit_logs', ['event' => 'updated', 'new_status' => 'late']);
    }

    public function test_mobile_sync_rejects_unassigned_teachers_and_stale_gps(): void
    {
        [$teacher, $student, $batch] = $this->fixture();
        $otherTeacher = $this->createUserWithRole('teacher', $teacher->branch_id);
        $token = $otherTeacher->createToken('unassigned-instructor')->plainTextToken;
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/instructor/attendance/sync', $this->payload($batch, $student))
            ->assertOk()->assertJsonPath('failed', 1)->assertJsonPath('results.0.status', 'failed');

        $token = $teacher->createToken('stale-gps-instructor')->plainTextToken;
        $payload = $this->payload($batch, $student);
        $payload['records'][0]['location']['captured_at'] = now()->subMinutes(20)->toIso8601String();
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/instructor/attendance/sync', $payload)
            ->assertOk()->assertJsonPath('failed', 1)
            ->assertJsonPath('results.0.message', 'Location is stale or too imprecise. Capture a fresh foreground location and retry.');
        $this->assertDatabaseCount('attendance_entries', 0);
    }

    private function fixture(): array
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'student-management', 'course-management', 'attendance-management', 'mobile-app-api']);

        $branch = DB::table('branches')->insertGetId([
            'code' => 'MOB-ATT-'.uniqid(),
            'name' => 'Mobile Attendance Branch',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $teacher = $this->createUserWithRole('teacher', $branch);
        $studentUser = $this->createUserWithRole('student', $branch);
        $student = Student::query()->create([
            'user_id' => $studentUser->id,
            'branch_id' => $branch,
            'student_code' => 'MOB-ATT-STU-'.uniqid(),
            'admission_date' => now()->toDateString(),
            'status' => 'active',
        ]);
        $course = DB::table('courses')->insertGetId([
            'branch_id' => $branch,
            'code' => 'MOB-ATT-'.uniqid(),
            'title' => 'Mobile Attendance Course',
            'slug' => 'mobile-attendance-'.uniqid(),
            'price' => 0,
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $batch = DB::table('batches')->insertGetId([
            'course_id' => $course,
            'branch_id' => $branch,
            'name' => 'Mobile Attendance Batch',
            'capacity' => 30,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('course_teacher_assignments')->insert([
            'course_id' => $course,
            'teacher_user_id' => $teacher->id,
            'assigned_by' => $teacher->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $mapping = AttendanceBatchMapping::resolve('course_batch', $batch, $branch, 'Mobile Attendance Batch');
        DB::table('attendance_batch_rosters')->insert([
            'attendance_batch_mapping_id' => $mapping->id,
            'student_id' => $student->id,
            'assigned_by' => $teacher->id,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$teacher, $student, $batch, $branch];
    }

    private function payload(int $batch, Student $student): array
    {
        return [
            'records' => [[
                'idempotency_key' => (string) \\Illuminate\\Support\\Str::uuid(),
                'batch_id' => $batch,
                'student_id' => $student->id,
                'attendance_date' => now()->toDateString(),
                'status' => 'present',
                'location' => [
                    'latitude' => 23.8103,
                    'longitude' => 90.4125,
                    'accuracy_meters' => 8,
                    'captured_at' => now()->toIso8601String(),
                ],
            ]],
        ];
    }
}
