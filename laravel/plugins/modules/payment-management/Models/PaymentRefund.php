<?php

declare(strict_types=1);

namespace Plugins\Modules\PaymentManagement\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentRefund extends Model
{
    protected $fillable = [
        'payment_transaction_id',
        'amount',
        'reason',
        'provider_refund_reference',
        'gateway_slug',
        'status',
        'requested_by',
        'approved_by',
        'rejected_by',
        'rejected_at',
        'idempotency_key',
        'provider_idempotency_key',
        'provider_status',
        'request_fingerprint',
        'metadata',
        'submitted_at',
        'succeeded_at',
        'failed_at',
        'reconciled_at',
        'ledger_applied_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'metadata' => 'array',
        'submitted_at' => 'datetime',
        'succeeded_at' => 'datetime',
        'failed_at' => 'datetime',
        'reconciled_at' => 'datetime',
        'rejected_at' => 'datetime',
        'ledger_applied_at' => 'datetime',
    ];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class, 'payment_transaction_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }
}
