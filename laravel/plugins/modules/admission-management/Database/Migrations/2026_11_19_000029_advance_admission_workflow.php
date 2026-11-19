<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('admission_applications')) {
            return;
        }

        Schema::table('admission_applications', function (Blueprint $table): void {
            if (!Schema::hasColumn('admission_applications', 'date_of_birth')) $table->date('date_of_birth')->nullable();
            if (!Schema::hasColumn('admission_applications', 'address')) $table->text('address')->nullable();
            if (!Schema::hasColumn('admission_applications', 'application_source')) $table->string('application_source', 32)->default('website');
            if (!Schema::hasColumn('admission_applications', 'interview_at')) $table->timestamp('interview_at')->nullable();
            if (!Schema::hasColumn('admission_applications', 'assigned_to')) $table->unsignedBigInteger('assigned_to')->nullable()->index();
            if (!Schema::hasColumn('admission_applications', 'created_by')) $table->unsignedBigInteger('created_by')->nullable()->index();
        });

        if (Schema::hasColumn('admission_applications', 'status')) {
            Schema::table('admission_applications', function (Blueprint $table): void {
                $table->index(['status', 'branch_id'], 'admission_status_branch_idx');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('admission_applications')) return;

        Schema::table('admission_applications', function (Blueprint $table): void {
            foreach (['date_of_birth', 'address', 'application_source', 'interview_at', 'assigned_to', 'created_by'] as $column) {
                if (Schema::hasColumn('admission_applications', $column)) $table->dropColumn($column);
            }
            try { $table->dropIndex('admission_status_branch_idx'); } catch (\Throwable) {}
        });
    }
};
