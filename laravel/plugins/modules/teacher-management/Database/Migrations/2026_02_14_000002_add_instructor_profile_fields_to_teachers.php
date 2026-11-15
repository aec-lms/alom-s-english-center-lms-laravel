<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('teachers')) {
            Schema::table('teachers', function (Blueprint $table): void {
                if (!Schema::hasColumn('teachers', 'photo_path')) {
                    $table->string('photo_path', 255)->nullable()->after('specialization');
                }
                if (!Schema::hasColumn('teachers', 'credentials')) {
                    $table->string('credentials', 180)->nullable()->after('photo_path');
                }
                if (!Schema::hasColumn('teachers', 'bio')) {
                    $table->text('bio')->nullable()->after('credentials');
                }
                if (!Schema::hasColumn('teachers', 'youtube_url')) {
                    $table->string('youtube_url', 255)->nullable()->after('bio');
                }
                if (!Schema::hasColumn('teachers', 'facebook_url')) {
                    $table->string('facebook_url', 255)->nullable()->after('youtube_url');
                }
            });
        }
    }

    public function down(): void {}
};
