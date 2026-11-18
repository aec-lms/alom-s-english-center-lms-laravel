<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('guardians')) {
            Schema::create('guardians', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->string('relationship', 60)->default('parent');
                $table->string('occupation', 120)->nullable();
                $table->string('emergency_phone', 32)->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Preserved on uninstall.
    }
};
