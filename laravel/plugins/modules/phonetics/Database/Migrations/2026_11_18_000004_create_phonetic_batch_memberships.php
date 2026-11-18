<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('phonetic_batch_memberships')) {
            Schema::create('phonetic_batch_memberships', function (Blueprint $table): void {
                $table->id();
                $table->string('lms_driver', 40)->default('aec');
                $table->string('batch_ref', 128);
                $table->string('user_id', 128)->index();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->unique(['lms_driver', 'batch_ref', 'user_id'], 'phonetic_batch_member_unique');
                $table->index(['user_id', 'is_active']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('phonetic_batch_memberships');
    }
};
