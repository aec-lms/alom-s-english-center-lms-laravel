<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('referral_commission_reversals')) return;
        Schema::table('referral_commission_reversals', function (Blueprint $table): void {
            if (!Schema::hasColumn('referral_commission_reversals', 'reviewed_by')) {
                $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('referral_commission_reversals', 'reviewed_at')) {
                $table->timestamp('reviewed_at')->nullable();
            }
            if (!Schema::hasColumn('referral_commission_reversals', 'admin_note')) {
                $table->string('admin_note', 1000)->nullable();
            }
        });
    }
    public function down(): void
    {
        // Preserve financial recovery audit metadata when rolling back optional plugin code.
    }
};
