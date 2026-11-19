<?php

declare(strict_types=1);

namespace Plugins\Modules\SliderBuilder\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class HeroSlider extends Model
{
    use SoftDeletes;

    protected $table = 'cms_sliders';

    protected $fillable = [
        'key_name', 'title', 'subtitle', 'image_path', 'mobile_image_path',
        'cta_url', 'button_label', 'sort_order', 'is_active', 'text_alignment',
        'transition', 'overlay_opacity', 'duration_ms', 'starts_at', 'ends_at',
        'open_new_tab',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'open_new_tab' => 'boolean',
        'overlay_opacity' => 'integer',
        'duration_ms' => 'integer',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function scopeVisibleNow($query)
    {
        return $query->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }
}
