<?php

declare(strict_types=1);

namespace Tests\\Feature;

use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Facades\\Hash;
use Plugins\\Modules\\StudentManagement\\Models\\Student;
use Tests\\TestCase;

final class StudentManagementAuditTrailTest extends TestCase
{
    public function test_student_updates_audit_only_allowlisted_lifecycle_diffs(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'student-management']);
        $admin = $this->createUserWithRole('admin');

        $branchA = DB::table('branches')->insertGetId([
            'code' => 'AUDIT-A-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
            'name' => 'Audit Branch A',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $branchB = DB::table('branches')->insertGetId([
            'code' => 'AUDIT-B-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
            'name' => 'Audit Branch B',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)->post('/admin/students', [
            'name' => 'Audit Trail Learner',
            'email' => 'audit.before@aec.test',
            'phone' => '01711112222',
            'password' => 'InitialPassword123!',
            'student_code' => 'AUDIT-BEFORE-001',
            'branch_id' => $branchA,
            'status' => 'active',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $student = Student::query()->where('student_code', 'AUDIT-BEFORE-001')->firstOrFail();

        $this->actingAs($admin)->put('/admin/students/' . $student->id, [
            'name' => 'Audit Trail Learner Updated',
            'email' => 'audit.after@aec.test',
            'phone' => '01733334444',
            'password' => 'ReplacementSecret987!',
            'student_code' => 'AUDIT-AFTER-001',
            'branch_id' => $branchB,
            'status' => 'inactive',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $student->refresh();
        $student->load('user');
        $this->assertSame('AUDIT-AFTER-001', $student->student_code);
        $this->assertSame((string) $branchB, (string) $student->branch_id);
        $this->assertSame('inactive', $student->status);
        $this->assertTrue(Hash::check('ReplacementSecret987!', $student->user->password));

        $log = DB::table('audit_logs')
            ->where('action', 'student.updated')
            ->where('entity_type', 'student')
            ->where('entity_id', (string) $student->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($log);
        $metadata = json_decode((string) $log->metadata, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([
            'student_code' => ['from' => 'AUDIT-BEFORE-001', 'to' => 'AUDIT-AFTER-001'],
            'branch_id' => ['from' => (string) $branchA, 'to' => (string) $branchB],
            'status' => ['from' => 'active', 'to' => 'inactive'],
        ], $metadata['changes']);

        $serializedMetadata = (string) $log->metadata;
        $this->assertStringNotContainsString('ReplacementSecret987!', $serializedMetadata);
        $this->assertStringNotContainsString('InitialPassword123!', $serializedMetadata);
        $this->assertStringNotContainsString('audit.before@aec.test', $serializedMetadata);
        $this->assertStringNotContainsString('audit.after@aec.test', $serializedMetadata);
        $this->assertStringNotContainsString('01711112222', $serializedMetadata);
        $this->assertStringNotContainsString('01733334444', $serializedMetadata);
    }
}
