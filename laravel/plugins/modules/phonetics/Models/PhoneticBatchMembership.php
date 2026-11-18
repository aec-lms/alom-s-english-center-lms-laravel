<?php
declare(strict_types=1);
namespace Plugins\Modules\Phonetics\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
final class PhoneticBatchMembership extends Model
{
    protected $table='phonetic_batch_memberships';
    protected $guarded=['id'];
    protected function casts():array{return ['is_active'=>'boolean'];}
    public function user(): BelongsTo
    {
        $model = config('phonetics.users.model') ?: config('auth.providers.users.model');
        abort_unless(is_string($model) && class_exists($model), 503, 'Configure the host LMS user model for the Phonetics batch roster.');
        return $this->belongsTo($model, 'user_id', config('phonetics.users.id', 'id'));
    }
}