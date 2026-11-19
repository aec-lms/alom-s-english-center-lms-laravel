<?php

declare(strict_types=1);

namespace Plugins\Modules\AdmissionManagement\Models;

use Illuminate\Database\Eloquent\Model;

class AdmissionApplication extends Model
{
    protected $table = 'admission_applications';

    protected $fillable = [
        'branch_id',
        'application_code',
        'applicant_name',
        'email',
        'phone',
        'guardian_phone',
        'desired_course',
        'preferred_batch',
        'previous_education',
        'date_of_birth',
        'address',
        'application_source',
        'status',
        'reviewer_notes',
        'reviewed_by',
        'reviewed_at',
        'interview_at',
        'assigned_to',
        'created_by',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'interview_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];
}
