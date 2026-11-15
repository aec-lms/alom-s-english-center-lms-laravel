<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('mobile_device_tokens')) {
            Schema::create('mobile_device_tokens', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('platform', 20)->index(); // ios | android
                $table->string('device_name', 120);
                $table->string('device_id', 160)->nullable()->index();
                $table->string('app_version', 40)->nullable();
                $table->text('push_token')->nullable();
                $table->timestamp('last_seen_at')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Preserving user/device push registration records on rollback
    }
};
