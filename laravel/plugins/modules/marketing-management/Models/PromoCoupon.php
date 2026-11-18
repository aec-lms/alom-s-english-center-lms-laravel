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
        'discount_type',
        'discount_amount',
        'minimum_subtotal',
        'maximum_discount',
        'per_user_limit',
        'starts_at',
        'applies_to_course_ids',
        'first_purchase_only',
    ];

    protected $casts = [
        'discount_percent' => 'integer',
        'max_uses' => 'integer',
        'used_count' => 'integer',
        'expires_at' => 'date',
        'is_active' => 'boolean',
        'discount_amount' => 'decimal:2',
        'minimum_subtotal' => 'decimal:2',
        'maximum_discount' => 'decimal:2',
        'per_user_limit' => 'integer',
        'starts_at' => 'datetime',
        'applies_to_course_ids' => 'array',
        'first_purchase_only' => 'boolean',
    ];
}
