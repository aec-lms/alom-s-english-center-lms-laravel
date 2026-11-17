<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('universal_payment_transactions')
            && !Schema::hasColumn('universal_payment_transactions', 'learn_earn_exam_id')) {
            Schema::table('universal_payment_transactions', function (Blueprint $table): void {
                $table->unsignedBigInteger('learn_earn_exam_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        // Payment and exam audit history is retained.
    }
};
