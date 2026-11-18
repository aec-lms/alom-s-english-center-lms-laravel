<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LearningInteractionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->markSystemInstalled();
        $this->activatePlugins(['course-management']);
    }

    public function test_active_student_can_comment_on_a_lesson_and_unenrolled_student_cannot_read_it(): void
    {
        [$student, $courseId, $lessonId] = $this->createCourseLesson('active');

        $this->actingAs($student)
            ->postJson(route('plugins.learning-interactions.lesson.comments.store', $lessonId), ['body' => 'This grammar explanation is helpful.'])
            ->assertCreated()
            ->assertJsonPath('comment.body', 'This grammar explanation is helpful.');

        $other = $this->createUserWithRole('student');
        $this->actingAs($other)
            ->getJson(route('plugins.learning-interactions.lesson.comments.index', $lessonId))
            ->assertForbidden();

        $this->assertDatabaseHas('course_lesson_comments', [
            'course_id' => $courseId,
            'lesson_id' => $lessonId,
            'user_id' => $student->id,
            'status' => 'visible',
        ]);
    }

    public function test_student_cannot_post_comments_to_another_courses_lesson(): void
    {
        [, , $lessonId] = $this->createCourseLesson('active');
        $other = $this->createUserWithRole('student');

        $this->actingAs($other)
            ->postJson(route('plugins.learning-interactions.lesson.comments.store', $lessonId), ['body' => 'Unauthorized comment'])
            ->assertForbidden();
    }

    public function test_live_class_questions_require_an_active_lms_participant(): void
    {
        [$student, $courseId] = $this->createCourseLesson('active');
        $liveClassId = $this->createLiveClass($courseId, 'Instructor');

        $this->actingAs($student)
            ->postJson(route('plugins.learning-interactions.live.questions.store', $liveClassId), ['body' => 'Could you explain this example?'])
            ->assertForbidden();

        DB::table('live_class_participants')->insert([
            'live_class_id' => $liveClassId,
            'user_id' => $student->id,
            'joined_at' => now(),
            'last_seen_at' => now(),
            'left_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($student)
            ->postJson(route('plugins.learning-interactions.live.questions.store', $liveClassId), ['body' => 'Could you explain this example?'])
            ->assertCreated()
            ->assertJsonPath('question.body', 'Could you explain this example?');
    }

    public function test_assigned_instructor_can_answer_without_consuming_a_student_seat(): void
    {
        [$student, $courseId] = $this->createCourseLesson('active');
        $teacher = $this->createUserWithRole('teacher');
        $liveClassId = $this->createLiveClass($courseId, $teacher->name);

        $this->actingAs($student)->postJson(route('plugins.learning-interactions.live.questions.store', $liveClassId), ['body' => 'What is the correct form?'])
            ->assertForbidden();

        DB::table('live_class_participants')->insert([
            'live_class_id' => $liveClassId,
            'user_id' => $student->id,
            'joined_at' => now(),
            'last_seen_at' => now(),
            'left_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $questionId = $this->actingAs($student)
            ->postJson(route('plugins.learning-interactions.live.questions.store', $liveClassId), ['body' => 'What is the correct form?'])
            ->assertCreated()
            ->json('question.id');

        $this->actingAs($teacher)
            ->getJson(route('plugins.learning-interactions.live.questions.index', $liveClassId))
            ->assertOk()
            ->assertJsonPath('can_answer', true);

        $this->actingAs($teacher)
            ->postJson(route('plugins.learning-interactions.live.questions.answer', [$liveClassId, $questionId]), ['body' => 'Use the present perfect here.'])
            ->assertCreated();

        $this->assertDatabaseHas('live_class_discussions', [
            'live_class_id' => $liveClassId,
            'parent_id' => $questionId,
            'user_id' => $teacher->id,
            'body' => 'Use the present perfect here.',
        ]);
    }

    private function createCourseLesson(string $enrollmentStatus): array
    {
        $student = $this->createUserWithRole('student');
        $courseId = DB::table('courses')->insertGetId([
            'code' => 'DISC-' . strtoupper(substr(uniqid(), -8)),
            'title' => 'Discussion Course',
            'slug' => 'discussion-course-' . uniqid(),
            'description' => 'Test course',
            'price' => 0,
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $lessonId = DB::table('course_lessons')->insertGetId([
            'course_id' => $courseId,
            'title' => 'Discussion Lesson',
            'content' => null,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('enrollments')->insert([
            'user_id' => $student->id,
            'course_id' => $courseId,
            'status' => $enrollmentStatus,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return [$student, $courseId, $lessonId];
    }

    private function createLiveClass(int $courseId, string $instructor): int
    {
        return DB::table('live_classes')->insertGetId([
            'course_id' => $courseId,
            'title' => 'Test Live Class',
            'instructor_name' => $instructor,
            'platform' => 'zoom',
            'meeting_url' => 'https://example.test/meeting',
            'recording_url' => null,
            'starts_at' => now()->subMinute(),
            'capacity' => 30,
            'status' => 'live',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
