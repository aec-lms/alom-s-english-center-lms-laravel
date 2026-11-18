<?php

declare(strict_types=1);

namespace Plugins\Modules\CourseBundles\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CourseBundleOrder extends Model
{
    protected $fillable = [
        'bundle_id', 'user_id', 'invoice_id', 'branch_id', 'bundle_title',
        'amount', 'currency', 'status', 'purchased_at',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'purchased_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CourseBundleOrderItem::class, 'order_id')->orderBy('position');
    }
}
