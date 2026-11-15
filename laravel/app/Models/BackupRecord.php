<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BackupRecord extends Model
{
    protected $table = 'backups';

    protected $fillable = [
        'filename',
        'storage_path',
        'checksum_sha256',
        'is_encrypted',
        'includes_files',
        'size_bytes',
        'core_version',
        'metadata',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_encrypted' => 'boolean',
            'includes_files' => 'boolean',
            'metadata' => 'array',
        ];
    }
}
