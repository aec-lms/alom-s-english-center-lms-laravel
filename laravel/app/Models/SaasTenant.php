<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SaasTenant extends Model
{
    protected $table = 'saas_tenants';

    protected $fillable = ['owner_user_id', 'name', 'slug', 'status', 'plan_key', 'timezone', 'currency', 'settings'];

    protected function casts(): array
    {
        return ['settings' => 'array'];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function domains(): HasMany\n    {\n        return $this->hasMany(SaasTenantDomain::class, 'tenant_id');\n    }\n\n    public function branding(): HasOne
    {
        return $this->hasOne(SaasTenantBranding::class, 'tenant_id');
    }

    public function agents(): HasMany
    {
        return $this->hasMany(AutomationAiAgent::class, 'tenant_id');
    }

    public function channels(): HasMany
    {
        return $this->hasMany(AutomationChannel::class, 'tenant_id');
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(AutomationContact::class, 'tenant_id');
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(AutomationConversation::class, 'tenant_id');
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(AutomationCampaign::class, 'tenant_id');
    }

    public function workflows(): HasMany
    {
        return $this->hasMany(AutomationWorkflow::class, 'tenant_id');
    }

    public function integrations(): HasMany
    {
        return $this->hasMany(AutomationIntegration::class, 'tenant_id');
    }

    public function webhooks(): HasMany
    {
        return $this->hasMany(AutomationWebhook::class, 'tenant_id');
    }
}
