<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Plugins\Modules\ExamManagement\Models\Exam;
use Tests\TestCase;

class MobileApiBranchIsolationTest extends TestCase
{
    public function test_mobile_catalog_and_exam_endpoints_use_student_profile_branch(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins([
            'branch-management',
            'student-management',
            'course-management',
            'exam-management',
            'mobile-app-api',
        ]);

        $branchA = DB::table('branches')->insertGetId([
            'code' => 'MOBILE-A-' . strtoupper(uniqid()),
            'name' => 'Mobile Branch A',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $branchB = DB::table('branches')->insertGetId([
            'code' => 'MOBILE-B-' . strtoupper(uniqid()),
            'name' => 'Mobile Branch B',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $student = $this->createUserWithRole('student', (int) $branchB);
        DB::table('students')->insert([
            'user_id' => $student->id,
            'branch_id' => $branchA,
            'student_code' => 'MOBILE-' . strtoupper(uniqid()),
            'admission_date' => now()->toDateString(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $courseA = DB::table('courses')->insertGetId([
            'code' => 'MOB-A-' . strtoupper(substr(uniqid(), -8)),
            'title' => 'Mobile Branch A Course',
            'slug' => 'mobile-branch-a-' . uniqid(),
            'description' => 'Visible to the profile branch only.',
            'price' => 0,
            'status' => 'published',
            'branch_id' => $branchA,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $courseB = DB::table('courses')->insertGetId([
            'code' => 'MOB-B-' . strtoupper(substr(uniqid(), -8)),
            'title' => 'Mobile Branch B Course',
            'slug' => 'mobile-branch-b-' . uniqid(),
            'description' => 'Must not be visible to branch A.',
            'price' => 0,
            'status' => 'published',
            'branch_id' => $branchB,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $globalCourse = DB::table('courses')->insertGetId([
            'code' => 'MOB-G-' . strtoupper(substr(uniqid(), -8)),
            'title' => 'Global Mobile Course',
            'slug' => 'mobile-global-' . uniqid(),
            'description' => 'Global catalog content.',
            'price' => 0,
            'status' => 'published',
            'branch_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('course_lessons')->insert([
            'course_id' => $globalCourse,
            'title' => 'Global Lesson',
            'content' => 'Lesson available through a branch enrollment.',
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('enrollments')->insert([
            'user_id' => $student->id,
            'course_id' => $globalCourse,
            'branch_id' => $branchA,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Exam::query()->create([
            'branch_id' => $branchA,
            'title' => 'Mobile Branch A Exam',
            'duration_minutes' => 30,
            'pass_marks' => 10,
            'is_published' => true,
        ]);
        Exam::query()->create([
            'branch_id' => $branchB,
            'title' => 'Mobile Branch B Exam',
            'duration_minutes' => 30,
            'pass_marks' => 10,
            'is_published' => true,
        ]);
        Exam::query()->create([
            'branch_id' => null,
            'title' => 'Global Mobile Exam',
            'duration_minutes' => 30,
            'pass_marks' => 10,
            'is_published' => true,
        ]);

        $this->getJson('/api/v1/courses')
            ->assertOk()
            ->assertJsonFragment(['title' => 'Global Mobile Course'])
            ->assertJsonMissing(['title' => 'Mobile Branch A Course'])
            ->assertJsonMissing(['title' => 'Mobile Branch B Course']);
        $this->getJson('/api/v1/exams')
            ->assertOk()
            ->assertJsonFragment(['title' => 'Global Mobile Exam'])
            ->assertJsonMissing(['title' => 'Mobile Branch A Exam'])
            ->assertJsonMissing(['title' => 'Mobile Branch B Exam']);

        $token = $student->createToken('mobile-branch-test')->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/courses')
            ->assertOk()
            ->assertJsonFragment(['title' => 'Mobile Branch A Course'])
            ->assertJsonFragment(['title' => 'Global Mobile Course'])
            ->assertJsonMissing(['title' => 'Mobile Branch B Course']);
        $this->withToken($token)->getJson('/api/v1/exams')
            ->assertOk()
            ->assertJsonFragment(['title' => 'Mobile Branch A Exam'])
            ->assertJsonFragment(['title' => 'Global Mobile Exam'])
            ->assertJsonMissing(['title' => 'Mobile Branch B Exam']);
        $this->withToken($token)->getJson('/api/v1/courses/' . DB::table('courses')->where('id', $courseA)->value('slug'))
            ->assertOk();
        $this->withToken($token)->getJson('/api/v1/courses/' . DB::table('courses')->where('id', $courseB)->value('slug'))
            ->assertNotFound();

        $globalSlug = DB::table('courses')->where('id', $globalCourse)->value('slug');
        $this->withToken($token)->getJson('/api/v1/courses/' . $globalSlug . '/lessons')->assertOk();

        DB::table('students')->where('user_id', $student->id)->update([
            'branch_id' => $branchB,
            'updated_at' => now(),
        ]);
        $this->withToken($token)->getJson('/api/v1/courses/' . $globalSlug . '/lessons')->assertForbidden();
    }

    public function test_mobile_exam_endpoint_is_safe_when_exam_plugin_is_disabled(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['mobile-app-api']);

        $this->getJson('/api/v1/exams')
            ->assertOk()
            ->assertJsonPath('data', []);
    }
}
