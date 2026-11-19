<?php
declare(strict_types=1);

namespace Plugins\Modules\AlBrandingLoader\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class LoaderSetting extends Model
{
    protected $table = 'alom_english_center_loader_settings';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'backdrop_blur' => 'boolean',
            'show_progress_label' => 'boolean',
            'respect_reduced_motion' => 'boolean',
            'excluded_paths' => 'array',
        ];
    }

    public static function current(): self
    {
        return Cache::remember('al-branding-loader.settings', 300, static function (): self {
            if (!Schema::hasTable('alom_english_center_loader_settings')) {
                return new self(['enabled' => false]);
            }

            return static::query()->first() ?? new self(['enabled' => false]);
        });
    }

    public function shouldRenderForPath(string $path): bool
    {
        if (!$this->enabled) {
            return false;
        }

        foreach ((array) ($this->excluded_paths ?? []) as $pattern) {
            $pattern = ltrim((string) $pattern, '/');
            if ($pattern !== '' && Str::is($pattern, ltrim($path, '/'))) {
                return false;
            }
        }

        return true;
    }

    public function getLogoUrlAttribute(): ?string
    {
        $path = (string) $this->logo_path;
        if (!preg_match('~^uploads/alom-english-center/loader/[A-Za-z0-9][A-Za-z0-9_-]{0,63}\.(?:jpe?g|png|webp)$~i', $path)) {
            return null;
        }

        return asset($path);
    }
}
