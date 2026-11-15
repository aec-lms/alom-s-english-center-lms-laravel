<?php

declare(strict_types=1);

namespace Plugins\Modules\MarketingManagement\Models;

use Illuminate\Database\Eloquent\Model;

class MarketingCampaign extends Model
{
    protected $table = 'marketing_campaigns';

    protected $fillable = [
        'name',
        'channel',
        'target_course',
        'budget_bdt',
        'utm_code',
        'status',
    ];

    protected $casts = [
        'budget_bdt' => 'decimal:2',
    ];
}
