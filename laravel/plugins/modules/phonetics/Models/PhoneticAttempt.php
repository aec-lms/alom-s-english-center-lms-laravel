<?php
declare(strict_types=1);
namespace Plugins\Modules\Phonetics\Models;
use Illuminate\Database\Eloquent\Model;
final class PhoneticAttempt extends Model{protected $table='alom_english_center_phonetic_attempts';protected $guarded=['id'];protected function casts():array{return['is_correct'=>'boolean'];}}