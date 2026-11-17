<?php
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  if(Schema::hasTable('exam_questions')) Schema::table('exam_questions',function(Blueprint $t){$t->decimal('marks',8,2)->default(1)->change();if(!Schema::hasColumn('exam_questions','negative_marks'))$t->decimal('negative_marks',8,2)->default(0);});
  if(Schema::hasTable('exam_attempts')) Schema::table('exam_attempts',function(Blueprint $t){$t->decimal('score_obtained',10,2)->default(0)->change();$t->decimal('total_marks',10,2)->default(0)->change();});
 }
 public function down():void {}
};