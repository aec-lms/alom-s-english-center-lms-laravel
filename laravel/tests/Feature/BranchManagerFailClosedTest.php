<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BranchManagerFailClosedTest extends TestCase
{
    public function test_unassigned_branch_manager_is_denied_across_academic_finance_and_library_modules(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins([
            'branch-management',
            'student-management',
            'course-management',
            'admission-management',
            'attendance-management',
            'certificate-management',
            'batch-management',
            'class-management',
            'exam-management',
            'fees-management',
            'library-management',
            'notice-board',
            'payment-management',
            'reports-analytics',
        ]);

        $manager = $this->createUserWithRole('branch_manager');
        $permissionIds = DB::table('permissions')
            ->whereIn('module', [
                'admission-management',
                'attendance-management',
                'batch-management',
                'certificate-management',
                'class-management',
                'course-management',
                'exam-management',
                'fees-management',
                'library-management',
                'notice-board',
                'payment-management',
                'reports-analytics',
            ])
            ->pluck('id');
        $manager->roles()->first()->permissions()->syncWithoutDetaching($permissionIds);

        foreach ([
            '/admin/admissions',
            '/admin/attendance',
            '/admin/batches',
            '/admin/certificates',
            '/admin/classes',
            '/admin/courses',
            '/admin/exams',
            '/admin/fees',
            '/admin/library',
            '/admin/notices',
            '/admin/payments',
            '/admin/reports',
        ] as $path) {
            $this->actingAs($manager)->get($path)->assertForbidden();
        }

        foreach ([
            '/admin/admissions',
            '/admin/attendance',
            '/admin/batches',
            '/admin/certificates',
            '/admin/classes',
            '/admin/courses',
            '/admin/exams',
            '/admin/fees/invoices',
            '/admin/library/books',
            '/admin/notices',
            '/admin/payments/offline',
        ] as $path) {
            $this->actingAs($manager)->post($path, [])->assertForbidden();
        }
    }

    public function test_unassigned_branch_manager_is_denied_teacher_guardian_and_hr_reads_and_writes(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins([
            'branch-management',
            'teacher-management',
            'guardian-management',
            'hr-staff-management',
        ]);

        $manager = $this->createUserWithRole('branch_manager');
        $permissionIds = DB::table('permissions')
            ->whereIn('slug', ['teachers.manage', 'guardians.manage', 'hr.manage'])
            ->pluck('id');
        $this->assertCount(3, $permissionIds);
        $manager->roles()->first()->permissions()->syncWithoutDetaching($permissionIds);

        $this->actingAs($manager)
            ->get('/admin/teachers')
            ->assertForbidden();
        $this->actingAs($manager)
            ->post('/admin/teachers', [])
            ->assertForbidden();

        $this->actingAs($manager)
            ->get('/admin/guardians')
            ->assertForbidden();
        $this->actingAs($manager)
            ->post('/admin/guardians', [])
            ->assertForbidden();

        $this->actingAs($manager)
            ->get('/admin/hr')
            ->assertForbidden();
        $this->actingAs($manager)
            ->post('/admin/hr/staff', [])
            ->assertForbidden();
        $this->actingAs($manager)
            ->post('/admin/hr/payrolls', [])
            ->assertForbidden();
    }
}
