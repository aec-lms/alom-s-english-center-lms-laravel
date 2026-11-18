<?php
declare(strict_types=1);
namespace Plugins\Modules\Phonetics\Models;
use Illuminate\Database\Eloquent\Model;
final class PhoneticLearningAssignment extends Model
{
    protected $table='phonetic_learning_assignments';
    protected $guarded=['id'];
    protected function casts():array{return ['is_required'=>'boolean','available_from'=>'datetime','available_until'=>'datetime'];}
    public function word(){return $this->belongsTo(PhoneticEntry::class,'word_id');}
}