<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('saas_tenants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('slug', 100)->unique();
            $table->string('status', 24)->default('trial')->index();
            $table->string('plan_key', 64)->default('starter');
            $table->string('timezone', 64)->default('Asia/Dhaka');
            $table->string('currency', 3)->default('BDT');
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::create('saas_tenant_domains', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('saas_tenants')->cascadeOnDelete();
            $table->string('domain', 253)->unique();
            $table->boolean('is_primary')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('saas_tenant_branding', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained('saas_tenants')->cascadeOnDelete();
            $table->string('brand_name', 160)->nullable();
            $table->string('logo_path')->nullable();
            $table->string('favicon_path')->nullable();
            $table->string('primary_color', 20)->default('#2563eb');
            $table->string('accent_color', 20)->default('#7c3aed');
            $table->json('custom_css')->nullable();
            $table->timestamps();
        });

        Schema::create('automation_ai_agents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('saas_tenants')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('slug', 140);
            $table->text('system_prompt')->nullable();
            $table->json('configuration')->nullable();
            $table->string('status', 24)->default('draft');
            $table->timestamps();
            $table->unique(['tenant_id', 'slug']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('automation_channels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('saas_tenants')->cascadeOnDelete();
            $table->string('provider', 40);
            $table->string('name', 120);
            $table->string('status', 24)->default('disconnected');
            $table->text('credentials_encrypted')->nullable();
            $table->json('configuration')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'provider', 'status']);
        });

        Schema::create('automation_contacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('saas_tenants')->cascadeOnDelete();
            $table->string('name', 160)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('source', 60)->default('manual');
            $table->json('attributes')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'email']);
            $table->index(['tenant_id', 'phone']);
        });

        Schema::create('automation_conversations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('saas_tenants')->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('automation_contacts')->nullOnDelete();
            $table->foreignId('channel_id')->nullable()->constrained('automation_channels')->nullOnDelete();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('subject', 190)->nullable();
            $table->string('status', 24)->default('open')->index();
            $table->string('priority', 16)->default('normal');
            $table->timestamp('last_message_at')->nullable()->index();
            $table->timestamps();
            $table->index(['tenant_id', 'status', 'last_message_at']);
        });

        Schema::create('automation_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained('automation_conversations')->cascadeOnDelete();
            $table->foreignId('sender_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('direction', 12);
            $table->string('message_type', 24)->default('text');
            $table->longText('body')->nullable();
            $table->json('payload')->nullable();
            $table->string('provider_message_id', 190)->nullable()->index();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['conversation_id', 'created_at']);
        });

        Schema::create('automation_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('saas_tenants')->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('channel', 40);
            $table->text('message_template')->nullable();
            $table->string('status', 24)->default('draft')->index();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('audience_count')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->timestamps();
        });

        Schema::create('automation_workflows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('saas_tenants')->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('trigger_event', 100);
            $table->json('definition');
            $table->string('status', 24)->default('draft')->index();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['tenant_id', 'trigger_event', 'status']);
        });

        Schema::create('automation_workflow_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workflow_id')->constrained('automation_workflows')->cascadeOnDelete();
            $table->string('status', 24)->default('queued')->index();
            $table->json('context')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('automation_integrations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('saas_tenants')->cascadeOnDelete();
            $table->string('provider', 80);
            $table->string('name', 120);
            $table->text('credentials_encrypted')->nullable();
            $table->json('configuration')->nullable();
            $table->string('status', 24)->default('disconnected');
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'provider']);
        });

        Schema::create('automation_webhooks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('saas_tenants')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('event_name', 100);
            $table->string('target_url', 2048);
            $table->text('secret_encrypted')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_triggered_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'event_name', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_webhooks');
        Schema::dropIfExists('automation_integrations');
        Schema::dropIfExists('automation_workflow_runs');
        Schema::dropIfExists('automation_workflows');
        Schema::dropIfExists('automation_campaigns');
        Schema::dropIfExists('automation_messages');
        Schema::dropIfExists('automation_conversations');
        Schema::dropIfExists('automation_contacts');
        Schema::dropIfExists('automation_channels');
        Schema::dropIfExists('automation_ai_agents');
        Schema::dropIfExists('saas_tenant_branding');
        Schema::dropIfExists('saas_tenant_domains');
        Schema::dropIfExists('saas_tenants');
    }
};
