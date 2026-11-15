<?php

declare(strict_types=1);

namespace Plugins\Modules\SeoManager\Models;

use Illuminate\Database\Eloquent\Model;

class SeoMetaRule extends Model
{
    protected $table = 'seo_meta_rules';

    protected $fillable = [
        'route_path',
        'meta_title',
        'meta_description',
        'meta_keywords',
        'canonical_url',
        'og_image',
        'robots_directive',
    ];
}
