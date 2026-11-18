<?php
declare(strict_types=1);
namespace Plugins\Modules\Phonetics\Models;
use Illuminate\Database\Eloquent\Model;
final class PhoneticEntry extends Model{protected $table='alom_english_center_phonetic_entries';protected $guarded=['id'];protected function casts():array{return['is_active'=>'boolean','sort_order'=>'integer'];}}