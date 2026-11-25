<?php

declare(strict_types=1);

namespace Tests\Feature;

use Plugins\Modules\CertificateManagement\Models\CertificateRecord;
use Plugins\Modules\CmsWebsite\Models\CmsPost;
use Plugins\Modules\CourseManagement\Models\Course;
use Plugins\Modules\ExamManagement\Models\Exam;
use Plugins\Modules\StorefrontCatalog\Models\StorefrontItem;
use Plugins\Modules\TeacherManagement\Models\Teacher;
use Tests\TestCase;

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

    public function test_verified_certificate_renders_a_printable_achievement_card_but_revoked_certificate_does_not(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['certificate-management']);

        CertificateRecord::query()->create([
            'verification_code' => 'AEC-CERT-CARD-001',
            'student_name' => 'Achievement Card Student',
            'student_code' => 'AEC-STU-001',
            'course_title' => 'Advanced English Communication',
            'grade_or_band' => 'Band 8.0',
            'issued_at' => now()->toDateString(),
            'status' => 'issued',
        ]);

        $this->get('/result?code=AEC-CERT-CARD-001')
            ->assertOk()
            ->assertSee('Achievement Card')
            ->assertSee('Achievement Card Student')
            ->assertSee('Print / Save PDF')
            ->assertSee('window.print()');

        CertificateRecord::query()->create([
            'verification_code' => 'AEC-CERT-REVOKED-001',
            'student_name' => 'Revoked Achievement Student',
            'course_title' => 'Advanced English Communication',
            'grade_or_band' => 'Completed',
            'issued_at' => now()->toDateString(),
            'status' => 'revoked',
        ]);

        $this->get('/result?code=AEC-CERT-REVOKED-001')
            ->assertOk()
            ->assertSee('has been revoked')
            ->assertDontSee('id="achievement-card"');
    }

    public function test_public_ebook_catalog_search_keeps_free_and_premium_filter_context(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['storefront-catalog']);

        StorefrontItem::query()->create([
            'type' => 'ebook',
            'slug' => 'ielts-writing-guide',
            'title' => 'IELTS Writing Guide',
            'category' => 'IELTS',
            'price' => 0,
            'original_price' => 400,
            'is_free' => true,
            'pages' => 80,
            'summary' => 'Task 1 and Task 2 writing practice',
            'is_active' => true,
        ]);

        StorefrontItem::query()->create([
            'type' => 'ebook',
            'slug' => 'phonetics-reference',
            'title' => 'Phonetics Reference Book',
            'category' => 'Pronunciation',
            'price' => 250,
            'original_price' => 300,
            'is_free' => false,
            'pages' => 60,
            'summary' => 'Sound and pronunciation reference',
            'is_active' => true,
        ]);

        $this->get('/ebooks?q=IELTS&type=free')
            ->assertOk()
            ->assertSee('IELTS Writing Guide')
            ->assertDontSee('Phonetics Reference Book')
            ->assertSee('name="q"', false);
    }

    public function test_public_learning_hub_searches_published_content_and_hides_unpublished_posts(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['cms-website']);

        CmsPost::query()->create([
            'slug' => 'ielts-speaking-fluency',
            'title' => 'IELTS Speaking Fluency Guide',
            'category' => 'IELTS',
            'author' => 'Academic Team',
            'excerpt' => 'Fluency practice and speaking drills',
            'content' => 'Prepare for IELTS speaking with structured practice.',
            'is_published' => true,
        ]);

        CmsPost::query()->create([
            'slug' => 'private-grammar-draft',
            'title' => 'Private Grammar Draft',
            'category' => 'Grammar',
            'author' => 'Editorial Team',
            'content' => 'Unpublished content must stay hidden.',
            'is_published' => false,
        ]);

        $this->get('/learn?q=IELTS')
            ->assertOk()
            ->assertSee('IELTS Speaking Fluency Guide')
            ->assertDontSee('Private Grammar Draft');
    }
}
