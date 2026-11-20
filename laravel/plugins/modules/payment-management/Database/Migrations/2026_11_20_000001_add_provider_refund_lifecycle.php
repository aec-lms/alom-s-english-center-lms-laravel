<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('payment_refunds')) {
            return;
        }

        Schema::table('payment_refunds', function (Blueprint $table): void {
            if (!Schema::hasColumn('payment_refunds', 'gateway_slug')) {
                $table->string('gateway_slug', 64)->default('offline');
            }
            if (!Schema::hasColumn('payment_refunds', 'status')) {
                // Existing rows are historical offline refunds whose ledger
                // effects were already applied by the legacy controller.
                $table->string('status', 32)->default('succeeded');
            }
            if (!Schema::hasColumn('payment_refunds', 'requested_by')) {
                $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('payment_refunds', 'rejected_by')) {
                $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('payment_refunds', 'rejected_at')) {
                $table->timestamp('rejected_at')->nullable();
            }
            if (!Schema::hasColumn('payment_refunds', 'idempotency_key')) {
                $table->string('idempotency_key', 120)->nullable();
            }
            if (!Schema::hasColumn('payment_refunds', 'provider_idempotency_key')) {
                $table->string('provider_idempotency_key', 120)->nullable();
            }
            if (!Schema::hasColumn('payment_refunds', 'provider_status')) {
                $table->string('provider_status', 40)->nullable();
            }
            if (!Schema::hasColumn('payment_refunds', 'request_fingerprint')) {
                $table->char('request_fingerprint', 64)->nullable();
            }
            if (!Schema::hasColumn('payment_refunds', 'metadata')) {
                $table->json('metadata')->nullable();
            }
            if (!Schema::hasColumn('payment_refunds', 'submitted_at')) {
                $table->timestamp('submitted_at')->nullable();
            }
            if (!Schema::hasColumn('payment_refunds', 'succeeded_at')) {
                $table->timestamp('succeeded_at')->nullable();
            }
            if (!Schema::hasColumn('payment_refunds', 'failed_at')) {
                $table->timestamp('failed_at')->nullable();
            }
            if (!Schema::hasColumn('payment_refunds', 'reconciled_at')) {
                $table->timestamp('reconciled_at')->nullable();
            }
            if (!Schema::hasColumn('payment_refunds', 'ledger_applied_at')) {
                $table->timestamp('ledger_applied_at')->nullable();
            }
        });

        // Historical refund rows predate the lifecycle state machine. Preserve
        // their already-applied ledger effect and never replay them as new work.
        DB::table('payment_refunds')
            ->where('status', 'succeeded')
            ->whereNull('ledger_applied_at')
            ->update(['ledger_applied_at' => DB::raw('created_at')]);

        // Unique idempotency per payment transaction prevents duplicate local
        // refund requests while allowing multiple distinct partial refunds.
        if (!Schema::hasIndex('payment_refunds', ['payment_transaction_id', 'idempotency_key'])) {
            $duplicateKeys = DB::table('payment_refunds')
                ->select('payment_transaction_id', 'idempotency_key')
                ->whereNotNull('idempotency_key')
                ->groupBy('payment_transaction_id', 'idempotency_key')
                ->havingRaw('COUNT(*) > 1')
                ->exists();
            if ($duplicateKeys) {
                throw new RuntimeException('Duplicate payment refund idempotency keys must be reconciled before applying the refund lifecycle migration.');
            }

            Schema::table('payment_refunds', function (Blueprint $table): void {
                $table->unique(
                    ['payment_transaction_id', 'idempotency_key'],
                    'payment_refunds_transaction_idempotency_unique'
                );
            });
        }

        if (!Schema::hasIndex('payment_refunds', ['gateway_slug', 'provider_refund_reference'])) {
            $duplicateProviderReferences = DB::table('payment_refunds')
                ->select('gateway_slug', 'provider_refund_reference')
                ->whereNotNull('provider_refund_reference')
                ->groupBy('gateway_slug', 'provider_refund_reference')
                ->havingRaw('COUNT(*) > 1')
                ->exists();
            if ($duplicateProviderReferences) {
                throw new RuntimeException('Duplicate provider refund references must be reconciled before applying the refund lifecycle migration.');
            }

            Schema::table('payment_refunds', function (Blueprint $table): void {
                $table->unique(
                    ['gateway_slug', 'provider_refund_reference'],
                    'payment_refunds_gateway_provider_reference_unique'
                );
            });
        }
    }

    public function down(): void
    {
        // Keep lifecycle/audit columns and idempotency uniqueness permanently.
        // Drop only the new index that depends on provider_refund_reference so
        // the preceding legacy migration can be rolled back in order.
        if (Schema::hasTable('payment_refunds')
            && Schema::hasIndex('payment_refunds', 'payment_refunds_gateway_provider_reference_unique')) {
            Schema::table('payment_refunds', function (Blueprint $table): void {
                $table->dropUnique('payment_refunds_gateway_provider_reference_unique');
            });
        }
    }
};
