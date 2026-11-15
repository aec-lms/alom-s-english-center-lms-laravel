<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('sms_campaigns')) {
            Schema::create('sms_campaigns', function (Blueprint $table): void {
                $table->id();
                $table->string('title', 180);
                $table->string('audience', 60)->default('students');
                $table->text('message');
                $table->unsignedInteger('recipient_count')->default(0);
                $table->string('provider', 40)->default('greenweb');
                $table->string('status', 30)->default('queued');
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Preserved on uninstall per core policy
    }
};
