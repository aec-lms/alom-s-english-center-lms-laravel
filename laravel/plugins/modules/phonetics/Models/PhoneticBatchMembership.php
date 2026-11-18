<?php
declare(strict_types=1);
namespace Plugins\Modules\Phonetics\Models;
use Illuminate\Database\Eloquent\Model;
final class PhoneticBatchMembership extends Model
{
    protected $table='phonetic_batch_memberships';
    protected $guarded=['id'];
    protected function casts():array{return ['is_active'=>'boolean'];}
    public function user(){ $model = config('auth.providers.users.model'); return $model && class_exists($model) ? $this->belongsTo($model, 'user_id') : null; }
}