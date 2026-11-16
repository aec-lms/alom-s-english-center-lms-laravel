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
        'notes',
        'verified_by',
        'verified_at',
        'user_id',
        'storefront_item_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'verified_at' => 'datetime',
    ];
}
