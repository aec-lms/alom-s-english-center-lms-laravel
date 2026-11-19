<?php

declare(strict_types=1);

namespace Plugins\Modules\CmsWebsite\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CmsPost extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'slug',
        'title',
        'category_id',
        'category',
        'author',
        'excerpt',
        'content',
        'thumbnail_path',
        'pdf_path',
        'pdf_thumbnail',
        'seo_title',
        'seo_description',
        'is_published',
        'publish_at',
    ];

    protected $casts = ['is_published' => 'boolean', 'publish_at' => 'datetime'];

    public function categoryModel(): BelongsTo
    {
        return $this->belongsTo(CmsCategory::class, 'category_id');
    }
}
