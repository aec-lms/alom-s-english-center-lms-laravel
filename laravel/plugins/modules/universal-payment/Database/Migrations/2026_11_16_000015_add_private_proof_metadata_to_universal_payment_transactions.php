<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('universal_payment_transactions')) {
            return;
        }

        Schema::table('universal_payment_transactions', function (Blueprint $table): void {
            if (!Schema::hasColumn('universal_payment_transactions', 'proof_path')) {
                $table->string('proof_path')->nullable();
            }
            if (!Schema::hasColumn('universal_payment_transactions', 'proof_mime')) {
                $table->string('proof_mime', 100)->nullable();
            }
            if (!Schema::hasColumn('universal_payment_transactions', 'proof_size')) {
                $table->unsignedInteger('proof_size')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('universal_payment_transactions', function (Blueprint $table): void {
            foreach (['proof_path', 'proof_mime', 'proof_size'] as $column) {
                if (Schema::hasColumn('universal_payment_transactions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
