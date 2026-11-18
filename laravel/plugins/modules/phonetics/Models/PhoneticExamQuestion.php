<?php
declare(strict_types=1);
namespace Plugins\\Modules\\Phonetics\\Models;
use Illuminate\\Database\\Eloquent\\Model;
final class PhoneticExamQuestion extends Model
{
    protected $table='phonetic_exam_questions';
    protected $guarded=['id'];
    protected function casts():array{return ['options'=>'array','marks'=>'integer','sort_order'=>'integer'];}
}