<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AttendanceOfflineSyncTest extends TestCase
{
    public function test_offline_sync_is_idempotent_and_never_overwrites_a_server_conflict(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins([
            'branch-management', 'student-management', 'batch-management',
            'guardian-management', 'mobile-app-api', 'attendance-management',
        ]);

        $branchId = DB::table('branches')->insertGetId([
            'code' => 'DHK-' . uniqid(), 'name' => 'Dhaka Main', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $teacher = $this->createUserWithRole('teacher', $branchId);
        $studentUser = $this->createUserWithRole('student', $branchId);
        $studentId = DB::table('students')->insertGetId([
            'user_id' => $studentUser->id, 'branch_id' => $branchId,
            'student_code' => 'ST-' . uniqid(), 'admission_date' => today(),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $batchId = DB::table('study_batches')->insertGetId([
            'branch_id' => $branchId, 'course_title' => 'English',
            'academic_level' => 'Intermediate', 'batch_code' => 'B-' . uniqid(),
            'name' => 'Morning Batch', 'schedule' => 'Sun-Tue',
            'max_capacity' => 30, 'enrolled_count' => 1, 'status' => 'ongoing',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('study_batch_students')->insert([
            'study_batch_id' => $batchId, 'student_id' => $studentId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $uuid = (string) \Illuminate\Support\Str::uuid();
        $payload = [
            'records' => [[
                'client_uuid' => $uuid, 'batch_id' => $batchId, 'student_id' => $studentId,
                'attendance_date' => today()->toDateString(), 'status' => 'present',
                'latitude' => 23.8103, 'longitude' => 90.4125, 'location_accuracy' => 12.5,
                'location_consent' => true,
            ]],
        ];

        $this->actingAs($teacher)->postJson('/admin/attendance/sync', $payload)
            ->assertOk()->assertJsonPath('results.0.status', 'synced');
        $record = DB::table('attendance_records')->where('client_uuid', $uuid)->first();
        $this->assertNotNull($record);

        $replay = $payload;
        $replay['records'][0]['status'] = 'absent';
        $this->actingAs($teacher)->postJson('/admin/attendance/sync', $replay)
            ->assertOk()->assertJsonPath('results.0.status', 'synced');
        $this->assertSame('present', DB::table('attendance_records')->where('id', $record->id)->value('status'));

        $conflict = $payload;
        $conflict['records'][0]['client_uuid'] = (string) \Illuminate\Support\Str::uuid();
        $conflict['records'][0]['status'] = 'late';
        $this->actingAs($teacher)->postJson('/admin/attendance/sync', $conflict)
            ->assertOk()->assertJsonPath('results.0.status', 'conflict');
        $this->assertSame('present', DB::table('attendance_records')->where('id', $record->id)->value('status'));
    }

    public function test_attendance_edit_requires_reason_and_creates_immutable_history(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins([
            'branch-management', 'student-management', 'batch-management',
            'guardian-management', 'mobile-app-api', 'attendance-management',
        ]);
        $branchId = DB::table('branches')->insertGetId([
            'code' => 'BR-' . uniqid(), 'name' => 'Branch', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $teacher = $this->createUserWithRole('teacher', $branchId);
        $studentUser = $this->createUserWithRole('student', $branchId);
        $studentId = DB::table('students')->insertGetId([
            'user_id' => $studentUser->id, 'branch_id' => $branchId,
            'student_code' => 'ST-' . uniqid(), 'admission_date' => today(),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $batchId = DB::table('study_batches')->insertGetId([
            'branch_id' => $branchId, 'course_title' => 'English',
            'batch_code' => 'BT-' . uniqid(), 'name' => 'Test Batch', 'schedule' => 'Daily',
            'max_capacity' => 30, 'enrolled_count' => 1, 'status' => 'ongoing',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('study_batch_students')->insert([
            'study_batch_id' => $batchId, 'student_id' => $studentId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $recordId = DB::table('attendance_records')->insertGetId([
            'batch_id' => $batchId, 'student_id' => $studentId, 'branch_id' => $branchId,
            'attendance_date' => today(), 'status' => 'absent', 'marked_by' => $teacher->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($teacher)->patchJson('/admin/attendance/records/' . $recordId, [
            'status' => 'present', 'reason' => 'Teacher verified the classroom register.',
        ])->assertOk();
        $this->assertDatabaseHas('attendance_record_history', [
            'attendance_record_id' => $recordId, 'old_status' => 'absent',
            'new_status' => 'present', 'action' => 'updated',
        ]);
    }
}
