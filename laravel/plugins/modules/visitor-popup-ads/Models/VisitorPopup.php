<?php

declare(strict_types=1);

namespace Plugins\Modules\VisitorPopupAds\Models;

use Illuminate\Database\Eloquent\Model;

class VisitorPopup extends Model
{
    protected $table = 'visitor_popups';

    protected $fillable = [
        'title',
        'badge_text',
        'description',
        'image_path',
        'cta_text',
        'cta_url',
        'delay_seconds',
        'frequency',
        'is_active',
        'views_count',
        'clicks_count',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
