<?php

declare(strict_types=1);

namespace Plugins\Modules\PaymentManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Plugins\Modules\FeesManagement\Models\FeeInvoice;

class PaymentTransaction extends Model
{
    protected $fillable = [
        'transaction_ref', 'invoice_id', 'branch_id', 'gateway_slug',
        'amount', 'currency', 'status', 'external_reference', 'metadata', 'settled_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'metadata' => 'array',
        'settled_at' => 'datetime',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(FeeInvoice::class, 'invoice_id');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(PaymentRefund::class, 'payment_transaction_id');
    }
}
