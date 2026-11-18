<?php

declare(strict_types=1);

namespace Plugins\Modules\CourseContentStudio\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CourseContentPost extends Model
{
    protected $fillable = [
        'author_id','title','slug','short_description','long_description','video_url',
        'thumbnail_path','content_file_path','content_file_name','content_file_mime','content_file_size',
        'access_type','seo_title','seo_description','tags','status','published_at',
    ];

    protected function casts(): array
    {
        return ['tags'=>'array','published_at'=>'datetime','content_file_size'=>'integer'];
    }

    public function author(): BelongsTo { return $this->belongsTo(User::class, 'author_id'); }
    public function categories(): BelongsToMany { return $this->belongsToMany(CourseContentCategory::class, 'course_content_post_category', 'course_content_post_id', 'category_id'); }
    public function curriculumItems(): HasMany { return $this->hasMany(CourseCurriculumItem::class, 'course_content_post_id'); }
    public function scopePublished(Builder $query): Builder { return $query->where('status','published')->whereNotNull('published_at')->where('published_at','<=',now()); }
    public function getRouteKeyName(): string { return 'slug'; }

    public function getVideoEmbedUrlAttribute(): ?string
    {
        $url=(string)$this->video_url;
        if(!self::safeVideoUrl($url))return null;
        $host=strtolower((string)parse_url($url,PHP_URL_HOST));
        $path=trim((string)parse_url($url,PHP_URL_PATH),'/');
        if(in_array($host,['youtu.be','www.youtu.be'],true)){
            $id=explode('/',$path)[0]??'';
            return preg_match('/^[A-Za-z0-9_-]{11}$/',$id)?'https://www.youtube-nocookie.com/embed/'.$id:null;
        }
        if(in_array($host,['youtube.com','www.youtube.com','m.youtube.com'],true)){
            parse_str((string)parse_url($url,PHP_URL_QUERY),$query);
            $id=$query['v']??'';
            if(preg_match('#^(?:embed|shorts|live)/([A-Za-z0-9_-]{11})$#',$path,$matches))$id=$matches[1];
            return preg_match('/^[A-Za-z0-9_-]{11}$/',(string)$id)?'https://www.youtube-nocookie.com/embed/'.$id:null;
        }
        if(in_array($host,['vimeo.com','www.vimeo.com','player.vimeo.com'],true)){
            $id=basename($path);
            return ctype_digit($id)?'https://player.vimeo.com/video/'.$id:null;
        }
        return null;
    }

    public static function safeVideoUrl(?string $url): bool
    {
        if (!$url) return true;
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        return strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https'
            && in_array($host, ['youtube.com','www.youtube.com','m.youtube.com','youtu.be','www.youtu.be','vimeo.com','www.vimeo.com','player.vimeo.com'], true);
    }
}
