<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Plugins\Modules\CourseManagement\Models\Course;
use Plugins\Modules\CourseManagement\Models\Enrollment;
use Tests\TestCase;

class CourseSocialProofAnalyticsTest extends TestCase
{
    public function test_course_detail_records_one_view_per_session_per_day_and_excludes_unpaid_enrollments(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['course-management', 'fees-management']);

        $student = $this->createUserWithRole('student');
        $course = Course::query()->create([
            'code' => 'SOCIAL-PROOF-01',
            'title' => 'Social Proof Test Course',
            'slug' => 'social-proof-test-course',
            'price' => 0,
            'status' => 'published',
        ]);

        Enrollment::query()->create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
        ]);
        $pendingStudent = $this->createUserWithRole('student');
        Enrollment::query()->create([
            'user_id' => $pendingStudent->id,
            'course_id' => $course->id,
            'status' => 'pending',
        ]);

        $visitorId = str_repeat('a', 64);
        $this->withSession(['course_view_visitor_id' => $visitorId])
            ->get('/course/' . $course->slug)->assertOk()->assertSeeText('0 purchased');
        $this->withSession(['course_view_visitor_id' => $visitorId])
            ->get('/course/' . $course->slug)->assertOk();

        $this->assertSame(1, DB::table('course_view_events')->where('course_id', $course->id)->count());
        $this->assertSame(1, DB::table('enrollments')->where('course_id', $course->id)->where('status', 'active')->count());
    }

    public function test_admin_can_independently_toggle_course_views_and_purchase_counts(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['course-management']);

        $admin = $this->createUserWithRole('admin');
        $this->actingAs($admin)->get('/admin/course-social-proof')->assertOk()
            ->assertSeeText('Course Views & Purchase Display');

        $this->actingAs($admin)->post('/admin/course-social-proof', [
            'enabled' => '1',
            'show_views' => '1',
            'show_on_cards' => '1',
            'show_on_details' => '1',
        ])->assertRedirect('/admin/course-social-proof');

        $stored = json_decode((string) DB::table('system_settings')->where('key', 'course.social_proof')->value('value'), true);
        $this->assertSame(true, $stored['enabled']);
        $this->assertSame(true, $stored['show_views']);
        $this->assertSame(false, $stored['show_purchases']);
    }

    public function test_branch_manager_cannot_change_global_course_social_proof_settings(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['course-management']);

        $manager = $this->createUserWithRole('branch_manager');
        $this->actingAs($manager)->get('/admin/course-social-proof')->assertForbidden();
        $this->actingAs($manager)->post('/admin/course-social-proof', ['enabled' => '1'])->assertForbidden();
    }
}
