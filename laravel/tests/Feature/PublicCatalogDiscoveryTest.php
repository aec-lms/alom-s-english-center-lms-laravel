<?php

declare(strict_types=1);

namespace Tests\\Feature;

use Plugins\\Modules\\CourseManagement\\Models\\Course;
use Plugins\\Modules\\ExamManagement\\Models\\Exam;
use Plugins\\Modules\\StorefrontCatalog\\Models\\StorefrontItem;
use Plugins\\Modules\\TeacherManagement\\Models\\Teacher;
use Tests\\TestCase;

class PublicCatalogDiscoveryTest extends TestCase
{
    public function test_public_exam_catalog_search_only_returns_published_matching_exams(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['course-management', 'exam-management']);

        $course = Course::query()->create([
            'code' => 'PUBLIC-EXAM-01',
            'title' => 'Public Exam Course',
            'slug' => 'public-exam-course',
            'price' => 0,
            'status' => 'published',
        ]);

        Exam::query()->create([
            'course_id' => $course->id,
            'title' => 'Grammar Diagnostic Public Test',
            'duration_minutes' => 30,
            'pass_marks' => 5,
            'is_published' => true,
        ]);

        Exam::query()->create([
            'course_id' => $course->id,
            'title' => 'Private IELTS Placement Test',
            'duration_minutes' => 30,
            'pass_marks' => 5,
            'is_published' => false,
        ]);

        $this->get('/exams?q=Grammar')
            ->assertOk()
            ->assertSee('Grammar Diagnostic Public Test')
            ->assertDontSee('Private IELTS Placement Test');
    }

    public function test_public_instructor_catalog_searches_subject_and_bio_without_exposing_inactive_teachers(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['teacher-management']);

        $activeUser = $this->createUserWithRole('teacher', email: 'public.ielts.teacher@aec.test');
        Teacher::query()->create([
            'user_id' => $activeUser->id,
            'employee_code' => 'PUBLIC-IELTS-01',
            'specialization' => 'IELTS Speaking',
            'bio' => 'Senior IELTS speaking coach',
            'credentials' => 'CELTA',
            'is_active' => true,
        ]);

        $inactiveUser = $this->createUserWithRole('teacher', email: 'private.teacher@aec.test');
        Teacher::query()->create([
            'user_id' => $inactiveUser->id,
            'employee_code' => 'PUBLIC-INACTIVE-01',
            'specialization' => 'Phonetics',
            'bio' => 'Inactive instructor profile',
            'is_active' => false,
        ]);

        $this->get('/instructors?q=IELTS')
            ->assertOk()
            ->assertSee('Senior IELTS speaking coach')
            ->assertDontSee('Inactive instructor profile');
    }

    public function test_public_product_catalog_searches_title_category_and_summary(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['storefront-catalog']);

        StorefrontItem::query()->create([
            'type' => 'product',
            'slug' => 'ielts-speaking-workbook',
            'title' => 'IELTS Speaking Workbook',
            'category' => 'Printed Study Kit',
            'price' => 650,
            'original_price' => 800,
            'stock_count' => 20,
            'summary' => 'Practice prompts and speaking exercises',
            'is_active' => true,
        ]);

        StorefrontItem::query()->create([
            'type' => 'product',
            'slug' => 'phonetics-flashcards',
            'title' => 'Phonetics Flashcards',
            'category' => 'Pronunciation',
            'price' => 300,
            'original_price' => 350,
            'stock_count' => 10,
            'summary' => 'Sound recognition practice',
            'is_active' => true,
        ]);

        $this->get('/products?q=IELTS')
            ->assertOk()
            ->assertSee('IELTS Speaking Workbook')
            ->assertDontSee('Phonetics Flashcards');
    }
}
