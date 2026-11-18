<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('plugin_archives', function (Blueprint $table): void {
            $table->boolean('was_installed')->default(true)->after('manifest_snapshot');
            $table->boolean('was_enabled')->default(true)->after('was_installed');
            $table->json('files_sha256')->nullable()->after('was_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('plugin_archives', function (Blueprint $table): void {
            $table->dropColumn(['was_installed', 'was_enabled', 'files_sha256']);
        });
    }
};
