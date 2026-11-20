<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('teachers', function (Blueprint $table): void {
            if (!Schema::hasColumn('teachers', 'deleted_at')) {
                $table->softDeletes();
            }
            if (!Schema::hasColumn('teachers', 'deleted_user_was_active')) {
                $table->boolean('deleted_user_was_active')->nullable()->after('deleted_at');
            }
        });
    }

    public function down(): void
    {
        // This migration conditionally adds soft-delete fields but does not record
        // ownership of pre-existing host columns. Retain the fields on rollback
        // rather than risk deleting host schema or Recycle Bin history.
    }
};
