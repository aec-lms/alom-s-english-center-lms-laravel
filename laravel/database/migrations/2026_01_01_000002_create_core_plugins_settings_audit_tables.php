<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('plugins', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 100)->unique();
            $table->string('name', 160);
            $table->string('version', 32);
            $table->string('author', 120)->default("Alom's English Center");
            $table->string('category', 64)->default('general');
            $table->text('description')->nullable();
            $table->string('core_compatibility', 32)->default('^2.0');
            $table->json('dependencies')->nullable();
            $table->json('manifest_snapshot')->nullable();
            $table->boolean('is_core')->default(false);
            $table->boolean('is_installed')->default(false);
            $table->boolean('is_enabled')->default(false);
            $table->timestamp('installed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('plugin_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('plugin_slug', 100);
            $table->string('setting_key', 120);
            $table->text('setting_value')->nullable();
            $table->boolean('is_encrypted')->default(false);
            $table->timestamps();
            $table->unique(['plugin_slug', 'setting_key']);
        });

        Schema::create('system_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 120)->unique();
            $table->longText('value')->nullable();
            $table->boolean('is_encrypted')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 120)->index();
            $table->string('entity_type', 80)->nullable();
            $table->string('entity_id', 80)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('system_settings');
        Schema::dropIfExists('plugin_settings');
        Schema::dropIfExists('plugins');
    }
};
