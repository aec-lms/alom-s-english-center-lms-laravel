<?php
declare(strict_types=1);
namespace Plugins\Modules\Phonetics\Models;
use Illuminate\Database\Eloquent\Model;use Illuminate\Support\Facades\Cache;
final class PhoneticSetting extends Model{protected $table='alom_english_center_phonetic_settings';protected $guarded=['id'];protected function casts():array{return['enabled'=>'boolean','course_scoped_only'=>'boolean','require_course_enrollment'=>'boolean','allow_slow_playback'=>'boolean','show_articulatory_notes'=>'boolean'];}public static function current():self{return Cache::remember('phonetics.settings',300,static fn()=>static::query()->first()??new self(['enabled'=>false]));}}