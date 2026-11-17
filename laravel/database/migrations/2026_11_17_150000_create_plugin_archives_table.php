<?php

declare(strict_types=1);

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('plugin_archives', function (Blueprint $table): void {
            $table->id();
            $table->string('plugin_slug', 120)->index();
            $table->string('plugin_name');
            $table->string('version', 40)->default('0.0.0');
            $table->string('archive_path', 500);
            $table->json('manifest_snapshot')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->timestamp('deleted_at')->useCurrent();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plugin_archives');
    }
};
