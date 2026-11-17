<?php

declare(strict_types=1);

namespace Plugins\Modules\SmsGateway\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SmsCampaign extends Model
{
    use SoftDeletes;

    protected $table = 'sms_campaigns';

    protected $fillable = [
        'title',
        'audience',
        'message',
        'recipient_count',
        'provider',
        'status',
        'created_by',
    ];
}
