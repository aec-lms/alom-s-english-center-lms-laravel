<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\SaasTenant;
use Tests\TestCase;

class AutomationSaasWorkspaceTest extends TestCase
{
    public function test_admin_can_create_a_white_label_workspace_with_default_branding(): void
    {
        $this->markSystemInstalled();
        $admin = $this->createUserWithRole('admin', null, 'saas-admin@aec.test');

        $this->actingAs($admin)->post(route('admin.automation-saas.tenants.store'), [
            'name' => 'Bright Future Academy',
            'slug' => 'bright-future',
            'plan_key' => 'growth',
        ])->assertRedirect(route('admin.automation-saas.index'));

        $tenant = SaasTenant::query()->where('slug', 'bright-future')->firstOrFail();
        $this->assertSame($admin->id, $tenant->owner_user_id);
        $this->assertDatabaseHas('saas_tenant_branding', [
            'tenant_id' => $tenant->id,
            'brand_name' => 'Bright Future Academy',
        ]);
    }

    public function test_workspace_dashboard_is_restricted_to_platform_admins(): void
    {
        $this->markSystemInstalled();
        $student = $this->createUserWithRole('student', null, 'saas-student@aec.test');

        $this->actingAs($student)->get(route('admin.automation-saas.index'))->assertForbidden();
    }

    public function test_workspace_owner_can_create_ai_agent_and_workflow_drafts(): void
    {
        $this->markSystemInstalled();
        $admin = $this->createUserWithRole('admin', null, 'saas-owner@aec.test');
        $tenant = SaasTenant::create([
            'owner_user_id' => $admin->id,
            'name' => 'Owner Workspace',
            'slug' => 'owner-workspace',
            'status' => 'trial',
            'plan_key' => 'starter',
        ]);

        $this->actingAs($admin)->post(route('admin.automation-saas.agents.store', $tenant), [
            'name' => 'English Support Bot',
            'system_prompt' => 'Answer student questions about course schedules.',
        ])->assertRedirect(route('admin.automation-saas.index'));

        $this->actingAs($admin)->post(route('admin.automation-saas.workflows.store', $tenant), [
            'name' => 'Welcome new contacts',
            'trigger_event' => 'contact.created',
        ])->assertRedirect(route('admin.automation-saas.index'));

        $this->assertDatabaseHas('automation_ai_agents', [
            'tenant_id' => $tenant->id,
            'name' => 'English Support Bot',
            'status' => 'draft',
        ]);
        $this->assertDatabaseHas('automation_workflows', [
            'tenant_id' => $tenant->id,
            'name' => 'Welcome new contacts',
            'trigger_event' => 'contact.created',
            'status' => 'draft',
        ]);
    }


