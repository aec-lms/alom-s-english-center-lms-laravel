<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('cms_sliders')) return;

        $columns = [
            'mobile_image_path' => fn (Blueprint $table) => $table->string('mobile_image_path', 255)->nullable(),
            'button_label' => fn (Blueprint $table) => $table->string('button_label', 60)->nullable(),
            'text_alignment' => fn (Blueprint $table) => $table->string('text_alignment', 10)->default('center'),
            'transition' => fn (Blueprint $table) => $table->string('transition', 10)->default('fade'),
            'overlay_opacity' => fn (Blueprint $table) => $table->unsignedTinyInteger('overlay_opacity')->default(45),
            'duration_ms' => fn (Blueprint $table) => $table->unsignedSmallInteger('duration_ms')->default(5000),
            'starts_at' => fn (Blueprint $table) => $table->timestamp('starts_at')->nullable(),
            'ends_at' => fn (Blueprint $table) => $table->timestamp('ends_at')->nullable(),
            'open_new_tab' => fn (Blueprint $table) => $table->boolean('open_new_tab')->default(false),
        ];

        foreach ($columns as $column => $add) {
            if (!Schema::hasColumn('cms_sliders', $column)) {
                Schema::table('cms_sliders', fn (Blueprint $table) => $add($table));
            }
        }

        if (!Schema::hasIndex('cms_sliders', 'cms_sliders_group_active_sort_idx')) {
            Schema::table('cms_sliders', fn (Blueprint $table) => $table->index(['key_name', 'is_active', 'sort_order'], 'cms_sliders_group_active_sort_idx'));
        }
    }

    public function down(): void
    {
        // Keep slide configuration when rolling back the plugin migration. Removing
        // these columns would silently erase mobile artwork and campaign schedules.
    }
};
