<?php

declare(strict_types=1);

namespace Plugins\Modules\GmailMarketing\Models;

use Illuminate\Database\Eloquent\Model;

class EmailCampaign extends Model
{
    protected $table = 'email_campaigns';

    protected $fillable = [
        'subject',
        'audience',
        'body_html',
        'recipient_count',
        'status',
        'created_by',
    ];
}
