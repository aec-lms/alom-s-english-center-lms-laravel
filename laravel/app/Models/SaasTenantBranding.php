<?php

declare(strict_types=1);

namespace App\\Models;

use Illuminate\\Database\\Eloquent\\Model;

class SaasTenantBranding extends Model
{
    protected $table = 'saas_tenant_branding';
    protected $fillable = ['tenant_id', 'brand_name', 'logo_path', 'favicon_path', 'primary_color', 'accent_color', 'custom_css'];

    protected function casts(): array
    {
        return ['custom_css' => 'array'];
    }
}
