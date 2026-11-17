<?php
declare(strict_types=1);
namespace Plugins\Modules\LearnAndEarn\Models;
use Illuminate\Database\Eloquent\Model;
class LearnEarnCategory extends Model {protected $table='learn_earn_categories';protected $fillable=['name','slug','description','is_active'];}