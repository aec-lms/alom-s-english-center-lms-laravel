<?php

declare(strict_types=1);

namespace Plugins\Modules\PageBuilder\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class BuilderPage extends Model
{
    use SoftDeletes;

    protected $table = 'cms_pages';

    protected $fillable = [
        'slug',
        'title',
        'template',
        'banner_image',
        'blocks_json',
        'seo_title',
        'seo_description',
        'is_published',
    ];

    protected $casts = [
        'blocks_json' => 'array',
        'is_published' => 'boolean',
    ];
}
