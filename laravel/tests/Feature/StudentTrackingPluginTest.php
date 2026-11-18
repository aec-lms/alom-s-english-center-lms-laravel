<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Plugins\PluginManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Plugins\Modules\StudentTracking\Models\StudentActivityEvent;
use Plugins\Modules\StudentTracking\Services\StudentActivityTracker;
use Tests\TestCase;

class StudentTrackingPluginTest extends TestCase
{
    public function test_student_tracking_installs_without_academic_sibling_plugins_and_records_safe_events(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['student-tracking']);
        $admin = $this->createUserWithRole('admin');
        $student = $this->createUserWithRole('student');

        $manager = app(PluginManager::class);
        $this->assertTrue($manager->isInstalled('student-tracking'));
        $this->assertTrue($manager->isEnabled('student-tracking'));
        $this->assertFalse($manager->isInstalled('student-management'));
        $this->assertFalse($manager->isInstalled('attendance-management'));
        $this->assertFalse($manager->isInstalled('course-management'));

        $this->actingAs($student)->postJson('/student-tracking/events', [
            'event_type' => 'lesson_completed',
            'subject_type' => 'lesson',
            'subject_id' => 'lesson-42',
            'duration_seconds' => 420,
            'metadata' => [
                'course_title' => 'SSC English Grammar',
                'result_percent' => 92,
                'password' => 'must-not-be-stored',
                'precise_location' => 'must-not-be-stored',
            ],
        ])->assertCreated()->assertJsonPath('recorded', true);

        $event = StudentActivityEvent::query()->firstOrFail();
        $this->assertSame('lesson_completed', $event->event_type);
        $this->assertSame('SSC English Grammar', $event->metadata['course_title']);
        $this->assertSame('92', $event->metadata['result_percent']);
        $this->assertArrayNotHasKey('password', $event->metadata);
        $this->assertArrayNotHasKey('precise_location', $event->metadata);

        $this->actingAs($admin)->get('/admin/student-tracking')
            ->assertOk()
            ->assertSee('Student Tracking & Analytics')
            ->assertSee('SSC English Grammar');

        $otherStudent = $this->createUserWithRole('student');
        app(StudentActivityTracker::class)->record((int) $otherStudent->id, 'lesson_completed', 'lesson', 'other-lesson', 300, [
            'course_title' => 'Other Student Private Course',
        ]);

        $this->actingAs($student)->get('/student-tracking/mine')
            ->assertOk()
            ->assertSee('My Learning Activity')
            ->assertSee('SSC English Grammar')
            ->assertDontSee('Other Student Private Course');
    }


    public function test_tracking_and_tracing_plugins_keep_separate_schemas_and_capture_academic_events(): void
    {
        $this->markSystemInstalled();
        // Install Tracking first to exercise the legacy table-creation order.
        $this->activatePlugins([
            'student-tracking',
            'student-tracing',
            'student-management',
            'attendance-management',
            'course-management',
            'exam-management',
        ]);

        foreach (['user_id', 'actor_id', 'event_type', 'subject_type', 'subject_id', 'duration_seconds', 'metadata', 'occurred_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('student_activity_events', $column), "Student Tracking table is missing {$column}.");
        }
        foreach (['student_id', 'branch_id', 'actor_id', 'event_type', 'metadata', 'occurred_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('student_trace_events', $column), "Student Tracing table is missing {$column}.");
        }

        $studentUser = $this->createUserWithRole('student');
        $studentId = DB::table('students')->insertGetId([
            'user_id' => $studentUser->id,
            'student_code' => 'TRACK-TRACE-001',
            'admission_date' => now()->toDateString(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Event::dispatch('eloquent.created: Plugins\\Modules\\AttendanceManagement\\Models\\AttendanceRecord', [
            (object) ['id' => 701, 'student_id' => $studentId, 'status' => 'present'],
        ]);
        Event::dispatch('eloquent.created: Plugins\\Modules\\CourseManagement\\Models\\Enrollment', [
            (object) ['id' => 702, 'user_id' => $studentUser->id, 'course_id' => 91],
        ]);
        Event::dispatch('eloquent.created: Plugins\\Modules\\ExamManagement\\Models\\ExamAttempt', [
            (object) ['id' => 703, 'user_id' => $studentUser->id, 'exam_id' => 92, 'score_obtained' => 8, 'total_marks' => 10],
        ]);
        Event::dispatch('eloquent.created: Plugins\\Modules\\StudentManagement\\Models\\Student', [
            (object) ['id' => $studentId, 'branch_id' => null],
        ]);

        $this->assertDatabaseHas('student_activity_events', [
            'user_id' => $studentUser->id,
            'event_type' => 'attendance_marked',
            'subject_id' => '701',
        ]);
        $this->assertDatabaseHas('student_activity_events', [
            'user_id' => $studentUser->id,
            'event_type' => 'course_enrolled',
            'subject_id' => '91',
        ]);
        $this->assertDatabaseHas('student_activity_events', [
            'user_id' => $studentUser->id,
            'event_type' => 'quiz_submitted',
            'subject_id' => '92',
        ]);
        $this->assertDatabaseHas('student_trace_events', [
            'student_id' => $studentId,
            'event_type' => 'student.created',
        ]);
    }

    public function test_guardian_can_only_view_activity_for_linked_students(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['student-tracking', 'guardian-management', 'student-management']);

        $guardianUser = $this->createUserWithRole('guardian');
        $guardianId = DB::table('guardians')->insertGetId([
            'user_id' => $guardianUser->id,
            'branch_id' => null,
            'relationship' => 'Parent',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $linkedStudentUser = $this->createUserWithRole('student');
        $linkedStudentId = DB::table('students')->insertGetId([
            'user_id' => $linkedStudentUser->id,
            'student_code' => 'GUARDIAN-LINKED-001',
            'admission_date' => now()->toDateString(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('guardian_student')->insert([
            'guardian_id' => $guardianId,
            'student_id' => $linkedStudentId,
            'is_primary' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $unlinkedStudent = $this->createUserWithRole('student');
        app(StudentActivityTracker::class)->record((int) $linkedStudentUser->id, 'lesson_completed', 'lesson', 'linked-lesson', 180, [
            'course_title' => 'Linked Student Course',
        ]);
        app(StudentActivityTracker::class)->record((int) $unlinkedStudent->id, 'lesson_completed', 'lesson', 'private-lesson', 240, [
            'course_title' => 'Unlinked Student Private Course',
        ]);

        $this->actingAs($guardianUser)->get('/student-tracking/guardian')
            ->assertOk()
            ->assertSee('Linked Student Course')
            ->assertDontSee('Unlinked Student Private Course');
    }

    public function test_student_tracking_rejects_unknown_event_types(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['student-tracking']);
        $student = $this->createUserWithRole('student');

        $this->actingAs($student)->postJson('/student-tracking/events', [
            'event_type' => 'quiz_submitted',
        ])->assertUnprocessable();

        $this->assertDatabaseCount('student_activity_events', 0);
    }

    public function test_student_can_record_lesson_started_activity_without_spoofing_another_user(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['student-tracking']);
        $student = $this->createUserWithRole('student');

        $this->actingAs($student)->postJson('/student-tracking/events', [
            'event_type' => 'lesson_started',
            'subject_type' => 'lesson',
            'subject_id' => 'lesson-77',
            'duration_seconds' => 0,
            'metadata' => ['lesson_title' => 'Introduction to Tense'],
        ])->assertCreated()->assertJsonPath('recorded', true);

        $this->assertDatabaseHas('student_activity_events', [
            'user_id' => $student->id,
            'event_type' => 'lesson_started',
            'subject_type' => 'lesson',
            'subject_id' => 'lesson-77',
        ]);
    }

    public function test_course_enabled_tracking_requires_real_enrollment_and_uses_canonical_lesson_metadata(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['student-tracking', 'course-management']);

        $student = $this->createUserWithRole('student');
        $otherStudent = $this->createUserWithRole('student');
        $courseId = DB::table('courses')->insertGetId([
            'code' => 'TRACK-REAL-001',
            'title' => 'Canonical SSC English Course',
            'slug' => 'canonical-ssc-english-course',
            'description' => 'Course used by the tracking integrity regression test.',
            'price' => 0,
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $lessonId = DB::table('course_lessons')->insertGetId([
            'course_id' => $courseId,
            'title' => 'Canonical Tense Lesson',
            'content' => 'Lesson content',
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('enrollments')->insert([
            'user_id' => $student->id,
            'course_id' => $courseId,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($student)->postJson('/student-tracking/events', [
            'event_type' => 'lesson_completed',
            'subject_type' => 'lesson',
            'subject_id' => (string) $lessonId,
            'duration_seconds' => 900,
            'metadata' => [
                'course_title' => 'Forged Course Title',
                'lesson_title' => 'Forged Lesson Title',
                'result_percent' => 100,
            ],
        ])->assertCreated();

        $event = StudentActivityEvent::query()->firstOrFail();
        $this->assertSame('Canonical SSC English Course', $event->metadata['course_title']);
        $this->assertSame('Canonical Tense Lesson', $event->metadata['lesson_title']);
        $this->assertSame('student_portal', $event->metadata['source']);
        $this->assertArrayNotHasKey('result_percent', $event->metadata);

        $this->actingAs($otherStudent)->postJson('/student-tracking/events', [
            'event_type' => 'lesson_completed',
            'subject_type' => 'lesson',
            'subject_id' => (string) $lessonId,
            'duration_seconds' => 900,
        ])->assertForbidden();

        $this->actingAs($student)->postJson('/student-tracking/events', [
            'event_type' => 'lesson_completed',
            'subject_type' => 'lesson',
            'subject_id' => 'lesson-not-real',
            'duration_seconds' => 900,
        ])->assertUnprocessable();

        $this->assertDatabaseCount('student_activity_events', 1);
    }

}
