<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PluginArchive extends Model
{
    protected $fillable = ['plugin_slug', 'plugin_name', 'version', 'archive_path', 'manifest_snapshot', 'files_sha256', 'was_installed', 'was_enabled', 'deleted_by', 'deleted_at'];

    protected function casts(): array
    {
        return ['manifest_snapshot' => 'array', 'files_sha256' => 'array', 'was_installed' => 'boolean', 'was_enabled' => 'boolean', 'deleted_at' => 'datetime'];
    }
}
