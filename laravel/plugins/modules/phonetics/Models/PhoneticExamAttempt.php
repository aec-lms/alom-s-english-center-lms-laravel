<?php
declare(strict_types=1);
namespace Plugins\\Modules\\Phonetics\\Models;
use Illuminate\\Database\\Eloquent\\Model;
final class PhoneticExamAttempt extends Model
{
    protected $table='phonetic_exam_attempts';
    protected $guarded=['id'];
    protected function casts():array{return ['question_snapshot'=>'array','answers'=>'array','passed'=>'boolean','started_at'=>'datetime','expires_at'=>'datetime','submitted_at'=>'datetime','percentage'=>'decimal:2'];}
}