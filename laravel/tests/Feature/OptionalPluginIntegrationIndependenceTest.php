<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OptionalPluginIntegrationIndependenceTest extends TestCase
{
    public function test_guardian_management_can_be_installed_before_student_management(): void
    {
        $this->markSystemInstalled();

        $this->activatePlugins(['guardian-management']);

        $this->assertTrue(Schema::hasTable('guardians'));
        $this->assertTrue(Schema::hasTable('guardian_student'));
        $this->assertFalse(Schema::hasTable('students'));

        $this->activatePlugins(['student-management']);

        $this->assertTrue(Schema::hasTable('students'));
        $this->assertTrue(Schema::hasTable('guardian_student'));

        $guardianUser = $this->createUserWithRole('guardian');
        $studentUser = $this->createUserWithRole('student');

        $guardianId = DB::table('guardians')->insertGetId([
            'user_id' => $guardianUser->id,
            'relationship' => 'parent',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $studentId = DB::table('students')->insertGetId([
            'user_id' => $studentUser->id,
            'student_code' => 'OPTIONAL-LINK-001',
            'admission_date' => now()->toDateString(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('guardian_student')->insert([
            'guardian_id' => $guardianId,
            'student_id' => $studentId,
            'is_primary' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseHas('guardian_student', [
            'guardian_id' => $guardianId,
            'student_id' => $studentId,
            'is_primary' => true,
        ]);
    }
}
