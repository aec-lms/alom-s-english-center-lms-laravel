<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class StudentTrackingAcademicEventHooksTest extends TestCase
{
    public function test_live_class_participation_and_issued_certificate_are_recorded(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins([
            'student-tracking',
            'student-management',
            'course-management',
            'certificate-management',
        ]);

        $studentUser = $this->createUserWithRole('student');
        DB::table('students')->insert([
            'user_id' => $studentUser->id,
            'student_code' => 'TRACK-HOOK-001',
            'admission_date' => now()->toDateString(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Event::dispatch('eloquent.created: Plugins\\Modules\\CourseManagement\\Models\\LiveClassParticipant', [
            (object) ['id' => 801, 'user_id' => $studentUser->id, 'live_class_id' => 51],
        ]);

        Event::dispatch('eloquent.created: Plugins\\Modules\\CertificateManagement\\Models\\CertificateRecord', [
            (object) [
                'id' => 802,
                'student_code' => 'TRACK-HOOK-001',
                'course_title' => 'HSC English Masterclass',
                'status' => 'issued',
            ],
        ]);

        $this->assertDatabaseHas('student_activity_events', [
            'user_id' => $studentUser->id,
            'event_type' => 'live_class_joined',
            'subject_type' => 'live_class',
            'subject_id' => '51',
        ]);
        $this->assertDatabaseHas('student_activity_events', [
            'user_id' => $studentUser->id,
            'event_type' => 'certificate_earned',
            'subject_type' => 'certificate',
            'subject_id' => '802',
        ]);
    }

    public function test_revoked_certificate_does_not_create_earned_activity(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['student-tracking', 'student-management', 'certificate-management']);

        $studentUser = $this->createUserWithRole('student');
        DB::table('students')->insert([
            'user_id' => $studentUser->id,
            'student_code' => 'TRACK-HOOK-002',
            'admission_date' => now()->toDateString(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Event::dispatch('eloquent.created: Plugins\\Modules\\CertificateManagement\\Models\\CertificateRecord', [
            (object) [
                'id' => 803,
                'student_code' => 'TRACK-HOOK-002',
                'course_title' => 'Revoked Certificate Course',
                'status' => 'revoked',
            ],
        ]);

        $this->assertDatabaseMissing('student_activity_events', [
            'user_id' => $studentUser->id,
            'event_type' => 'certificate_earned',
            'subject_id' => '803',
        ]);
    }
}
