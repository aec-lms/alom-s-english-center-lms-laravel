<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('fee_invoices') && !Schema::hasColumn('fee_invoices', 'payer_phone')) {
            Schema::table('fee_invoices', function (Blueprint $table): void {
                $table->string('payer_phone', 40)->nullable()->after('payer_email');
            });
        }
    }

    public function down(): void
    {
        // Preserve payer contact data on rollback.
    }
};
