<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AutomationIntegration extends Model
{
    protected $table = 'automation_integrations';
    protected $fillable = ['tenant_id', 'provider', 'name', 'credentials_encrypted', 'configuration', 'status', 'last_synced_at'];

    protected function casts(): array
    {
        return ['credentials_encrypted' => 'encrypted', 'configuration' => 'array', 'last_synced_at' => 'datetime'];
    }
}
