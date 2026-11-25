<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('flash_sales')) {
            return;
        }

        // Add each field only when absent so an interrupted deployment can be retried.
        Schema::table('flash_sales', function (Blueprint $table): void {
            if (!Schema::hasColumn('flash_sales', 'subtitle')) {
                $table->string('subtitle', 220)->nullable();
            }
            if (!Schema::hasColumn('flash_sales', 'description')) {
                $table->text('description')->nullable();
            }
            if (!Schema::hasColumn('flash_sales', 'badge_text')) {
                $table->string('badge_text', 40)->nullable();
            }
            if (!Schema::hasColumn('flash_sales', 'banner_image_url')) {
                $table->string('banner_image_url', 2048)->nullable();
            }
            if (!Schema::hasColumn('flash_sales', 'cta_label')) {
                $table->string('cta_label', 40)->default('Explore offer');
            }
            if (!Schema::hasColumn('flash_sales', 'priority')) {
                $table->unsignedSmallInteger('priority')->default(0);
            }
            if (!Schema::hasColumn('flash_sales', 'branch_id')) {
                $table->unsignedBigInteger('branch_id')->nullable();
            }
            if (!Schema::hasColumn('flash_sales', 'terms')) {
                $table->text('terms')->nullable();
            }
        });

        // Named indexes are checked independently so partial retries are safe.
        if (!Schema::hasIndex('flash_sales', 'flash_sales_branch_schedule')) {
            Schema::table('flash_sales', function (Blueprint $table): void {
                $table->index(
                    ['branch_id', 'is_published', 'is_active', 'starts_at', 'ends_at'],
                    'flash_sales_branch_schedule'
                );
            });
        }

        if (!Schema::hasIndex('flash_sales', 'flash_sales_course_priority')) {
            Schema::table('flash_sales', function (Blueprint $table): void {
                $table->index(
                    ['course_id', 'priority', 'ends_at'],
                    'flash_sales_course_priority'
                );
            });
        }
    }

    /**
     * This migration is intentionally forward-only.
     *
     * The columns may predate this migration in installations with manually
     * provisioned schemas, and MySQL DDL can partially commit before a later
     * statement fails. Without a durable per-object ownership ledger it is
     * impossible to distinguish objects created here from pre-existing ones.
     * A destructive down() would risk deleting branch assignments and campaign
     * content. Preserve data and require a separately reviewed forward migration
     * for any future schema retirement.
     */
    public function down(): void
    {
        // Intentionally non-destructive: never drop potentially pre-existing data.
    }
};
