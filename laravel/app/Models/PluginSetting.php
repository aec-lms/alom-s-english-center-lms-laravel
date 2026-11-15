<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class PluginSetting extends Model
{
    protected $fillable = ['plugin_slug', 'setting_key', 'setting_value', 'is_encrypted'];

    protected function casts(): array
    {
        return ['is_encrypted' => 'boolean'];
    }

    public function decryptedValue(): ?string
    {
        if ($this->setting_value === null) {
            return null;
        }

        return $this->is_encrypted
            ? Crypt::decryptString($this->setting_value)
            : $this->setting_value;
    }
}
