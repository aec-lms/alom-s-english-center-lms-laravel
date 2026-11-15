<?php

declare(strict_types=1);

namespace Plugins\Modules\RoleAiAssistant\Models;

use Illuminate\Database\Eloquent\Model;

class AiAssistantLog extends Model
{
    protected $fillable = [
        'user_id', 'role_slug', 'provider', 'model',
        'prompt_excerpt', 'tokens_estimated', 'status',
    ];
}
