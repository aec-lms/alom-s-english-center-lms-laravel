<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('lti_tools', function (Blueprint $table): void {
            $table->id();
            $table->string('client_id', 190)->unique();
            $table->string('tool_name', 160);
            $table->string('deployment_id', 190);
            $table->string('login_initiation_url', 500);
            $table->json('redirect_uris');
            $table->json('jwks');
            $table->json('allowed_scopes');
            $table->unsignedBigInteger('course_id')->nullable()->index();
            $table->unsignedBigInteger('branch_id')->nullable()->index();
            $table->boolean('is_enabled')->default(false);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->unique(['client_id', 'deployment_id'], 'lti_client_deployment_unique');
        });

        Schema::create('lti_platform_keys', function (Blueprint $table): void {
            $table->id();
            $table->string('key_id', 64)->unique();
            $table->longText('private_key_encrypted');
            $table->json('public_jwk');
            $table->boolean('is_active')->default(false)->index();
            $table->timestamp('retire_after')->nullable();
            $table->timestamps();
        });

        Schema::create('lti_launch_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tool_id')->constrained('lti_tools')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->index();
            $table->char('state_hash', 64)->unique();
            $table->char('nonce_hash', 64);
            $table->string('target_link_uri', 500);
            $table->string('resource_link_id', 190);
            $table->timestamp('expires_at')->index();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('lti_launches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tool_id')->constrained('lti_tools')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('subject', 128);
            $table->string('resource_link_id', 190);
            $table->string('context_id', 190)->nullable();
            $table->timestamp('launched_at')->index();
            $table->timestamps();
            $table->unique(['tool_id', 'subject', 'resource_link_id'], 'lti_launch_subject_resource_unique');
        });

        Schema::create('lti_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tool_id')->constrained('lti_tools')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->json('scopes');
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lti_access_tokens');
        Schema::dropIfExists('lti_launches');
        Schema::dropIfExists('lti_launch_states');
        Schema::dropIfExists('lti_platform_keys');
        Schema::dropIfExists('lti_tools');
    }
};
