<?php

declare(strict_types=1);

namespace Plugins\Modules\MarketingManagement\Models;

use Illuminate\Database\Eloquent\Model;

class PromoCoupon extends Model
{
    protected $table = 'promo_coupons';

    protected $fillable = [
        'code',
        'discount_percent',
        'max_uses',
        'used_count',
        'expires_at',
        'is_active',
    ];

    protected $casts = [
        'discount_percent' => 'integer',
        'max_uses' => 'integer',
        'used_count' => 'integer',
        'expires_at' => 'date',
        'is_active' => 'boolean',
    ];
}
