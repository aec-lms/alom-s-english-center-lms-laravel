<?php

declare(strict_types=1);

namespace Plugins\Modules\AdminBoosting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class BoostCampaign extends Model
{
    use SoftDeletes;

    protected $table = 'boost_campaigns';

    protected $fillable = [
        'campaign_name',
        'entity_type',
        'entity_id',
        'placement',
        'badge_text',
        'cta_text',
        'destination_url',
        'priority',
        'status',
        'starts_at',
        'ends_at',
        'impressions',
        'clicks',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];
}
