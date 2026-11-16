<?php
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {public function up():void{
 if(!Schema::hasTable('flash_sale_settings'))Schema::create('flash_sale_settings',function(Blueprint $t){$t->id();$t->boolean('page_enabled')->default(true);$t->string('page_title',160)->default('Flash Sale');$t->timestamps();});
 if(!Schema::hasTable('flash_sales'))Schema::create('flash_sales',function(Blueprint $t){$t->id();$t->foreignId('course_id')->constrained('courses')->cascadeOnDelete();$t->string('title',160);$t->string('discount_type',12);$t->decimal('discount_value',10,2);$t->timestamp('starts_at');$t->timestamp('ends_at');$t->boolean('is_published')->default(false);$t->boolean('is_active')->default(true);$t->timestamps();$t->index(['is_published','is_active','starts_at','ends_at']);});
 }public function down():void{}};