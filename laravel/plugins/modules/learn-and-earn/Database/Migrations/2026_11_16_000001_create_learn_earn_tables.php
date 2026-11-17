<?php
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  if(!Schema::hasTable('learn_earn_categories')) Schema::create('learn_earn_categories',function(Blueprint $t){$t->id();$t->string('name',120);$t->string('slug',140)->unique();$t->text('description')->nullable();$t->boolean('is_active')->default(true);$t->timestamps();});
  if(!Schema::hasTable('learn_earn_questions')) Schema::create('learn_earn_questions',function(Blueprint $t){$t->id();$t->foreignId('category_id')->nullable()->constrained('learn_earn_categories')->nullOnDelete();$t->text('question');$t->json('options');$t->unsignedInteger('correct_option_index');$t->decimal('positive_marks',8,2)->default(1);$t->decimal('negative_marks',8,2)->default(0);$t->boolean('is_active')->default(true);$t->timestamps();$t->index(['category_id','is_active']);});
  if(Schema::hasTable('exams')&&!Schema::hasColumn('exams','learn_earn_type'))Schema::table('exams',function(Blueprint $t){$t->string('learn_earn_type',12)->default('free');$t->decimal('access_price',10,2)->default(0);});
  if(Schema::hasTable('exam_questions')&&!Schema::hasColumn('exam_questions','negative_marks'))Schema::table('exam_questions',function(Blueprint $t){$t->decimal('negative_marks',8,2)->default(0);});
  if(!Schema::hasTable('learn_earn_exam_access'))Schema::create('learn_earn_exam_access',function(Blueprint $t){$t->id();$t->unsignedBigInteger('exam_id')->index();$t->foreignId('user_id')->constrained('users')->cascadeOnDelete();$t->string('status',20)->default('granted');$t->string('payment_reference')->nullable();$t->timestamp('granted_at')->nullable();$t->timestamps();$t->unique(['exam_id','user_id']);});
 }
 public function down(): void {}
};