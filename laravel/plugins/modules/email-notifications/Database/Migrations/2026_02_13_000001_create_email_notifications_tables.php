<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('email_templates')) {
            Schema::create('email_templates', function (Blueprint $table): void {
                $table->id();
                $table->string('template_key', 80)->unique();
                $table->string('subject', 200);
                $table->text('body_html');
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('email_logs')) {
            Schema::create('email_logs', function (Blueprint $table): void {
                $table->id();
                $table->string('recipient_email', 160);
                $table->string('subject', 200);
                $table->text('body_excerpt');
                $table->string('template_key', 80)->nullable();
                $table->string('status', 32)->default('queued'); // queued, sent, failed
                $table->timestamp('dispatched_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Preserved on uninstall.
    }
};
