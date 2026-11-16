<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
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
        $this->activatePlugins(['student-management', 'admission-management', 'certificate-management']);

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

}
