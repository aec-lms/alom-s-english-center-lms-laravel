<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Plugins\Modules\UniversalPayment\Models\UniversalPaymentTransaction;
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
            'batch-management',
            'admission-management',
            'attendance-management',
            'certificate-management',
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

    public function test_branch_manager_access_is_denied_when_assigned_branch_is_inactive(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'student-management']);

        $branchId = DB::table('branches')->insertGetId([
            'code' => 'BR-INACTIVE',
            'name' => 'Inactive Branch',
            'is_active' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $manager = $this->createUserWithRole('branch_manager', $branchId);
        $permissionId = DB::table('permissions')->where('slug', 'students.manage')->value('id');
        $manager->roles()->first()->permissions()->syncWithoutDetaching([$permissionId]);

        $this->actingAs($manager)->get('/admin/students')->assertForbidden();
    }

    public function test_branch_manager_can_only_view_and_update_their_assigned_branch(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management']);

        $branchA = DB::table('branches')->insertGetId([
            'code' => 'BR-A',
            'name' => 'Branch A',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $branchB = DB::table('branches')->insertGetId([
            'code' => 'BR-B',
            'name' => 'Branch B',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $manager = $this->createUserWithRole('branch_manager', $branchA);
        $permissionId = DB::table('permissions')->where('slug', 'branches.manage')->value('id');
        $manager->roles()->first()->permissions()->syncWithoutDetaching([$permissionId]);

        $branchRequest = \Illuminate\Http\Request::create('/admin/branches', 'GET');
        $branchRequest->setUserResolver(static fn () => $manager);
        $branchView = app(\Plugins\Modules\BranchManagement\Http\Controllers\BranchController::class)->index($branchRequest);
        $visibleBranches = $branchView->getData()['branches'];
        $this->assertCount(1, $visibleBranches);
        $this->assertSame($branchA, (int) $visibleBranches->first()->id);

        $this->actingAs($manager)->put('/admin/branches/' . $branchB, [
            'code' => 'BR-B-EDIT',
            'name' => 'Changed Other Branch',
        ])->assertForbidden();

        $this->actingAs($manager)->post('/admin/branches', [
            'code' => 'BR-C',
            'name' => 'New Branch',
        ])->assertForbidden();

        $this->actingAs($manager)->post('/admin/branches/' . $branchA . '/toggle')
            ->assertForbidden();
        $this->actingAs($manager)->delete('/admin/branches/' . $branchA)
            ->assertForbidden();
    }

    public function test_branch_cannot_be_deleted_while_course_records_reference_it(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'student-management', 'course-management']);

        $admin = $this->createUserWithRole('admin');
        $branchId = DB::table('branches')->insertGetId([
            'code' => 'BR-KEEP',
            'name' => 'Branch With Courses',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $courseId = DB::table('courses')->insertGetId([
            'branch_id' => $branchId,
            'code' => 'BR-KEEP-01',
            'title' => 'Branch-owned Course',
            'slug' => 'branch-owned-course',
            'price' => 0,
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)->delete('/admin/branches/' . $branchId)
            ->assertSessionHasErrors('branch');

        $this->assertDatabaseHas('branches', ['id' => $branchId]);
        $this->assertDatabaseHas('courses', ['id' => $courseId, 'branch_id' => $branchId]);
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

    public function test_universal_payment_branch_manager_can_only_view_and_verify_own_branch_receipts(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'payment-management', 'fees-management', 'universal-payment']);

        $ownBranch = DB::table('branches')->insertGetId([
            'code' => 'UP-OWN',
            'name' => 'Universal Payment Own Branch',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherBranch = DB::table('branches')->insertGetId([
            'code' => 'UP-OTHER',
            'name' => 'Universal Payment Other Branch',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $manager = $this->createUserWithRole('branch_manager', $ownBranch);
        $permission = DB::table('permissions')->where('slug', 'payments.universal.manage')->firstOrFail();
        $manager->roles()->first()->permissions()->syncWithoutDetaching([$permission->id]);

        $ownReceipt = UniversalPaymentTransaction::query()->create([
            'reference' => 'UP-OWN-001',
            'payer_name' => 'Own Branch Payer',
            'item_title' => 'Own Branch Fee',
            'gateway' => 'bank_cash',
            'currency' => 'BDT',
            'amount' => 1000,
            'external_trx_id' => 'OWN-TRX-001',
            'status' => 'pending',
            'branch_id' => $ownBranch,
        ]);
        $otherReceipt = UniversalPaymentTransaction::query()->create([
            'reference' => 'UP-OTHER-001',
            'payer_name' => 'Other Branch Payer',
            'item_title' => 'Other Branch Fee',
            'gateway' => 'bank_cash',
            'currency' => 'BDT',
            'amount' => 2000,
            'external_trx_id' => 'OTHER-TRX-001',
            'status' => 'pending',
            'branch_id' => $otherBranch,
        ]);

        $this->actingAs($manager)
            ->get('/plugins/universal-payment')
            ->assertOk()
            ->assertSee('UP-OWN-001')
            ->assertDontSee('UP-OTHER-001');

        $this->actingAs($manager)
            ->post('/plugins/universal-payment/transactions/' . $ownReceipt->id . '/status', ['status' => 'verified'])
            ->assertRedirect();

        $this->assertDatabaseHas('universal_payment_transactions', [
            'id' => $ownReceipt->id,
            'status' => 'verified',
            'verified_by' => $manager->id,
        ]);

        $this->actingAs($manager)
            ->post('/plugins/universal-payment/transactions/' . $otherReceipt->id . '/status', ['status' => 'verified'])
            ->assertNotFound();

        $this->assertDatabaseHas('universal_payment_transactions', [
            'id' => $otherReceipt->id,
            'status' => 'pending',
            'verified_by' => null,
        ]);
    }

    public function test_unassigned_branch_manager_cannot_access_universal_payment_receipts(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'payment-management', 'fees-management', 'universal-payment']);

        $manager = $this->createUserWithRole('branch_manager');
        $permission = DB::table('permissions')->where('slug', 'payments.universal.manage')->firstOrFail();
        $manager->roles()->first()->permissions()->syncWithoutDetaching([$permission->id]);

        $this->actingAs($manager)->get('/plugins/universal-payment')->assertForbidden();
        $this->actingAs($manager)
            ->post('/plugins/universal-payment/transactions', [
                'payer_name' => 'No Branch Payer',
                'item_title' => 'Manual Receipt',
                'gateway' => 'bank_cash',
                'amount' => 500,
                'external_trx_id' => 'NO-BRANCH-TRX',
            ])
            ->assertForbidden();
    }

}
