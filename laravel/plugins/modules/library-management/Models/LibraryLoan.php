<?php

declare(strict_types=1);

namespace Plugins\Modules\LibraryManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LibraryLoan extends Model
{
    protected $table = 'library_loans';

    protected $fillable = [
        'library_book_id',
        'branch_id',
        'borrower_name',
        'borrower_code',
        'borrowed_at',
        'due_at',
        'returned_at',
        'fine_amount',
        'status',
    ];

    protected $casts = [
        'borrowed_at' => 'date',
        'due_at' => 'date',
        'returned_at' => 'date',
        'fine_amount' => 'decimal:2',
    ];

    public function book(): BelongsTo
    {
        return $this->belongsTo(LibraryBook::class, 'library_book_id');
    }
}
