<?php

declare(strict_types=1);

namespace Plugins\Modules\CertificateManagement\Models;

use Illuminate\Database\Eloquent\Model;

class CertificateRecord extends Model
{
    protected $table = 'certificates';

    protected $fillable = [
        'branch_id',
        'verification_code',
        'student_name',
        'student_code',
        'course_title',
        'grade_or_band',
        'remarks',
        'issued_at',
        'status',
        'issued_by',
    ];

    protected $casts = [
        'issued_at' => 'date',
    ];
}
