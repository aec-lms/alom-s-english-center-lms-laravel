<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PluginArchive extends Model
{
    protected $fillable = ['plugin_slug', 'plugin_name', 'version', 'archive_path', 'manifest_snapshot', 'deleted_by', 'deleted_at'];

    protected function casts(): array
    {
        return ['manifest_snapshot' => 'array', 'deleted_at' => 'datetime'];
    }
}
