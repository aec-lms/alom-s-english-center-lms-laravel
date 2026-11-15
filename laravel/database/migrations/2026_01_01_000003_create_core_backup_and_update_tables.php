<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('backups', function (Blueprint $table): void {
            $table->id();
            $table->string('filename', 255);
            $table->string('storage_path', 500);
            $table->string('checksum_sha256', 64);
            $table->boolean('is_encrypted')->default(true);
            $table->boolean('includes_files')->default(true);
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('core_version', 32);
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('update_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('target_type', 32); // core | plugin
            $table->string('target_slug', 100);
            $table->string('from_version', 32)->nullable();
            $table->string('to_version', 32);
            $table->string('package_checksum', 64);
            $table->string('status', 32); // verified | applied | rolled_back | failed
            $table->string('backup_reference', 255)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('executed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('update_logs');
        Schema::dropIfExists('backups');
    }
};
