<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationAiAgent extends Model
{
    protected $table = 'automation_ai_agents';
    protected $fillable = ['tenant_id', 'name', 'slug', 'system_prompt', 'configuration', 'status'];

    protected function casts(): array
    {
        return ['configuration' => 'array'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(SaasTenant::class, 'tenant_id');
    }
}
