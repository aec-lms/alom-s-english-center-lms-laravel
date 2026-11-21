<?php

declare(strict_types=1);

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('alom_english_center_loader_settings')) {
            return;
        }

        Schema::create('alom_english_center_loader_settings', function (Blueprint $table): void {
            $table->id();
            $table->boolean('enabled')->default(true);
            $table->string('headline', 120)->default("Alom's English Center");
            $table->string('subtext', 200)->nullable();
            $table->string('logo_path', 255)->nullable();
            $table->string('animation_style', 40)->default('progress-crest');
            $table->string('background_color', 7)->default('#F01800');
            $table->string('accent_color', 7)->default('#38BDF8');
            $table->string('text_color', 7)->default('#F8FAFC');
            $table->boolean('backdrop_blur')->default(true);
            $table->boolean('show_progress_label')->default(true);
            $table->boolean('respect_reduced_motion')->default(true);
            $table->unsignedInteger('min_duration_ms')->default(500);
            $table->unsignedInteger('fade_duration_ms')->default(250);
            $table->unsignedInteger('max_timeout_ms')->default(5000);
            $table->string('trigger_mode', 40)->default('every-load');
            $table->json('excluded_paths')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        DB::table('alom_english_center_loader_settings')->insert([
            'enabled' => true,
            'headline' => "Alom's English Center",
            'subtext' => 'Preparing your classroom',
            'animation_style' => 'progress-crest',
            'background_color' => '#F01800',
            'accent_color' => '#38BDF8',
            'text_color' => '#F8FAFC',
            'backdrop_blur' => true,
            'show_progress_label' => true,
            'respect_reduced_motion' => true,
            'min_duration_ms' => 500,
            'fade_duration_ms' => 250,
            'max_timeout_ms' => 5000,
            'trigger_mode' => 'every-load',
            'excluded_paths' => json_encode([
                'admin/*',
                'api/*',
                'payment/*',
                'checkout/*',
                'webhook/*',
                'live-class/*',
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Settings may predate this migration on upgraded installations. The
        // migration cannot reliably distinguish an adopted table from one it
        // created, so rollback must preserve the table and its configuration.
        \\Illuminate\\Support\\Facades\\Cache::forget('al-branding-loader.settings');
    }
};
