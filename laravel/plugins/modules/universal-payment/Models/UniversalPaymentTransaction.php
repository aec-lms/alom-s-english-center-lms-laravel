<?php

declare(strict_types=1);

namespace Plugins\Modules\UniversalPayment\Models;

use Illuminate\Database\Eloquent\Model;

class UniversalPaymentTransaction extends Model
{
    protected $table = 'universal_payment_transactions';

    protected $fillable = [
        'reference',
        'payer_name',
        'payer_contact',
        'item_title',
        'gateway',
        'currency',
        'amount',
        'external_trx_id',
        'status',
        'branch_id',
        'notes',
        'verified_by',
        'verified_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'verified_at' => 'datetime',
    ];
}
