<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Plugins\Modules\NotificationCenter\Models\LmsNotification;
use Plugins\Modules\UniversalPayment\Models\UniversalPaymentTransaction;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Plugins\Modules\CourseManagement\Http\Controllers\CourseController;
use Plugins\Modules\CourseManagement\Http\Controllers\EnrollmentController;
use Plugins\Modules\ExamManagement\Http\Controllers\ExamController;
use Plugins\Modules\FeesManagement\Http\Controllers\FeeController;
use Tests\TestCase;

class BranchManagerFailClosedTest extends TestCase
{
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
    public function test_unassigned_branch_manager_is_denied_admission_and_certificate_workflows(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'student-management', 'admission-management', 'certificate-management']);

        $manager = $this->createUserWithRole('branch_manager');
        $permissions = DB::table('permissions')
            ->whereIn('slug', ['admissions.manage', 'certificates.manage'])
            ->pluck('id');
        $this->assertCount(2, $permissions);
        $manager->roles()->first()->permissions()->syncWithoutDetaching($permissions);

        $this->actingAs($manager)->get('/admin/admissions')->assertForbidden();
        $this->actingAs($manager)->post('/admin/admissions', [])->assertForbidden();
        $this->actingAs($manager)->get('/admin/certificates')->assertForbidden();
        $this->actingAs($manager)->post('/admin/certificates', [])->assertForbidden();
    }

    public function test_branch_manager_access_is_denied_when_assigned_branch_is_inactive(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'student-management', 'admission-management', 'certificate-management']);

        $branchId = DB::table('branches')->insertGetId([
            'code' => 'BR-INACTIVE-AC',
            'name' => 'Inactive Branch for Admissions',
            'is_active' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $manager = $this->createUserWithRole('branch_manager', $branchId);
        $permissions = DB::table('permissions')
            ->whereIn('slug', ['admissions.manage', 'certificates.manage'])
            ->pluck('id');
        $manager->roles()->first()->permissions()->syncWithoutDetaching($permissions);

        $this->actingAs($manager)->get('/admin/admissions')->assertForbidden();
        $this->actingAs($manager)->get('/admin/certificates')->assertForbidden();
    }

    public function test_branch_owned_course_exam_enrollment_and_fee_directories_fail_closed(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['course-management', 'exam-management', 'fees-management']);
        $manager = $this->createUserWithRole('branch_manager');

        $request = Request::create('/admin/branch-scope-check', 'GET');
        $request->setUserResolver(static fn () => $manager);
        $controllers = [
            app(CourseController::class),
            app(EnrollmentController::class),
            app(ExamController::class),
            app(FeeController::class),
        ];

        foreach ($controllers as $controller) {
            try {
                $controller->index($request);
                $this->fail('Unassigned branch manager must be denied by ' . $controller::class);
            } catch (HttpExceptionInterface $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }
    }

    public function test_notification_center_branch_manager_reads_and_dispatches_only_within_own_branch(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'email-notifications', 'notification-center']);

        $ownBranch = DB::table('branches')->insertGetId([
            'code' => 'NC-OWN',
            'name' => 'Notification Own Branch',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherBranch = DB::table('branches')->insertGetId([
            'code' => 'NC-OTHER',
            'name' => 'Notification Other Branch',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $manager = $this->createUserWithRole('branch_manager', $ownBranch);
        $ownStudent = $this->createUserWithRole('student', $ownBranch);
        $otherStudent = $this->createUserWithRole('student', $otherBranch);
        $permission = DB::table('permissions')->where('slug', 'notifications.manage')->first();
        $this->assertNotNull($permission);
        $manager->roles()->first()->permissions()->syncWithoutDetaching([$permission->id]);

        LmsNotification::query()->create([
            'user_id' => $ownStudent->id,
            'branch_id' => $ownBranch,
            'channel' => 'in_app',
            'title' => 'Own Branch Alert',
            'message' => 'Own branch only',
            'status' => 'sent',
        ]);
        LmsNotification::query()->create([
            'user_id' => $otherStudent->id,
            'branch_id' => $otherBranch,
            'channel' => 'in_app',
            'title' => 'Other Branch Alert',
            'message' => 'Must remain private',
            'status' => 'sent',
        ]);

        $this->actingAs($manager)
            ->get('/admin/notifications')
            ->assertOk()
            ->assertSee('Own Branch Alert')
            ->assertDontSee('Other Branch Alert');

        $this->actingAs($manager)->post('/admin/notifications/dispatch', [
            'user_id' => $ownStudent->id,
            'channel' => 'in_app',
            'title' => 'Branch-scoped dispatch',
            'message' => 'Allowed',
        ])->assertRedirect();

        $this->assertDatabaseHas('lms_notifications', [
            'user_id' => $ownStudent->id,
            'branch_id' => $ownBranch,
            'title' => 'Branch-scoped dispatch',
        ]);

        $this->actingAs($manager)->post('/admin/notifications/dispatch', [
            'user_id' => $otherStudent->id,
            'channel' => 'in_app',
            'title' => 'Cross-branch attempt',
            'message' => 'Must be blocked',
        ])->assertNotFound();

        $this->assertDatabaseMissing('lms_notifications', [
            'user_id' => $otherStudent->id,
            'title' => 'Cross-branch attempt',
        ]);
    }

    public function test_unassigned_branch_manager_cannot_access_notification_center(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'email-notifications', 'notification-center']);

        $manager = $this->createUserWithRole('branch_manager');
        $permission = DB::table('permissions')->where('slug', 'notifications.manage')->firstOrFail();
        $manager->roles()->first()->permissions()->syncWithoutDetaching([$permission->id]);

        $this->actingAs($manager)->get('/admin/notifications')->assertForbidden();
        $this->actingAs($manager)->post('/admin/notifications/dispatch', [
            'user_id' => $this->createUserWithRole('student')->id,
            'channel' => 'in_app',
            'title' => 'Denied',
            'message' => 'No branch assignment',
        ])->assertForbidden();
    }


    public function test_universal_payment_branch_manager_sees_and_reviews_only_own_branch_receipts(): void
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
            'gateway' => 'bkash',
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
            'gateway' => 'nagad',
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

        $receipt = UniversalPaymentTransaction::query()->create([
            'reference' => 'UP-NO-BRANCH-001',
            'payer_name' => 'Unassigned Branch Payer',
            'item_title' => 'Manual Receipt',
            'gateway' => 'bank_cash',
            'currency' => 'BDT',
            'amount' => 500,
            'external_trx_id' => 'BANK-TRX-001',
            'status' => 'pending',
            'branch_id' => null,
        ]);

        $this->actingAs($manager)->get('/plugins/universal-payment')->assertForbidden();
        $this->actingAs($manager)
            ->post('/plugins/universal-payment/transactions/' . $receipt->id . '/status', ['status' => 'verified'])
            ->assertForbidden();

        $this->assertDatabaseHas('universal_payment_transactions', [
            'id' => $receipt->id,
            'status' => 'pending',
            'verified_by' => null,
        ]);
    }

}
