<?php
declare(strict_types=1);
namespace Plugins\Modules\Phonetics\Models;
use Illuminate\Database\Eloquent\Model;
final class PhoneticAuditEvent extends Model
{
    protected $table='phonetic_audit_events';
    protected $guarded=['id'];
    protected function casts():array{return ['metadata'=>'array'];}
}