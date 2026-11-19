<?php

declare(strict_types=1);

namespace Plugins\Modules\AdmissionManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'converted_student_id',
        'converted_at',
        'admission_fee_invoice_id',
    ];

    public function documents(): HasMany
    {
        return $this->hasMany(AdmissionDocument::class, 'admission_application_id');
    }

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'interview_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'converted_at' => 'datetime',
        ];
    }
}
