<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\DeliverAutomationWebhook;
use App\Models\SaasTenant;
use App\Services\Automation\WebhookEventDispatcher;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AutomationSaasWorkspaceTest extends TestCase
{
    private function tenant($owner, string $slug = 'test-workspace', string $plan = 'growth'): SaasTenant
    {
        return SaasTenant::create([
            'owner_user_id' => $owner->id,
            'name' => 'Test Workspace',
            'slug' => $slug,
            'status' => 'trial',
            'plan_key' => $plan,
        ]);
    }

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

    public function test_workspace_owner_can_create_agent_and_workflow_within_plan_limits(): void
    {
        $this->markSystemInstalled();
        $admin = $this->createUserWithRole('admin', null, 'saas-owner@aec.test');
        $tenant = $this->tenant($admin, 'owner-workspace', 'starter');

        $this->actingAs($admin)->post(route('admin.automation-saas.agents.store', $tenant), [
            'name' => 'English Support Bot',
            'system_prompt' => 'Answer student questions about course schedules.',
        ])->assertRedirect(route('admin.automation-saas.index'));

        $this->actingAs($admin)->post(route('admin.automation-saas.workflows.store', $tenant), [
            'name' => 'Welcome new contacts',
            'trigger_event' => 'contact.created',
        ])->assertRedirect(route('admin.automation-saas.index'));

        $this->assertDatabaseHas('automation_ai_agents', ['tenant_id' => $tenant->id, 'name' => 'English Support Bot', 'status' => 'draft']);
        $this->assertDatabaseHas('automation_workflows', ['tenant_id' => $tenant->id, 'name' => 'Welcome new contacts', 'status' => 'draft']);
    }

    public function test_starter_plan_enforces_agent_quota(): void
    {
        $this->markSystemInstalled();
        $admin = $this->createUserWithRole('admin', null, 'saas-quota@aec.test');
        $tenant = $this->tenant($admin, 'quota-workspace', 'starter');
        $tenant->agents()->create([
            'name' => 'Existing agent',
            'slug' => 'existing-agent',
            'system_prompt' => 'Test',
            'status' => 'draft',
            'configuration' => [],
        ]);

        $this->actingAs($admin)->post(route('admin.automation-saas.agents.store', $tenant), [
            'name' => 'Second agent',
        ])->assertForbidden();

        $this->assertDatabaseCount('automation_ai_agents', 1);
    }

    public function test_workspace_owner_can_create_crm_contact_and_campaign_draft(): void
    {
        $this->markSystemInstalled();
        $admin = $this->createUserWithRole('admin', null, 'saas-crm-owner@aec.test');
        $tenant = $this->tenant($admin, 'crm-workspace');

        $this->actingAs($admin)->post(route('admin.automation-saas.contacts.store', $tenant), [
            'name' => 'Test Contact',
            'email' => 'contact@example.test',
            'phone' => '+8801700000000',
            'source' => 'manual',
        ])->assertRedirect();

        $this->actingAs($admin)->post(route('admin.automation-saas.campaigns.store', $tenant), [
            'name' => 'Welcome campaign',
            'channel' => 'email',
            'message_template' => 'Welcome to our academy.',
        ])->assertRedirect();

        $this->assertDatabaseHas('automation_contacts', ['tenant_id' => $tenant->id, 'email' => 'contact@example.test']);
        $this->assertDatabaseHas('automation_campaigns', ['tenant_id' => $tenant->id, 'name' => 'Welcome campaign', 'status' => 'draft', 'sent_count' => 0]);
    }

    public function test_workspace_owner_cannot_access_another_tenants_records(): void
    {
        $this->markSystemInstalled();
        $owner = $this->createUserWithRole('teacher', null, 'saas-teacher-owner@aec.test');
        $other = $this->createUserWithRole('teacher', null, 'saas-teacher-other@aec.test');
        $tenant = $this->tenant($other, 'private-workspace');

        $this->actingAs($owner)->post(route('admin.automation-saas.contacts.store', $tenant), [
            'email' => 'intruder@example.test',
            'source' => 'manual',
        ])->assertForbidden();

        $this->assertDatabaseMissing('automation_contacts', ['email' => 'intruder@example.test']);
    }

    public function test_provider_secrets_are_encrypted_at_rest(): void
    {
        $this->markSystemInstalled();
        $admin = $this->createUserWithRole('admin', null, 'saas-secret-owner@aec.test');
        $tenant = $this->tenant($admin, 'secret-workspace');

        $channel = $tenant->channels()->create([
            'provider' => 'whatsapp',
            'name' => 'Primary WhatsApp',
            'status' => 'disconnected',
            'credentials_encrypted' => 'test-access-token',
        ]);
        $stored = \\Illuminate\\Support\\Facades\\DB::table('automation_channels')->where('id', $channel->id)->value('credentials_encrypted');

        $this->assertNotSame('test-access-token', $stored);
        $this->assertSame('test-access-token', $channel->fresh()->credentials_encrypted);
    }

    public function test_webhook_events_create_queued_delivery_for_matching_hooks(): void
    {
        $this->markSystemInstalled();
        $admin = $this->createUserWithRole('admin', null, 'saas-webhook-owner@aec.test');
        $tenant = $this->tenant($admin, 'webhook-workspace');
        $webhook = $tenant->webhooks()->create([
            'name' => 'Contact hook',
            'event_name' => 'contact.created',
            'target_url' => 'https://hooks.example.test/events',
            'secret_encrypted' => 'signing-secret',
            'is_active' => true,
        ]);

        Queue::fake();
        $count = app(WebhookEventDispatcher::class)->dispatch($tenant, 'contact.created', ['contact_id' => 42]);

        $this->assertSame(1, $count);
        $this->assertDatabaseHas('automation_webhook_deliveries', [
            'webhook_id' => $webhook->id,
            'event_name' => 'contact.created',
            'status' => 'queued',
        ]);
        Queue::assertPushed(DeliverAutomationWebhook::class);
    }

    public function test_webhook_dispatch_is_scoped_to_the_given_tenant(): void
    {
        $this->markSystemInstalled();
        $admin = $this->createUserWithRole('admin', null, 'saas-webhook-isolation@aec.test');
        $tenant = $this->tenant($admin, 'tenant-a-hooks');
        $otherTenant = $this->tenant($admin, 'tenant-b-hooks');
        $otherTenant->webhooks()->create([
            'name' => 'Other tenant hook',
            'event_name' => 'contact.created',
            'target_url' => 'https://hooks.example.test/other',
            'secret_encrypted' => 'another-secret',
            'is_active' => true,
        ]);

        Queue::fake();
        $count = app(WebhookEventDispatcher::class)->dispatch($tenant, 'contact.created', ['contact_id' => 1]);

        $this->assertSame(0, $count);
        $this->assertDatabaseCount('automation_webhook_deliveries', 0);
        Queue::assertNothingPushed();
    }
}
