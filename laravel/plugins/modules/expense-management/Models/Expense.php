<?php

declare(strict_types=1);

namespace Plugins\\Modules\\ExpenseManagement\\Models;

use Illuminate\\Database\\Eloquent\\Model;
use Illuminate\\Database\\Eloquent\\Relations\\BelongsTo;

class Expense extends Model
{
    protected $table = 'expenses';

    protected $fillable = [
        'branch_id', 'created_by', 'category', 'title', 'description',
        'amount', 'payment_method', 'reference', 'expense_date',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'expense_date' => 'date',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(\\App\\Models\\Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(\\App\\Models\\User::class, 'created_by');
    }
}
