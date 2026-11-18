<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CourseContentStudioPluginTest extends TestCase
{
    private function course(): int
    {
        return DB::table('courses')->insertGetId([
            'code'=>'CCS-'.strtoupper(bin2hex(random_bytes(3))),
            'title'=>'Course Content Studio Test',
            'slug'=>'course-content-studio-'.bin2hex(random_bytes(3)),
            'description'=>'Test course',
            'price'=>0,
            'status'=>'published',
            'created_at'=>now(),
            'updated_at'=>now(),
        ]);
    }

    private function post(int $authorId,string $slug='content-studio-lesson'): int
    {
        return DB::table('course_content_posts')->insertGetId([
            'author_id'=>$authorId,
            'title'=>'Course Content Studio Lesson',
            'slug'=>$slug,
            'short_description'=>'A reusable lesson.',
            'long_description'=>'Lesson content for the student.',
            'access_type'=>'course_enrollment',
            'status'=>'published',
            'published_at'=>now()->subMinute(),
            'created_at'=>now(),
            'updated_at'=>now(),
        ]);
    }

    public function test_content_studio_installs_without_course_management(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['course-content-studio']);

        $this->assertTrue(Schema::hasTable('course_content_posts'));
        $this->assertTrue(Schema::hasTable('course_content_categories'));
        $this->assertTrue(Schema::hasTable('course_curriculum_items'));
        $this->assertTrue(Schema::hasTable('course_content_progress'));
    }

    public function test_admin_can_create_reusable_content_post_with_category_and_safe_video(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['course-content-studio']);
        $admin=$this->createUserWithRole('admin');
        $category=DB::table('course_content_categories')->insertGetId([
            'name'=>'Grammar','slug'=>'grammar','sort_order'=>1,'is_visible'=>true,'created_at'=>now(),'updated_at'=>now(),
        ]);

        $this->actingAs($admin)->post('/admin/course-content-studio/posts',[
            'title'=>'Present Perfect Lesson',
            'long_description'=>'Learn the present perfect tense.',
            'video_url'=>'https://youtu.be/dQw4w9WgXcQ',
            'access_type'=>'course_enrollment',
            'status'=>'published',
            'tags'=>'grammar, suffix, grammar',
            'category_ids'=>[$category],
        ])->assertRedirect('/admin/course-content-studio')->assertSessionHasNoErrors();

        $this->assertDatabaseHas('course_content_posts',[
            'title'=>'Present Perfect Lesson',
            'slug'=>'present-perfect-lesson',
            'status'=>'published',
        ]);
        $postId=DB::table('course_content_posts')->where('slug','present-perfect-lesson')->value('id');
        $this->assertDatabaseHas('course_content_post_category',['course_content_post_id'=>$postId,'category_id'=>$category]);
        $this->assertSame(['grammar','suffix'],json_decode(DB::table('course_content_posts')->where('id',$postId)->value('tags'),true));
    }

    public function test_course_builder_adds_and_disables_lessons_without_deleting_progress_history(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['course-management','course-content-studio']);
        $admin=$this->createUserWithRole('admin');
        $course=$this->course();
        $post=$this->post($admin->id);

        $this->actingAs($admin)->put('/admin/course-content-studio/courses/'.$course.'/builder',[
            'add_post_ids'=>[$post],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $itemId=DB::table('course_curriculum_items')->where('course_id',$course)->where('course_content_post_id',$post)->value('id');
        $student=$this->createUserWithRole('student');
        DB::table('course_content_progress')->insert([
            'user_id'=>$student->id,'course_curriculum_item_id'=>$itemId,'last_viewed_at'=>now(),'completed_at'=>now(),'created_at'=>now(),'updated_at'=>now(),
        ]);

        $this->actingAs($admin)->put('/admin/course-content-studio/courses/'.$course.'/builder',[
            'items'=>[[
                'post_id'=>$post,'section_title'=>'Module 1','position'=>20,
                'duration_minutes'=>15,'is_preview'=>false,'is_active'=>false,
            ]],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('course_curriculum_items',['id'=>$itemId,'is_active'=>0]);
        $this->assertDatabaseHas('course_content_progress',['user_id'=>$student->id,'course_curriculum_item_id'=>$itemId]);
    }

    public function test_enrolled_student_can_view_complete_and_resume_a_lesson(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['course-management','course-content-studio']);
        $teacher=$this->createUserWithRole('teacher');
        $student=$this->createUserWithRole('student');
        $course=$this->course();
        $post=$this->post($teacher->id,'student-learning-lesson');
        DB::table('enrollments')->insert([
            'user_id'=>$student->id,'course_id'=>$course,'status'=>'active','created_at'=>now(),'updated_at'=>now(),
        ]);
        $item=DB::table('course_curriculum_items')->insertGetId([
            'course_id'=>$course,'course_content_post_id'=>$post,'position'=>10,'is_preview'=>false,'is_active'=>true,'created_at'=>now(),'updated_at'=>now(),
        ]);

        $this->actingAs($student)->get('/courses/'.DB::table('courses')->where('id',$course)->value('slug').'/content/student-learning-lesson')
            ->assertOk()->assertSee('Course Content Studio Lesson');

        $this->actingAs($student)->post('/courses/'.DB::table('courses')->where('id',$course)->value('slug').'/content/student-learning-lesson/complete')
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('course_content_progress',[
            'user_id'=>$student->id,'course_curriculum_item_id'=>$item,
        ]);
        $this->assertNotNull(DB::table('course_content_progress')->where('user_id',$student->id)->where('course_curriculum_item_id',$item)->value('completed_at'));
    }

    public function test_unenrolled_student_cannot_open_locked_content_or_mark_preview_complete(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['course-management','course-content-studio']);
        $admin=$this->createUserWithRole('admin');
        $student=$this->createUserWithRole('student');
        $course=$this->course();
        $post=$this->post($admin->id,'locked-course-lesson');
        DB::table('course_curriculum_items')->insert([
            'course_id'=>$course,'course_content_post_id'=>$post,'position'=>10,'is_preview'=>false,'is_active'=>true,'created_at'=>now(),'updated_at'=>now(),
        ]);

        $slug=DB::table('courses')->where('id',$course)->value('slug');
        $this->actingAs($student)->get('/courses/'.$slug.'/content/locked-course-lesson')->assertForbidden();
        $this->assertDatabaseCount('course_content_progress',0);
    }
}
