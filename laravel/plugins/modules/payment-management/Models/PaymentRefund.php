<?php

declare(strict_types=1);

namespace Plugins\Modules\PaymentManagement\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentRefund extends Model
{
    protected $fillable = ['payment_transaction_id', 'amount', 'reason', 'provider_refund_reference', 'approved_by'];

    protected $casts = ['amount' => 'decimal:2'];
}
