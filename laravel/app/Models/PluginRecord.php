<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PluginRecord extends Model
{
    protected $table = 'plugins';

    protected $fillable = [
        'slug',
        'name',
        'version',
        'author',
        'category',
        'description',
        'core_compatibility',
        'dependencies',
        'manifest_snapshot',
        'is_core',
        'is_installed',
        'is_enabled',
        'installed_at',
    ];

    protected function casts(): array
    {
        return [
            'dependencies' => 'array',
            'manifest_snapshot' => 'array',
            'is_core' => 'boolean',
            'is_installed' => 'boolean',
            'is_enabled' => 'boolean',
            'installed_at' => 'datetime',
        ];
    }
}
