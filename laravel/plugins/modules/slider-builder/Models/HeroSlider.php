<?php

declare(strict_types=1);

namespace Plugins\Modules\SliderBuilder\Models;

use Illuminate\Database\Eloquent\Model;

class HeroSlider extends Model
{
    protected $table = 'cms_sliders';

    protected $fillable = [
        'key_name',
        'title',
        'subtitle',
        'image_path',
        'cta_url',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
