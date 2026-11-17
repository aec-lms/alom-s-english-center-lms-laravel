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
                $table->string('proof_path', 500)->nullable();
            }
            if (!Schema::hasColumn('universal_payment_transactions', 'proof_mime')) {
                $table->string('proof_mime', 120)->nullable();
            }
            if (!Schema::hasColumn('universal_payment_transactions', 'proof_size')) {
                $table->unsignedBigInteger('proof_size')->nullable();
            }
        });
    }

    public function down(): void
    {
        // Preserve financial evidence and audit history on rollback.
    }
};
