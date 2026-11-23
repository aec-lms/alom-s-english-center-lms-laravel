<?php

declare(strict_types=1);

namespace App\\Models;

use Illuminate\\Database\\Eloquent\\Model;

class AutomationWorkflow extends Model
{
    protected $table = 'automation_workflows';
    protected $fillable = ['tenant_id', 'name', 'trigger_event', 'definition', 'status', 'version'];

    protected function casts(): array
    {
        return ['definition' => 'array'];
    }
}
