<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Plugins\Modules\AdmissionManagement\Models\AdmissionApplication;
use Plugins\Modules\CertificateManagement\Models\CertificateRecord;
use Tests\TestCase;

class AdmissionCertificateBranchAcceptanceTest extends TestCase
{
    public function test_admission_and_certificate_workflows_enforce_branch_isolation_and_scope_admin_lists(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'admission-management', 'certificate-management']);

        $branchA = DB::table('branches')->insertGetId([
            'code' => 'ADM-CERT-A',
            'name' => 'Admission Certificate Branch A',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $branchB = DB::table('branches')->insertGetId([
            'code' => 'ADM-CERT-B',
            'name' => 'Admission Certificate Branch B',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $admin = $this->createUserWithRole('admin');
        $manager = $this->createUserWithRole('branch_manager', $branchA);
        $permissionIds = DB::table('permissions')
            ->whereIn('slug', ['admissions.manage', 'certificates.manage'])
            ->pluck('id');
        $this->assertCount(2, $permissionIds);
        $manager->roles()->first()->permissions()->syncWithoutDetaching($permissionIds);

        // Public admission intake may select an active branch, but a manager
        // from another branch must not review that application.
        $this->post('/admission/apply', [
            'applicant_name' => 'Branch B Public Applicant',
            'email' => 'branch-b-public@aec.test',
            'phone' => '01700000001',
            'desired_course' => 'IELTS Masterclass',
            'branch_id' => $branchB,
        ])->assertSessionHasNoErrors();

        $foreignApplication = AdmissionApplication::query()
            ->where('email', 'branch-b-public@aec.test')
            ->firstOrFail();
        $this->actingAs($manager)->post("/admin/admissions/{$foreignApplication->id}/review", [
            'status' => 'approved',
            'reviewer_notes' => 'Cross-branch review must be denied.',
        ])->assertForbidden();
        $this->assertSame('pending', $foreignApplication->fresh()->status);

        // Even if the request submits another active branch, the manager's
        // own admission intake is forced into the manager's assigned branch.
        $this->actingAs($manager)->post('/admin/admissions', [
            'applicant_name' => 'Branch A Staff Applicant',
            'email' => 'branch-a-staff@aec.test',
            'phone' => '01700000002',
            'desired_course' => 'Spoken English',
            'branch_id' => $branchB,
        ])->assertSessionHasNoErrors();
        $ownApplication = AdmissionApplication::query()
            ->where('email', 'branch-a-staff@aec.test')
            ->firstOrFail();
        $this->assertSame($branchA, (int) $ownApplication->branch_id);

        $this->actingAs($manager)->get('/admin/admissions')
            ->assertOk()
            ->assertSee('Branch A Staff Applicant')
            ->assertDontSee('Branch B Public Applicant');

        // Certificate creation follows the same forced-branch rule and
        // certificate revocation is blocked across branch boundaries.
        $this->actingAs($admin)->post('/admin/certificates', [
            'student_name' => 'Branch B Certificate Learner',
            'student_code' => 'CERT-B-001',
            'course_title' => 'IELTS Academic',
            'grade_or_band' => 'Band 7.5',
            'issued_at' => now()->toDateString(),
            'branch_id' => $branchB,
        ])->assertSessionHasNoErrors();
        $foreignCertificate = CertificateRecord::query()
            ->where('student_code', 'CERT-B-001')
            ->firstOrFail();

        $this->actingAs($manager)->post("/admin/certificates/{$foreignCertificate->id}/revoke")
            ->assertForbidden();
        $this->assertSame('issued', $foreignCertificate->fresh()->status);

        $this->actingAs($manager)->post('/admin/certificates', [
            'student_name' => 'Branch A Certificate Learner',
            'student_code' => 'CERT-A-001',
            'course_title' => 'Spoken English',
            'grade_or_band' => 'Completed',
            'issued_at' => now()->toDateString(),
            'branch_id' => $branchB,
        ])->assertSessionHasNoErrors();
        $ownCertificate = CertificateRecord::query()
            ->where('student_code', 'CERT-A-001')
            ->firstOrFail();
        $this->assertSame($branchA, (int) $ownCertificate->branch_id);

        $this->actingAs($manager)->get('/admin/certificates')
            ->assertOk()
            ->assertSee('Branch A Certificate Learner')
            ->assertDontSee('Branch B Certificate Learner');
    }
}
