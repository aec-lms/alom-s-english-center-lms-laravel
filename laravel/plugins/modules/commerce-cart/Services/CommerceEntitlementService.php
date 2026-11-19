<?php

declare(strict_types=1);

namespace Plugins\Modules\CommerceCart\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CommerceEntitlementService
{
    public function hasActive(int $userId, string $type, ?int $resourceId = null): bool
    {
        if (!Schema::hasTable('commerce_entitlements')) return false;

        return DB::table('commerce_entitlements')
            ->where('user_id', $userId)
            ->where('entitlement_type', $type)
            ->where('status', 'active')
            ->when($resourceId !== null, fn ($query) => $query->where('resource_id', $resourceId))
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->exists();
    }

    public function hasActiveSubscription(int $userId): bool
    {
        return $this->hasActive($userId, 'subscription');
    }
}
