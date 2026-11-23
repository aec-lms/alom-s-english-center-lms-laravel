<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationContact extends Model
{
    protected $table = 'automation_contacts';
    protected $fillable = ['tenant_id', 'name', 'email', 'phone', 'source', 'attributes'];

    protected function casts(): array { return ['attributes' => 'array']; }
    public function tenant(): BelongsTo { return $this->belongsTo(SaasTenant::class, 'tenant_id'); }
}
