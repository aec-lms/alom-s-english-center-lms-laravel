<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Permission;
use Illuminate\Support\Facades\DB;
use Plugins\Modules\AttendanceManagement\Models\AttendanceBatchMapping;
use Plugins\Modules\GuardianManagement\Models\Guardian;
use Plugins\Modules\StudentManagement\Models\Student;
use Tests\TestCase;

class AttendanceRegisterWorkflowTest extends TestCase
{
    public function test_batch_attendance_is_audited_and_edits_require_a_reason(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'student-management', 'course-management', 'batch-management', 'guardian-management', 'attendance-management', 'learning-rewards']);

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
        $mappingId = DB::table('attendance_batch_mappings')
            ->where('source_type', 'course_batch')
            ->where('source_id', $batch)
            ->value('id');
        $this->assertNotNull($mappingId, 'Legacy course batches must have an explicit attendance identity.');
        // Re-run the additive upgrade migration after removing the canonical copy;
        // it must reconstruct the canonical history from the untouched legacy row.
        DB::table('attendance_entries')->where('attendance_batch_mapping_id', $mappingId)->delete();
        $upgradeMigration = require base_path('plugins/modules/attendance-management/Database/Migrations/2026_11_18_000003_create_canonical_attendance_entries.php');
        $upgradeMigration->up();
        $this->assertDatabaseHas('attendance_entries', [
            'attendance_batch_mapping_id' => $mappingId,
            'student_id' => $student->id,
            'status' => 'present',
            'source' => 'offline',
        ]);
        $this->assertDatabaseHas('attendance_records', [
            'student_id' => $student->id,
            'batch_id' => $batch,
            'attendance_batch_mapping_id' => $mappingId,
        ]);
        $this->assertDatabaseHas('attendance_audit_logs', ['new_status' => 'present', 'event' => 'created']);

        $payload['records'][0]['status'] = 'late';
        $payload['records'][0]['sync_uuid'] = null;
        $this->actingAs($admin)->post('/admin/attendance', $payload)->assertStatus(422);
        $this->assertDatabaseHas('attendance_records', ['student_id' => $student->id, 'status' => 'present']);

        $payload['records'][0]['edit_reason'] = 'Teacher corrected register';
        $this->actingAs($admin)->post('/admin/attendance', $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('attendance_records', ['student_id' => $student->id, 'status' => 'late', 'edited_reason' => 'Teacher corrected register']);
        $this->assertDatabaseHas('attendance_entries', ['student_id' => $student->id, 'status' => 'late']);
        $this->assertDatabaseHas('attendance_audit_logs', ['new_status' => 'late', 'event' => 'updated', 'reason' => 'Teacher corrected register']);
        $this->assertDatabaseHas('learning_reward_ledger', [
            'user_id' => $studentUser->id,
            'activity_key' => 'attendance_present',
            'points' => 5,
        ]);
        $this->assertDatabaseHas('learning_reward_ledger', [
            'user_id' => $studentUser->id,
            'activity_key' => 'reversal',
            'source_type' => 'reward_reversal',
            'points' => -5,
        ]);

        // Re-marking the same attendance row as present must not mint a
        // second award after its original award has already been reversed.
        $payload['records'][0]['status'] = 'present';
        $payload['records'][0]['edit_reason'] = 'Teacher restored the original status';
        $this->actingAs($admin)->post('/admin/attendance', $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('learning_reward_ledger')
            ->where('user_id', $studentUser->id)
            ->where('activity_key', 'attendance_present')
            ->where('points', 5)
            ->count());
        $this->assertSame(1, DB::table('learning_reward_ledger')
            ->where('user_id', $studentUser->id)
            ->where('activity_key', 'reversal')
            ->where('points', -5)
            ->count());
        $this->assertSame(0, (int) DB::table('learning_reward_ledger')->where('user_id', $studentUser->id)->sum('points'));
    }

    public function test_offline_sync_is_idempotent_and_records_offline_source(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'student-management', 'course-management', 'batch-management', 'attendance-management']);

        $branch = DB::table('branches')->insertGetId([
            'code' => 'ATT-SYNC-01',
            'name' => 'Attendance Sync',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $admin = $this->createUserWithRole('admin', $branch);
        $permission = Permission::query()->where('slug', 'attendance.manage')->value('id');
        $admin->roles()->first()->permissions()->syncWithoutDetaching([$permission]);

        $studentUser = $this->createUserWithRole('student', $branch);
        $student = Student::query()->create([
            'user_id' => $studentUser->id,
            'branch_id' => $branch,
            'student_code' => 'ATT-SYNC-STU-01',
            'admission_date' => now()->toDateString(),
            'status' => 'active',
        ]);
        $course = DB::table('courses')->insertGetId([
            'branch_id' => $branch,
            'code' => 'ATT-SYNC-COURSE',
            'title' => 'Attendance Sync Course',
            'slug' => 'attendance-sync-course',
            'price' => 0,
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $batch = DB::table('batches')->insertGetId([
            'course_id' => $course,
            'branch_id' => $branch,
            'name' => 'Attendance Sync Batch',
            'capacity' => 20,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = [
            'batch_id' => $batch,
            'attendance_date' => now()->toDateString(),
            'records' => [[
                'student_id' => $student->id,
                'status' => 'present',
                'sync_uuid' => '7a90e9f1-3b7b-4ad7-97a7-92b3c1f2d405',
            ]],
        ];

        $this->actingAs($admin)->postJson('/admin/attendance/sync', $payload)
            ->assertOk()
            ->assertJson(['status' => 'ok', 'synced' => 1, 'duplicates' => 0]);

        $this->actingAs($admin)->postJson('/admin/attendance/sync', $payload)
            ->assertOk()
            ->assertJson(['status' => 'ok', 'synced' => 0, 'duplicates' => 1]);

        $this->assertDatabaseCount('attendance_records', 1);
        $this->assertDatabaseHas('attendance_records', [
            'student_id' => $student->id,
            'source' => 'offline',
            'sync_uuid' => '7a90e9f1-3b7b-4ad7-97a7-92b3c1f2d405',
        ]);
        $this->assertDatabaseCount('attendance_audit_logs', 1);
    }

    public function test_qr_checkin_requires_roster_and_rejects_replay_and_expired_tokens(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'student-management', 'course-management', 'batch-management', 'guardian-management', 'attendance-management']);

        $branch = DB::table('branches')->insertGetId([
            'code' => 'ATT-QR-01',
            'name' => 'QR Attendance',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $admin = $this->createUserWithRole('admin', $branch);
        $permission = Permission::query()->where('slug', 'attendance.manage')->value('id');
        $admin->roles()->first()->permissions()->syncWithoutDetaching([$permission]);

        $studentUser = $this->createUserWithRole('student', $branch);
        $student = Student::query()->create([
            'user_id' => $studentUser->id,
            'branch_id' => $branch,
            'student_code' => 'ATT-QR-STU-01',
            'admission_date' => now()->toDateString(),
            'status' => 'active',
        ]);
        $course = DB::table('courses')->insertGetId([
            'branch_id' => $branch,
            'code' => 'ATT-QR-COURSE',
            'title' => 'QR Attendance Course',
            'slug' => 'qr-attendance-course',
            'price' => 0,
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $batch = DB::table('batches')->insertGetId([
            'course_id' => $course,
            'branch_id' => $branch,
            'name' => 'QR Attendance Batch',
            'capacity' => 20,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $mapping = AttendanceBatchMapping::resolve('course_batch', $batch, $branch, 'QR Attendance Batch');

        $this->actingAs($admin)->putJson("/admin/attendance/batches/{$mapping->id}/roster", [
            'student_ids' => [$student->id],
        ])->assertOk()->assertJson(['assigned' => 1]);

        $issued = $this->actingAs($admin)->postJson("/admin/attendance/batches/{$mapping->id}/qr", [
            'expires_in_minutes' => 5,
            'latitude' => 23.8103,
            'longitude' => 90.4125,
            'radius_meters' => 100,
        ])->assertOk()->json();
        $this->actingAs($studentUser)->postJson('/attendance/qr/check-in', [
            'token' => $issued['token'],
            'gps_consent' => true,
            'latitude' => 23.8103,
            'longitude' => 90.4125,
            'gps_accuracy_meters' => 5,
        ])->assertOk()->assertJson(['status' => 'ok']);

        $this->assertDatabaseHas('attendance_records', [
            'batch_id' => $batch,
            'student_id' => $student->id,
            'status' => 'present',
            'source' => 'qr',
        ]);
        $this->assertDatabaseHas('attendance_gps_consents', ['user_id' => $studentUser->id, 'policy_version' => 'attendance-gps-v1']);
        $this->assertDatabaseHas('attendance_qr_checkins', ['student_id' => $student->id, 'gps_verified' => true, 'gps_accuracy_meters' => 5]);
        $this->actingAs($studentUser)->postJson('/attendance/qr/check-in', [
            'token' => $issued['token'],
        ])->assertStatus(409);

        $expired = $this->actingAs($admin)->postJson("/admin/attendance/batches/{$mapping->id}/qr", [
            'expires_in_minutes' => 5,
        ])->assertOk()->json();
        DB::table('attendance_qr_sessions')->where('id', $expired['session_id'])->update(['expires_at' => now()->subMinute()]);
        $this->actingAs($studentUser)->postJson('/attendance/qr/check-in', [
            'token' => $expired['token'],
        ])->assertStatus(410);

        $outside = $this->actingAs($admin)->postJson("/admin/attendance/batches/{$mapping->id}/qr", [
            'expires_in_minutes' => 5,
            'latitude' => 23.8103,
            'longitude' => 90.4125,
            'radius_meters' => 100,
        ])->assertOk()->json();
        $this->actingAs($studentUser)->postJson('/attendance/qr/check-in', [
            'token' => $outside['token'],
            'gps_consent' => true,
            'latitude' => 23.8203,
            'longitude' => 90.4125,
            'gps_accuracy_meters' => 5,
        ])->assertStatus(403);

        $revoked = $this->actingAs($admin)->postJson("/admin/attendance/batches/{$mapping->id}/qr", [
            'expires_in_minutes' => 5,
        ])->assertOk()->json();
        $this->actingAs($admin)->deleteJson("/admin/attendance/qr-sessions/{$revoked['session_id']}")->assertOk();
        $this->actingAs($studentUser)->postJson('/attendance/qr/check-in', [
            'token' => $revoked['token'],
        ])->assertStatus(410);

        $this->actingAs($studentUser)->deleteJson('/attendance/gps-consent')->assertOk();
        $this->assertDatabaseHas('attendance_gps_consents', [
            'user_id' => $studentUser->id,
        ]);
        $this->assertNotNull(DB::table('attendance_gps_consents')->where('user_id', $studentUser->id)->value('revoked_at'));

        // The study-batch table may reuse the same numeric ID as a course batch.
        // The explicit source discriminator must keep those identities separate.
        $studyBatch = DB::table('study_batches')->insertGetId([
            'branch_id' => $branch,
            'course_title' => 'QR Study Batch',
            'batch_code' => 'ATT-QR-STUDY-01',
            'name' => 'QR Study Batch',
            'schedule' => 'Sun/Tue 10:00',
            'max_capacity' => 20,
            'enrolled_count' => 1,
            'status' => 'ongoing',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $studyMapping = AttendanceBatchMapping::resolve('study_batch', $studyBatch, $branch, 'QR Study Batch');
        $this->assertSame($mapping->source_id, $studyMapping->source_id);
        $this->assertNotSame($mapping->id, $studyMapping->id);

        $this->actingAs($admin)->putJson("/admin/attendance/batches/{$studyMapping->id}/roster", [
            'student_ids' => [$student->id],
        ])->assertOk();
        $studyQr = $this->actingAs($admin)->postJson("/admin/attendance/batches/{$studyMapping->id}/qr", [
            'expires_in_minutes' => 5,
        ])->assertOk()->json();
        $this->actingAs($studentUser)->postJson('/attendance/qr/check-in', [
            'token' => $studyQr['token'],
        ])->assertOk();

        $this->assertDatabaseHas('attendance_entries', [
            'attendance_batch_mapping_id' => $studyMapping->id,
            'student_id' => $student->id,
            'status' => 'present',
            'source' => 'qr',
        ]);
        $this->assertDatabaseHas('attendance_qr_checkins', [
            'student_id' => $student->id,
            'attendance_record_id' => null,
        ]);

        $this->actingAs($admin)->postJson('/admin/attendance/sync', [
            'batch_mapping_id' => $studyMapping->id,
            'attendance_date' => now()->toDateString(),
            'source' => 'offline',
            'records' => [[
                'student_id' => $student->id,
                'status' => 'present',
                'remarks' => 'QR check-in',
                'sync_uuid' => 'b0e6bdf8-45d2-4f7c-bf1f-9a3d77a0a001',
            ]],
        ])->assertOk()->assertJson(['status' => 'ok', 'synced' => 1, 'duplicates' => 0]);
        $this->assertDatabaseHas('attendance_entries', [
            'attendance_batch_mapping_id' => $studyMapping->id,
            'student_id' => $student->id,
            'source' => 'offline',
            'sync_uuid' => 'b0e6bdf8-45d2-4f7c-bf1f-9a3d77a0a001',
        ]);

        $guardianUser = $this->createUserWithRole('guardian', $branch);
        $guardian = Guardian::query()->create([
            'user_id' => $guardianUser->id,
            'branch_id' => $branch,
            'relationship' => 'parent',
        ]);
        DB::table('guardian_student')->insert([
            'guardian_id' => $guardian->id,
            'student_id' => $student->id,
            'is_primary' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($guardianUser)->get("/attendance/guardian/{$student->id}")->assertOk()->assertSee('QR Study Batch');
        $otherStudentUser = $this->createUserWithRole('student', $branch);
        $otherStudent = Student::query()->create([
            'user_id' => $otherStudentUser->id,
            'branch_id' => $branch,
            'student_code' => 'ATT-QR-STU-02',
            'admission_date' => now()->toDateString(),
            'status' => 'active',
        ]);
        $this->actingAs($guardianUser)->get("/attendance/guardian/{$otherStudent->id}")->assertForbidden();
    }

}
