<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Plugins\Modules\AdmissionManagement\Models\AdmissionApplication;
use Plugins\Modules\CertificateManagement\Models\CertificateRecord;
use Plugins\Modules\LibraryManagement\Models\LibraryBook;
use Plugins\Modules\LibraryManagement\Models\LibraryLoan;
use Plugins\Modules\StudentManagement\Models\Student;
use Tests\TestCase;

class AdditionalModulesWorkflowTest extends TestCase
{
    public function test_all_newly_modularized_academic_and_growth_plugins_work_end_to_end(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins([
            'branch-management',
            'student-management',
            'course-management',
            'class-management',
            'batch-management',
            'admission-management',
            'certificate-management',
            'library-management',
            'hr-staff-management',
            'email-notifications',
            'marketing-management',
        ]);

        $admin = $this->createUserWithRole('admin');
        $branchId = DB::table('branches')->insertGetId([
            'code' => 'SYL-01',
            'name' => 'Sylhet Campus',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 1. Class & Batch independent plugins
        $this->actingAs($admin)->post('/admin/classes', [
            'code' => 'CLS-IELTS-1',
            'name' => 'IELTS Writing Workshop',
            'section' => 'Section A',
            'subject' => 'IELTS Academic',
            'day_of_week' => 'Sunday',
            'start_time' => '16:00',
            'end_time' => '17:30',
            'branch_id' => $branchId,
        ])->assertSessionHasNoErrors();

        $this->actingAs($admin)->post('/admin/batches', [
            'batch_code' => 'BATCH-JAN-01',
            'name' => 'IELTS Evening Batch',
            'course_title' => 'IELTS Masterclass',
            'schedule' => 'Sun-Tue-Thu 6:00 PM',
            'max_capacity' => 30,
            'status' => 'ongoing',
            'start_date' => now()->toDateString(),
            'branch_id' => $branchId,
        ])->assertSessionHasNoErrors();

        // 2. Public Admission Application + Admin Approval
        $this->post('/admission/apply', [
            'applicant_name' => 'Farhan Sadik',
            'email' => 'farhan@example.com',
            'phone' => '+8801712345678',
            'desired_course' => 'IELTS Complete Masterclass',
            'branch_id' => $branchId,
        ])->assertSessionHasNoErrors();

        $app = AdmissionApplication::query()->where('email', 'farhan@example.com')->firstOrFail();
        $this->actingAs($admin)->post("/admin/admissions/{$app->id}/review", [
            'status' => 'approved',
        ])->assertSessionHasNoErrors();
        $this->assertSame('approved', $app->fresh()->status);

        // 3. Certificate Issuance -> Public Verification -> Revocation Check
        $this->actingAs($admin)->post('/admin/certificates', [
            'student_name' => 'Nadia Karim',
            'student_code' => 'AEC-2026-09',
            'course_title' => 'IELTS Academic Masterclass',
            'grade_or_band' => 'Band 8.0',
            'issued_at' => now()->toDateString(),
            'branch_id' => $branchId,
        ])->assertSessionHasNoErrors();

        $cert = CertificateRecord::query()->where('student_code', 'AEC-2026-09')->firstOrFail();
        $this->get('/result?code=' . urlencode($cert->verification_code))
            ->assertOk()
            ->assertSee('Nadia Karim')
            ->assertSee('Band 8.0');

        $this->actingAs($admin)->post("/admin/certificates/{$cert->id}/revoke")
            ->assertSessionHasNoErrors();
        $this->get('/result?code=' . urlencode($cert->verification_code))
            ->assertOk()
            ->assertSee('revoked');

        // 4. Library Add Book -> Borrow -> Return Stock Lifecycle
        $this->actingAs($admin)->post('/admin/library/books', [
            'isbn' => 'ISBN-AEC-001',
            'title' => 'AEC Band 8 Essay Handbook',
            'author' => 'Engr. Mahbubul Alom',
            'category' => 'IELTS Writing',
            'total_copies' => 3,
            'branch_id' => $branchId,
        ])->assertSessionHasNoErrors();

        $book = LibraryBook::query()->where('isbn', 'ISBN-AEC-001')->firstOrFail();
        $this->actingAs($admin)->post('/admin/library/loans', [
            'library_book_id' => $book->id,
            'borrower_name' => 'Farhan Sadik',
            'borrower_code' => 'STU-881',
            'due_at' => now()->addDays(7)->toDateString(),
        ])->assertSessionHasNoErrors();
        $this->assertSame(2, $book->fresh()->available_copies);

        $loan = LibraryLoan::query()->where('library_book_id', $book->id)->firstOrFail();
        $this->actingAs($admin)->post("/admin/library/loans/{$loan->id}/return")
            ->assertSessionHasNoErrors();
        $this->assertSame(3, $book->fresh()->available_copies);

        // 5. HR Staff + Payroll
        $this->actingAs($admin)->post('/admin/hr/staff', [
            'employee_code' => 'EMP-101',
            'full_name' => 'Sadia Rahman',
            'designation' => 'Senior IELTS Instructor',
            'department' => 'Academic',
            'base_salary' => 45000,
            'branch_id' => $branchId,
        ])->assertSessionHasNoErrors();
        $staffId = DB::table('staff_profiles')->where('employee_code', 'EMP-101')->value('id');
        $this->actingAs($admin)->post('/admin/hr/payrolls', [
            'staff_profile_id' => $staffId,
            'payroll_month' => '2026-02',
            'bonus' => 5000,
            'deductions' => 1000,
        ])->assertSessionHasNoErrors();
        $this->assertSame(49000.0, (float) DB::table('staff_payrolls')->where('staff_profile_id', $staffId)->value('net_paid'));

        // 6. Email Notifications & Marketing Coupons
        $this->actingAs($admin)->post('/admin/email-notifications/templates', [
            'template_key' => 'welcome_student',
            'subject' => 'Welcome to AEC LMS',
            'body_html' => '<p>Hello Student</p>',
        ])->assertSessionHasNoErrors();

        $this->actingAs($admin)->post('/admin/email-notifications/send', [
            'recipient_email' => 'student@alomsenglish.com',
            'subject' => 'Orientation Reminder',
            'message' => 'Your class starts Sunday at 10 AM.',
        ])->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('email_logs')->count());

        $this->actingAs($admin)->post('/admin/marketing/campaigns', [
            'name' => 'Spring IELTS Drive',
            'channel' => 'Facebook & Search',
            'budget_bdt' => 15000,
        ])->assertSessionHasNoErrors();

        $this->actingAs($admin)->post('/admin/marketing/coupons', [
            'code' => 'ALOM20',
            'discount_percent' => 20,
            'max_uses' => 50,
        ])->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('promo_coupons')->where('code', 'ALOM20')->count());
    }
    public function test_branch_manager_cannot_delete_other_branch_classes_or_batches_or_link_foreign_students(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins([
            'branch-management',
            'student-management',
            'guardian-management',
            'class-management',
            'batch-management',
            'course-management',
            'exam-management',
            'certificate-management',
        ]);

        $branchA = DB::table('branches')->insertGetId([
            'code' => 'BR-A', 'name' => 'Branch A', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $branchB = DB::table('branches')->insertGetId([
            'code' => 'BR-B', 'name' => 'Branch B', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $admin = $this->createUserWithRole('admin');
        $manager = $this->createUserWithRole('branch_manager', $branchA);

        $this->actingAs($admin)->post('/admin/batches', [
            'batch_code' => 'B-BR-B',
            'name' => 'Branch B Batch',
            'schedule' => 'Sun-Tue 5 PM',
            'max_capacity' => 20,
            'status' => 'ongoing',
            'branch_id' => $branchB,
        ])->assertSessionHasNoErrors();
        $batchId = DB::table('study_batches')->where('batch_code', 'B-BR-B')->value('id');

        $this->actingAs($admin)->post('/admin/classes', [
            'code' => 'C-BR-B',
            'name' => 'Branch B Class',
            'section' => 'A',
            'subject' => 'English',
            'day_of_week' => 'Sunday',
            'start_time' => '10:00',
            'end_time' => '11:00',
            'branch_id' => $branchB,
        ])->assertSessionHasNoErrors();
        $classId = DB::table('academic_classes')->where('code', 'C-BR-B')->value('id');

        $this->actingAs($manager)->delete('/admin/batches/' . $batchId)->assertForbidden();
        $this->actingAs($manager)->delete('/admin/classes/' . $classId)->assertForbidden();
        $this->assertDatabaseHas('study_batches', ['id' => $batchId]);
        $this->assertDatabaseHas('academic_classes', ['id' => $classId]);

        $studentUser = $this->createUserWithRole('student', $branchB);
        $student = Student::query()->create([
            'user_id' => $studentUser->id,
            'branch_id' => $branchB,
            'student_code' => 'STU-BR-B',
            'admission_date' => now()->toDateString(),
            'status' => 'active',
        ]);

        $this->actingAs($admin)->post('/admin/courses', [
            'code' => 'COURSE-BR-B',
            'title' => 'Branch B English Course',
            'price' => 2500,
            'branch_id' => $branchB,
            'status' => 'published',
        ])->assertSessionHasNoErrors();
        $courseId = DB::table('courses')->where('code', 'COURSE-BR-B')->value('id');

        $this->actingAs($admin)->post('/admin/exams', [
            'course_id' => $courseId,
            'title' => 'Branch B Assessment',
            'duration_minutes' => 60,
            'pass_marks' => 40,
        ])->assertSessionHasNoErrors();
        $examId = DB::table('exams')->where('title', 'Branch B Assessment')->value('id');

        $this->actingAs($admin)->post('/admin/certificates', [
            'student_name' => 'Branch B Learner',
            'course_title' => 'Branch B English Course',
            'grade_or_band' => 'A',
            'issued_at' => now()->toDateString(),
            'branch_id' => $branchB,
        ])->assertSessionHasNoErrors();
        $certificateId = DB::table('certificates')->where('student_name', 'Branch B Learner')->value('id');

        $permissionIds = DB::table('permissions')
            ->whereIn('slug', ['courses.manage', 'exams.manage', 'certificates.manage'])
            ->pluck('id');
        $manager->roles()->first()->permissions()->syncWithoutDetaching($permissionIds);

        $this->actingAs($manager)->delete('/admin/courses/' . $courseId)->assertForbidden();
        $this->actingAs($manager)->delete('/admin/exams/' . $examId)->assertForbidden();
        $this->actingAs($manager)->post('/admin/certificates/' . $certificateId . '/revoke')->assertForbidden();

        $this->assertDatabaseHas('courses', ['id' => $courseId]);
        $this->assertDatabaseHas('exams', ['id' => $examId]);
        $this->assertDatabaseHas('certificates', ['id' => $certificateId, 'status' => 'issued']);

        $guardianPermission = DB::table('permissions')->where('slug', 'guardians.manage')->first();
        if ($guardianPermission) {
            $manager->roles()->first()->permissions()->syncWithoutDetaching([$guardianPermission->id]);
        }

        $this->actingAs($manager)->post('/admin/guardians', [
            'name' => 'Guardian Branch A',
            'email' => 'guardian.branch.a@example.test',
            'phone' => '01700000000',
            'password' => 'StrongGuardianPass!2026',
            'branch_id' => $branchA,
            'relationship' => 'parent',
            'student_id' => $student->id,
        ])->assertStatus(422);
    }


    public function test_unassigned_branch_manager_is_denied_student_directory_access(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'student-management']);

        $manager = $this->createUserWithRole('branch_manager');
        $permission = DB::table('permissions')->where('slug', 'students.manage')->first();
        $this->assertNotNull($permission);
        $manager->roles()->first()->permissions()->syncWithoutDetaching([$permission->id]);

        $this->actingAs($manager)->get('/admin/students')->assertForbidden();
    }

    public function test_class_schedule_rejects_invalid_weekdays_and_non_positive_time_ranges(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'class-management']);

        $admin = $this->createUserWithRole('admin');
        $branchId = DB::table('branches')->insertGetId([
            'code' => 'TIME-01',
            'name' => 'Schedule Test Branch',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = [
            'code' => 'TIME-CLASS-01',
            'name' => 'Schedule Validation Class',
            'section' => 'A',
            'subject' => 'English',
            'day_of_week' => 'Sunday',
            'start_time' => '10:00',
            'end_time' => '11:00',
            'branch_id' => $branchId,
        ];

        $this->actingAs($admin)->post('/admin/classes', $payload)->assertSessionHasNoErrors();
        $classId = DB::table('academic_classes')->where('code', 'TIME-CLASS-01')->value('id');
        $this->assertNotNull($classId);

        $this->actingAs($admin)->put('/admin/classes/' . $classId, array_merge($payload, [
            'day_of_week' => 'Funday',
        ]))->assertSessionHasErrors(['day_of_week']);

        $this->actingAs($admin)->put('/admin/classes/' . $classId, array_merge($payload, [
            'start_time' => '14:00',
            'end_time' => '13:00',
        ]))->assertSessionHasErrors(['end_time']);

        $this->actingAs($admin)->put('/admin/classes/' . $classId, array_merge($payload, [
            'start_time' => 'not-a-time',
        ]))->assertSessionHasErrors(['start_time']);

        $this->assertDatabaseHas('academic_classes', [
            'id' => $classId,
            'day_of_week' => 'Sunday',
            'start_time' => '10:00',
            'end_time' => '11:00',
        ]);
    }


    public function test_library_branch_manager_is_fail_closed_and_cannot_access_other_branch_books(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'library-management']);

        $branchA = DB::table('branches')->insertGetId([
            'code' => 'LIB-A', 'name' => 'Library Branch A', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $branchB = DB::table('branches')->insertGetId([
            'code' => 'LIB-B', 'name' => 'Library Branch B', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $admin = $this->createUserWithRole('admin');
        $manager = $this->createUserWithRole('branch_manager', $branchA);
        $permission = DB::table('permissions')->where('slug', 'library.manage')->first();
        $this->assertNotNull($permission);
        $manager->roles()->first()->permissions()->syncWithoutDetaching([$permission->id]);

        $this->actingAs($admin)->post('/admin/library/books', [
            'isbn' => 'LIB-FOREIGN-001',
            'title' => 'Foreign Branch Book',
            'author' => 'Test Author',
            'category' => 'English',
            'total_copies' => 2,
            'branch_id' => $branchB,
        ])->assertSessionHasNoErrors();
        $foreignBookId = DB::table('library_books')->where('isbn', 'LIB-FOREIGN-001')->value('id');
        $this->assertNotNull($foreignBookId);
        $this->assertSame($branchA, (int) $manager->fresh()->branch_id);
        $this->assertTrue($manager->fresh()->hasRole('branch_manager'));
        $this->assertDatabaseHas('library_books', ['id' => $foreignBookId, 'branch_id' => $branchB]);
        $this->assertSame(0, DB::table('library_books')->where('branch_id', $branchA)->count());

        $this->actingAs($manager)->get('/admin/library')
            ->assertOk()
            ->assertViewHas('books', fn ($books) => $books->where('title', 'Foreign Branch Book')->isEmpty());
        $this->actingAs($manager)->post('/admin/library/books', [
            'isbn' => 'LIB-OWN-001',
            'title' => 'Own Branch Book',
            'author' => 'Test Author',
            'category' => 'English',
            'total_copies' => 1,
            'branch_id' => $branchB,
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('library_books', [
            'isbn' => 'LIB-OWN-001',
            'branch_id' => $branchA,
        ]);

        $this->actingAs($manager)->post('/admin/library/loans', [
            'library_book_id' => $foreignBookId,
            'borrower_name' => 'Cross Branch Attempt',
            'borrower_code' => 'STU-X',
            'due_at' => now()->addDays(5)->toDateString(),
        ])->assertForbidden();

        $unassigned = $this->createUserWithRole('branch_manager');
        $unassigned->roles()->first()->permissions()->syncWithoutDetaching([$permission->id]);
        $this->actingAs($unassigned)->get('/admin/library')->assertForbidden();
        $this->actingAs($unassigned)->post('/admin/library/books', [
            'isbn' => 'LIB-NO-BRANCH',
            'title' => 'Should Not Exist',
            'author' => 'Test Author',
            'category' => 'English',
            'total_copies' => 1,
        ])->assertForbidden();
        $this->assertDatabaseMissing('library_books', ['isbn' => 'LIB-NO-BRANCH']);
    }

}
