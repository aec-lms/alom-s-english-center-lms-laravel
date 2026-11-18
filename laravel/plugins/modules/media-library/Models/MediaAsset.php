<?php

declare(strict_types=1);

namespace Plugins\Modules\MediaLibrary\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MediaAsset extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'uploaded_by', 'branch_id', 'disk', 'path', 'original_name', 'mime_type',
        'size_bytes', 'collection', 'alt_text',
    ];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer'];
    }

    public function getPublicUrlAttribute(): ?string
    {
        return $this->disk === 'public' ? \Illuminate\Support\Facades\Storage::disk('public')->url($this->path) : null;
    }
}
