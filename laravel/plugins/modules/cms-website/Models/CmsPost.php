<?php

declare(strict_types=1);

namespace Plugins\Modules\CmsWebsite\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CmsPost extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'slug',
        'title',
        'category_id',
        'category',
        'author',
        'excerpt',
        'content',
        'thumbnail_path',
        'pdf_path',
        'pdf_thumbnail',
        'seo_title',
        'seo_description',
        'is_published',
        'publish_at',
        'created_by',
        'author_user_id',\n        'updated_by',\n        'assigned_editor_id',\n        'assigned_reviewer_id',
        'editorial_status',
        'submitted_at',
        'submitted_by',
        'reviewed_at',
        'reviewed_by',
        'review_note',
    ];

    protected $casts = ['is_published' => 'boolean', 'publish_at' => 'datetime', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime'];


    public function primaryAuthor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function assignedEditor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_editor_id');
    }

    public function assignedReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_reviewer_id');
    }

    public function contributors(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'cms_post_authors', 'post_id', 'user_id')
            ->withPivot(['contributor_type', 'sort_order'])
            ->withTimestamps()
            ->orderByPivot('sort_order');
    }

    public function categoryModel(): BelongsTo
    {
        return $this->belongsTo(CmsCategory::class, 'category_id');
    }
}
