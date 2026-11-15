<?php

declare(strict_types=1);

namespace Plugins\Modules\CarouselBuilder\Models;

use Illuminate\Database\Eloquent\Model;

class CarouselItem extends Model
{
    protected $table = 'cms_carousels';

    protected $fillable = [
        'carousel_group',
        'title',
        'subtitle',
        'image_path',
        'cta_url',
        'rating',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'rating' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];
}
