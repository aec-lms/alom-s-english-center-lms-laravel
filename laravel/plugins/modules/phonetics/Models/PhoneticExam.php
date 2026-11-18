<?php
declare(strict_types=1);
namespace Plugins\Modules\Phonetics\Models;
use Illuminate\Database\Eloquent\Model;
final class PhoneticExam extends Model
{
    protected $table='phonetic_exams';
    protected $guarded=['id'];
    protected function casts():array{return ['shuffle_questions'=>'boolean','shuffle_options'=>'boolean','show_result_immediately'=>'boolean','available_from'=>'datetime','available_until'=>'datetime'];}
    public function questions(){return $this->hasMany(PhoneticExamQuestion::class,'exam_id')->orderBy('sort_order');}
}