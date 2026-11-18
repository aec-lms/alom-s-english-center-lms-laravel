<?php

declare(strict_types=1);

namespace Tests\\Feature;

use Illuminate\\Support\\Facades\\DB;
use Tests\\TestCase;

class CourseWishlistPluginTest extends TestCase
{
    private function setupWishlist(): array
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['course-management', 'course-wishlist']);

        $student = $this->createUserWithRole('student');
        $teacher = $this->createUserWithRole('teacher');

        $courseId = DB::table('courses')->insertGetId([
            'branch_id' => null,
            'code' => 'WISH-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8)),
            'title' => 'Wishlist English Course',
            'slug' => 'wishlist-english-' . bin2hex(random_bytes(3)),
            'description' => 'A course saved for later.',
            'price' => 1200,
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$student, $teacher, $courseId];
    }

    public function test_student_can_save_course_once_and_view_their_wishlist(): void
    {
        [$student, , $courseId] = $this->setupWishlist();

        $this->actingAs($student)->post(route('plugins.course-wishlist.store', $courseId))->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($student)->post(route('plugins.course-wishlist.store', $courseId))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, DB::table('course_wishlists')->where('user_id', $student->id)->where('course_id', $courseId)->count());
        $this->actingAs($student)->get(route('plugins.course-wishlist.index'))->assertOk()->assertSee('My Wishlist')->assertSee('Wishlist English Course');
    }

    public function test_wishlist_is_private_and_students_can_only_remove_their_own_entries(): void
    {
        [$student, , $courseId] = $this->setupWishlist();
        $otherStudent = $this->createUserWithRole('student');

        $this->actingAs($student)->post(route('plugins.course-wishlist.store', $courseId))->assertRedirect();
        $this->actingAs($otherStudent)->get(route('plugins.course-wishlist.index'))->assertOk()->assertDontSee('Wishlist English Course');
        $this->actingAs($otherStudent)->delete(route('plugins.course-wishlist.destroy', $courseId))->assertRedirect();
        $this->assertDatabaseHas('course_wishlists', ['user_id' => $student->id, 'course_id' => $courseId]);
        $this->actingAs($student)->delete(route('plugins.course-wishlist.destroy', $courseId))->assertRedirect();
        $this->assertDatabaseMissing('course_wishlists', ['user_id' => $student->id, 'course_id' => $courseId]);
    }

    public function test_only_students_can_access_wishlist_and_unpublished_courses_cannot_be_saved(): void
    {
        [$student, , $courseId] = $this->setupWishlist();
        $teacher = $this->createUserWithRole('teacher');

        $this->actingAs($teacher)->get(route('plugins.course-wishlist.index'))->assertForbidden();

        DB::table('courses')->where('id', $courseId)->update(['status' => 'draft']);
        $this->actingAs($student)->post(route('plugins.course-wishlist.store', $courseId))->assertNotFound();
        $this->assertDatabaseMissing('course_wishlists', ['user_id' => $student->id, 'course_id' => $courseId]);
    }

    public function test_course_wishlist_plugin_can_be_installed_without_course_management(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['course-wishlist']);
        $student = $this->createUserWithRole('student');

        $this->assertDatabaseHas('plugins', ['slug' => 'course-wishlist', 'is_installed' => true, 'is_enabled' => true]);
        $this->actingAs($student)->get(route('plugins.course-wishlist.index'))->assertStatus(503);
    }
}
