<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AutomationChannel extends Model
{
    protected $table = 'automation_channels';
    protected $fillable = ['tenant_id', 'provider', 'name', 'status', 'credentials_encrypted', 'configuration', 'connected_at'];

    protected function casts(): array
    {
        return ['credentials_encrypted' => 'encrypted', 'configuration' => 'array', 'connected_at' => 'datetime'];
    }
}
