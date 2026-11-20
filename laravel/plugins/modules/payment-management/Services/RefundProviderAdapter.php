<?php

declare(strict_types=1);

namespace Plugins\Modules\PaymentManagement\Services;

use Plugins\Modules\PaymentManagement\Models\PaymentRefund;
use Plugins\Modules\PaymentManagement\Models\PaymentTransaction;

interface RefundProviderAdapter
{
    public function submit(PaymentTransaction $transaction, PaymentRefund $refund): RefundProviderResult;

    public function reconcile(PaymentTransaction $transaction, PaymentRefund $refund): RefundProviderResult;
}
