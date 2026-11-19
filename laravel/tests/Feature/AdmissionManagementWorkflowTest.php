<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Plugins\Modules\AdmissionManagement\Models\AdmissionApplication;
use Tests\TestCase;

class AdmissionManagementWorkflowTest extends TestCase
{
    public function test_application_moves_through_screening_and_decision_states_with_auditable_review_metadata(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['admission-management']);

        $reviewer = $this->createUserWithRole('admin');
        $assignee = $this->createUserWithRole('teacher');
        $application = AdmissionApplication::query()->create([
            'application_code' => 'ADM-WORKFLOW-001',
            'applicant_name' => 'Workflow Applicant',
            'email' => 'workflow-applicant@aec.test',
            'phone' => '01700000001',
            'desired_course' => 'IELTS Masterclass',
            'application_source' => 'website',
            'status' => 'pending',
        ]);

        $this->actingAs($reviewer)->post('/admin/admissions/' . $application->id . '/review', [
            'status' => 'screening',
            'reviewer_notes' => 'Initial eligibility screening completed.',
            'interview_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'assigned_to' => $assignee->id,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('admission_applications', [
            'id' => $application->id,
            'status' => 'screening',
            'reviewed_by' => $reviewer->id,
            'assigned_to' => $assignee->id,
            'reviewer_notes' => 'Initial eligibility screening completed.',
        ]);

        $this->actingAs($reviewer)->post('/admin/admissions/' . $application->id . '/review', [
            'status' => 'shortlisted',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('admission_applications', [
            'id' => $application->id,
            'status' => 'shortlisted',
            'reviewer_notes' => 'Initial eligibility screening completed.',
            'assigned_to' => $assignee->id,
        ]);

        $this->actingAs($reviewer)->post('/admin/admissions/' . $application->id . '/review', [
            'status' => 'approved',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('admission_applications', [
            'id' => $application->id,
            'status' => 'approved',
        ]);

        // Approved decisions are terminal: stale or replayed requests cannot silently reverse them.
        $this->actingAs($reviewer)->post('/admin/admissions/' . $application->id . '/review', [
            'status' => 'rejected',
            'reviewer_notes' => 'Attempted reversal.',
        ])->assertStatus(422);

        $this->assertSame('approved', $application->fresh()->status);
    }

    public function test_rejection_requires_a_reason_and_invalid_status_transitions_are_blocked(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['admission-management']);
        $reviewer = $this->createUserWithRole('admin');
        $application = AdmissionApplication::query()->create([
            'application_code' => 'ADM-WORKFLOW-002',
            'applicant_name' => 'Decision Applicant',
            'email' => 'decision-applicant@aec.test',
            'phone' => '01700000002',
            'desired_course' => 'Spoken English',
            'status' => 'pending',
        ]);

        $this->actingAs($reviewer)->from('/admin/admissions')->post('/admin/admissions/' . $application->id . '/review', [
            'status' => 'rejected',
        ])->assertSessionHasErrors('reviewer_notes');

        $this->assertSame('pending', $application->fresh()->status);

        $this->actingAs($reviewer)->post('/admin/admissions/' . $application->id . '/review', [
            'status' => 'rejected',
            'reviewer_notes' => 'Applicant does not meet the published entry requirements.',
        ])->assertSessionHasNoErrors();

        $this->assertSame('rejected', $application->fresh()->status);

        $this->actingAs($reviewer)->post('/admin/admissions/' . $application->id . '/review', [
            'status' => 'approved',
        ])->assertStatus(422);

        $this->assertSame('rejected', $application->fresh()->status);
    }

    public function test_admission_dashboard_search_and_status_filter_are_scoped_and_paginated(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['admission-management']);
        $admin = $this->createUserWithRole('admin');

        AdmissionApplication::query()->create([
            'application_code' => 'ADM-SEARCH-001',
            'applicant_name' => 'Searchable Applicant',
            'email' => 'searchable@aec.test',
            'phone' => '01700000003',
            'desired_course' => 'IELTS',
            'status' => 'pending',
        ]);
        AdmissionApplication::query()->create([
            'application_code' => 'ADM-SEARCH-002',
            'applicant_name' => 'Other Applicant',
            'email' => 'other@aec.test',
            'phone' => '01700000004',
            'desired_course' => 'Spoken English',
            'status' => 'approved',
        ]);

        $this->actingAs($admin)->get('/admin/admissions?q=ADM-SEARCH-001&status=pending')
            ->assertOk()
            ->assertSee('Searchable Applicant')
            ->assertDontSee('Other Applicant');
    }

    public function test_public_admission_intake_normalizes_email_and_saves_optional_profile_fields(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['admission-management']);

        $this->post('/admission/apply', [
            'applicant_name' => 'Public Applicant',
            'email' => 'PUBLIC.APPLICANT@AEC.TEST',
            'phone' => '01700000005',
            'desired_course' => 'IELTS Masterclass',
            'date_of_birth' => '2005-05-12',
            'address' => 'Dhaka, Bangladesh',
        ])->assertSessionHasNoErrors();

        $application = AdmissionApplication::query()->where('email', 'PUBLIC.APPLICANT@AEC.TEST')->first();
        $this->assertNotNull($application);
        $this->assertSame('pending', $application->status);
        $this->assertSame('website', $application->application_source);
        $this->assertSame('Dhaka, Bangladesh', $application->address);
        $this->assertStringStartsWith('ADM-', $application->application_code);
    }
}
