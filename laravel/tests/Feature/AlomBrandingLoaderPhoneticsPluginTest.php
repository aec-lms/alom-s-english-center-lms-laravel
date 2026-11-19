<?php
declare(strict_types=1);
namespace Tests\Feature;
use App\Core\Plugins\PluginManager;use Illuminate\Database\Schema\Blueprint;use Illuminate\Http\UploadedFile;use Illuminate\Support\Facades\DB;use Illuminate\Support\Facades\Schema;
use Plugins\Modules\Phonetics\Models\PhoneticSetting;use Plugins\Modules\Phonetics\Services\CourseCatalog;use Plugins\Modules\Phonetics\Providers\StandalonePhoneticsServiceProvider;use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;use Illuminate\Support\Facades\File;use Plugins\Modules\Phonetics\Models\PhoneticEntry;use Tests\TestCase;
final class AlomBrandingLoaderPhoneticsPluginTest extends TestCase{
 public function test_al_branding_loader_and_phonetics_are_independent_plugins():void{$m=app(PluginManager::class)->discoverManifests();$this->assertArrayHasKey('al-branding-loader',$m);$this->assertArrayHasKey('phonetics',$m);$this->assertSame([],$m['al-branding-loader']->dependencies);$this->assertSame([],$m['phonetics']->dependencies);$this->assertContains('course-management',$m['phonetics']->optionalDependencies);$this->assertNotContains('phonetics',$m['al-branding-loader']->dependencies);$this->assertNotContains('al-branding-loader',$m['phonetics']->dependencies);}
 public function test_admin_routes_are_permission_protected():void{
  $l=file_get_contents(base_path('plugins/modules/al-branding-loader/Routes/web.php'));
  $p=file_get_contents(base_path('plugins/modules/phonetics/Routes/web.php'));
  $this->assertStringContainsString('permission:al-branding-loader.manage',$l);
  $this->assertStringContainsString('permission:phonetics.manage',$p);
  $this->markSystemInstalled();
  $this->activatePlugins(['al-branding-loader','phonetics']);
  $student=$this->createUserWithRole('student');
  $this->actingAs($student)->get('/admin/al-branding-loader')->assertForbidden();
  $this->actingAs($student)->get('/admin/phonetics')->assertForbidden();
  $this->actingAs($student)->patch('/admin/phonetics/settings',[])->assertForbidden();
 }

 public function test_phonetics_attempt_ignores_client_correctness_and_checks_active_enrollment():void{
  $this->markSystemInstalled();
  $this->activatePlugins(['course-management','phonetics']);
  $student=$this->createUserWithRole('student');
  $courseId=DB::table('courses')->insertGetId(['code'=>'PHON-SEC-001','title'=>'British and American Phonetics','slug'=>'british-american-phonetics','description'=>'Practice course','price'=>0,'status'=>'published','created_at'=>now(),'updated_at'=>now()]);
  DB::table('enrollments')->insert(['user_id'=>$student->id,'course_id'=>$courseId,'branch_id'=>null,'status'=>'active','created_at'=>now(),'updated_at'=>now()]);
  $entry=PhoneticEntry::query()->create(['word'=>'car','category'=>'rhoticity','uk_ipa'=>'/kɑː/','us_ipa'=>'/kɑːr/','is_active'=>true,'sort_order'=>1]);
  DB::table('phonetic_learning_assignments')->insert(['lms_driver'=>'aec','course_ref'=>(string)$courseId,'course_category_ref'=>null,'lesson_ref'=>null,'batch_ref'=>null,'word_id'=>$entry->id,'audience'=>'both','sort_order'=>1,'is_required'=>true,'created_at'=>now(),'updated_at'=>now()]);
  $this->actingAs($student)->get('/courses/british-american-phonetics/phonetics-learning')->assertOk();
  $challenge=session('phonetics.learning_challenge');
  $this->assertIsArray($challenge);
  $challengeEntry=PhoneticEntry::query()->findOrFail($challenge['entry_id']);
  $this->actingAs($student)->postJson('/courses/british-american-phonetics/phonetics-learning/attempts',[
   'selected_answer'=>$challenge['expected'],
   'is_correct'=>false,
   'entry_id'=>999999,
   'target_accent'=>'us',
   'quiz_mode'=>'identify-accent',
  ])->assertOk()->assertJson(['saved'=>true,'correct'=>true]);
  $this->assertDatabaseHas('alom_english_center_phonetic_attempts',['user_id'=>$student->id,'entry_id'=>$challengeEntry->id,'is_correct'=>true]);
  // A challenge is single-use: replaying the same session token must not create another attempt.
  $this->actingAs($student)->postJson('/courses/british-american-phonetics/phonetics-learning/attempts',[
   'selected_answer'=>$challenge['expected'],
  ])->assertStatus(409);
  $this->assertSame(1,DB::table('alom_english_center_phonetic_attempts')->where('user_id',$student->id)->count());
  DB::table('enrollments')->where('user_id',$student->id)->delete();
  $this->actingAs($student)->get('/courses/british-american-phonetics/phonetics-learning')->assertForbidden();
 }

 public function test_phonetics_is_available_only_to_enrolled_students_of_the_configured_phonetics_course():void{
  $this->markSystemInstalled();
  $this->activatePlugins(['course-management','phonetics']);
  $student=$this->createUserWithRole('student');
  $phoneticsCourseId=DB::table('courses')->insertGetId(['code'=>'PHON-ONLY-001','title'=>'British and American Phonetics','slug'=>'british-american-phonetics','description'=>'Dedicated phonetics course','price'=>0,'status'=>'published','created_at'=>now(),'updated_at'=>now()]);
  $otherCourseId=DB::table('courses')->insertGetId(['code'=>'GENERAL-001','title'=>'General English','slug'=>'general-english','description'=>'Unrelated course','price'=>0,'status'=>'published','created_at'=>now(),'updated_at'=>now()]);
  DB::table('enrollments')->insert([
   ['user_id'=>$student->id,'course_id'=>$phoneticsCourseId,'branch_id'=>null,'status'=>'active','created_at'=>now(),'updated_at'=>now()],
   ['user_id'=>$student->id,'course_id'=>$otherCourseId,'branch_id'=>null,'status'=>'active','created_at'=>now(),'updated_at'=>now()],
  ]);
  PhoneticSetting::query()->firstOrCreate(['id'=>1],['enabled'=>true,'course_slug'=>'british-american-phonetics','course_title'=>'British & American Phonetics Course','require_course_enrollment'=>false]);
  PhoneticSetting::query()->whereKey(1)->update(['require_course_enrollment'=>false]);
  \Illuminate\Support\Facades\Cache::forget('phonetics.settings');
  $this->actingAs($student)->get('/courses/british-american-phonetics/phonetics-learning')->assertOk();
  $this->actingAs($student)->get('/courses/general-english/phonetics-learning')->assertNotFound();
  $this->actingAs($student)->postJson('/courses/general-english/phonetics-learning/attempts',['selected_answer'=>'/ˈtest/'])->assertNotFound();
 }

 public function test_phonetics_admin_uploads_safe_media_and_rejects_svg():void{
  $this->markSystemInstalled();
  $this->activatePlugins(['phonetics']);
  $admin=$this->createUserWithRole('admin');
  try{
   $this->actingAs($admin)->post('/admin/phonetics',[
    'word'=>'aec-test-water','category'=>'rhoticity','uk_ipa'=>'/ˈwɔːtə/','us_ipa'=>'/ˈwɔːtər/',
    'uk_audio'=>UploadedFile::fake()->create('british.mp3',100,'audio/mpeg'),
    'us_audio'=>UploadedFile::fake()->create('american.mp3',100,'audio/mpeg'),
    'card_bg_image'=>UploadedFile::fake()->image('card.png',120,80),
   ])->assertSessionHas('status');
   $entry=PhoneticEntry::query()->where('word','aec-test-water')->firstOrFail();
   $this->assertNotNull($entry->uk_audio_path,'UK audio path was not saved');
   $this->assertFileExists(public_path($entry->uk_audio_path));
   $this->assertFileExists(public_path($entry->us_audio_path));
   $this->assertFileExists(public_path($entry->card_bg_image_path));
   $this->assertNotNull($entry->uk_audio_url);
   $this->assertDatabaseHas('phonetic_audit_events',['action'=>'phonetics.entry.created','entity_id'=>(string)$entry->id]);
   $oldUkPath=$entry->uk_audio_path;
   $this->actingAs($admin)->put('/admin/phonetics/'.$entry->id,[
    'word'=>'aec-test-water','category'=>'rhoticity','uk_ipa'=>'/ˈwɔːtə/','us_ipa'=>'/ˈwɔːtər/',
    'is_active'=>1,'remove_uk_audio'=>1,
   ])->assertSessionHas('status');
   $entry->refresh();
   $this->assertNull($entry->uk_audio_path);
   $this->assertFileDoesNotExist(public_path($oldUkPath));
   $this->assertNotNull($entry->us_audio_path);
   $this->actingAs($admin)->patch('/admin/phonetics/settings',[
    'enabled'=>1,'require_course_enrollment'=>1,'show_articulatory_notes'=>1,
    'allow_slow_playback'=>0,'course_slug'=>'british-american-phonetics','course_title'=>'British & American Phonetics',
    'theme_profile_id'=>'warm-studio','page_bg_color'=>'#F8FAFC','default_card_bg_color'=>'#FFFFFF',
    'default_text_color'=>'#0F172A','accent_color'=>'#1D4ED8',
   ])->assertSessionHas('status');
   $this->assertDatabaseHas('alom_english_center_phonetic_settings',['theme_profile_id'=>'warm-studio','allow_slow_playback'=>false,'page_bg_color'=>'#FFF7ED']);
   $this->actingAs($admin)->from('/admin/phonetics')->post('/admin/phonetics',[
    'word'=>'unsafe','category'=>'rhoticity','uk_ipa'=>'/x/','us_ipa'=>'/x/',
    'card_bg_image'=>UploadedFile::fake()->create('unsafe.svg',20,'image/svg+xml'),
   ])->assertSessionHasErrors('card_bg_image');
  }finally{
   File::deleteDirectory(public_path('uploads/alom-english-center/phonetics'));
  }
 }


 public function test_loader_admin_settings_save_audit_and_core_branding_link():void{
  $this->markSystemInstalled();
  $this->activatePlugins(['al-branding-loader']);
  $admin=$this->createUserWithRole('admin');
  $this->actingAs($admin)->get('/admin/al-branding-loader')->assertOk()->assertSee('Open Branding Settings');
  $this->actingAs($admin)->put('/admin/al-branding-loader',[
   'enabled'=>1,'headline'=>"Alom's English Center",'subtext'=>'Preparing your classroom',
   'animation_style'=>'shimmer-sweep','background_color'=>'#112233','accent_color'=>'#445566',
   'text_color'=>'#FFFFFF','backdrop_blur'=>1,'show_progress_label'=>1,'respect_reduced_motion'=>1,
   'min_duration_ms'=>500,'fade_duration_ms'=>250,'max_timeout_ms'=>5000,
   'trigger_mode'=>'every-load','excluded_paths_text'=>"admin/*
api/*",
  ])->assertRedirect('/admin/al-branding-loader')->assertSessionHas('status');
  $this->assertDatabaseHas('alom_english_center_loader_settings',['animation_style'=>'shimmer-sweep','background_color'=>'#112233','enabled'=>true]);
  $this->assertDatabaseHas('audit_logs',['action'=>'al_branding_loader.settings.updated','entity_id'=>'al-branding-loader']);
 }


 public function test_plugins_enable_disable_and_reinstall_independently_while_preserving_phonetics_data():void{
  $this->markSystemInstalled();
  $this->activatePlugins(['al-branding-loader','phonetics']);
  $manager=app(PluginManager::class);
  $entry=PhoneticEntry::query()->create(['word'=>'ship','category'=>'rhoticity','uk_ipa'=>'/ʃɪp/','us_ipa'=>'/ʃɪp/','is_active'=>true,'sort_order'=>10]);
  $manager->disable('al-branding-loader');
  $this->assertTrue($manager->isEnabled('phonetics'));
  $manager->enable('al-branding-loader');
  $manager->disable('phonetics');
  $this->assertTrue($manager->isEnabled('al-branding-loader'));
  $manager->uninstall('phonetics',false);
  $this->assertDatabaseHas('alom_english_center_phonetic_entries',['id'=>$entry->id,'word'=>'ship']);
  $manager->install('phonetics');
  $this->assertTrue($manager->isEnabled('phonetics'));
  $this->assertDatabaseHas('alom_english_center_phonetic_entries',['id'=>$entry->id,'word'=>'ship']);
 }


 public function test_admin_can_assign_words_by_course_lesson_and_create_server_graded_exams():void{
  $this->markSystemInstalled();
  $this->activatePlugins(['course-management','phonetics']);
  $admin=$this->createUserWithRole('admin');
  $student=$this->createUserWithRole('student');
  $categoryId=DB::table('course_categories')->insertGetId(['name'=>'General English','slug'=>'general-english','description'=>null,'color'=>'blue','is_active'=>true,'created_at'=>now(),'updated_at'=>now()]);
  $courseId=DB::table('courses')->insertGetId(['code'=>'VOCAB-001','branch_id'=>null,'category_id'=>$categoryId,'title'=>'Vocabulary Starter','category'=>'General English','slug'=>'vocabulary-starter','description'=>'Course for vocabulary tests','price'=>0,'status'=>'published','created_at'=>now(),'updated_at'=>now()]);
  $lessonId=DB::table('course_lessons')->insertGetId(['course_id'=>$courseId,'title'=>'Lesson One','content'=>null,'sort_order'=>1,'created_at'=>now(),'updated_at'=>now()]);
  DB::table('enrollments')->insert(['user_id'=>$student->id,'course_id'=>$courseId,'branch_id'=>null,'status'=>'active','created_at'=>now(),'updated_at'=>now()]);
  $word=PhoneticEntry::query()->create(['word'=>'aec-vocabulary-test','category'=>'rhoticity','uk_ipa'=>'/ˈtest/','us_ipa'=>'/ˈtɛst/','is_active'=>true,'sort_order'=>500]);
  $this->actingAs($admin)->post('/admin/phonetics/learning/assignments',[
   'course_ref'=>(string)$courseId,'course_category_ref'=>(string)$categoryId,'lesson_ref'=>(string)$lessonId,
   'word_id'=>$word->id,'audience'=>'free','is_required'=>1,
  ])->assertSessionHas('status');
  $this->assertDatabaseHas('phonetic_learning_assignments',['course_ref'=>(string)$courseId,'lesson_ref'=>(string)$lessonId,'word_id'=>$word->id,'audience'=>'free']);
  $this->actingAs($admin)->post('/admin/phonetics/learning/exams',[
   'title'=>'Lesson One Vocabulary Exam','description'=>'Test the assigned words','course_ref'=>(string)$courseId,
   'course_category_ref'=>(string)$categoryId,'lesson_ref'=>(string)$lessonId,'audience'=>'free',
   'duration_minutes'=>10,'pass_percentage'=>60,'max_attempts'=>2,'question_count'=>1,'question_type'=>'identify-ipa',
   'word_ids'=>[$word->id],'shuffle_questions'=>1,'shuffle_options'=>1,'show_result_immediately'=>1,'status'=>'published',
  ])->assertSessionHas('status');
  $exam=DB::table('phonetic_exams')->where('slug','lesson-one-vocabulary-exam')->first();
  $this->assertNotNull($exam);
  $this->assertDatabaseHas('phonetic_exam_questions',['exam_id'=>$exam->id,'word_id'=>$word->id]);
  PhoneticSetting::query()->firstOrCreate(['id'=>1],['enabled'=>true,'course_slug'=>'vocabulary-starter','course_title'=>'Vocabulary Starter']);
  PhoneticSetting::query()->whereKey(1)->update(['course_slug'=>'vocabulary-starter']);
  \Illuminate\Support\Facades\Cache::forget('phonetics.settings');
  $this->actingAs($student)->get('/courses/vocabulary-starter/phonetics-learning?lesson='.$lessonId)->assertOk()->assertSee('aec-vocabulary-test')->assertSee('Lesson One Vocabulary Exam');
  $this->actingAs($student)->get('/courses/vocabulary-starter/phonetics-learning/exams/lesson-one-vocabulary-exam?lesson='.$lessonId)->assertOk();
  $attempt=DB::table('phonetic_exam_attempts')->where('exam_id',$exam->id)->where('user_id',$student->id)->first();
  $question=DB::table('phonetic_exam_questions')->where('exam_id',$exam->id)->first();
  $this->actingAs($student)->post('/courses/vocabulary-starter/phonetics-learning/exams/lesson-one-vocabulary-exam/attempts/'.$attempt->id.'?lesson='.$lessonId,[
   'answers'=>[$question->id=>$question->answer_key],
  ])->assertOk()->assertSee('Congratulations');
  $this->assertDatabaseHas('phonetic_exam_attempts',['id'=>$attempt->id,'status'=>'submitted','passed'=>true]);
 }


 public function test_phonetics_user_reference_columns_are_portable_and_have_no_host_user_foreign_key():void{
  $this->markSystemInstalled();
  $this->activatePlugins(['phonetics']);
  $this->assertContains(Schema::getColumnType('alom_english_center_phonetic_attempts','user_id'),['string','varchar']);
  $this->assertContains(Schema::getColumnType('phonetic_exam_attempts','user_id'),['string','varchar']);
  $this->assertContains(Schema::getColumnType('phonetic_batch_memberships','user_id'),['string','varchar']);
  $foreignKeys=collect(Schema::getForeignKeys('phonetic_exam_attempts'));
  $this->assertFalse($foreignKeys->contains(fn($foreignKey)=>in_array('user_id',$foreignKey['columns']??[],true)));
 }

 public function test_batch_roster_uses_configured_uuid_host_user_table():void{
  $this->markSystemInstalled();
  $this->activatePlugins(['phonetics']);
  Schema::create('phonetics_uuid_users',function(Blueprint $table):void{$table->string('uuid',36)->primary();$table->string('full_name');$table->string('email_address');});
  Schema::create('phonetics_test_batches',function(Blueprint $table):void{$table->string('batch_key',40)->primary();$table->string('batch_name');});
  try{
   $uuid='7e8f9a10-1112-4314-8516-171819202122';
   DB::table('phonetics_uuid_users')->insert(['uuid'=>$uuid,'full_name'=>'UUID Student','email_address'=>'uuid@example.test']);
   DB::table('phonetics_test_batches')->insert(['batch_key'=>'batch-uuid-1','batch_name'=>'UUID Batch']);
   config([
    'phonetics.users'=>['table'=>'phonetics_uuid_users','id'=>'uuid','name'=>'full_name','email'=>'email_address'],
    'phonetics.catalog.batches'=>['table'=>'phonetics_test_batches','id'=>'batch_key','title'=>'batch_name'],
   ]);
   $admin=$this->createUserWithRole('admin');
   $this->actingAs($admin)->post('/admin/phonetics/learning/batch-members',[
    'batch_ref'=>'batch-uuid-1','user_id'=>$uuid,
   ])->assertSessionHas('status');
   $this->assertDatabaseHas('phonetic_batch_memberships',['lms_driver'=>'aec','batch_ref'=>'batch-uuid-1','user_id'=>$uuid,'is_active'=>true]);
  }finally{
   Schema::dropIfExists('phonetics_test_batches');
   Schema::dropIfExists('phonetics_uuid_users');
  }
 }

 public function test_free_paid_classification_fails_closed_without_a_price_mapping():void{
  Schema::create('phonetics_price_guard_courses',function(Blueprint $table):void{$table->id();});
  try{
   config(['phonetics.catalog.courses'=>['table'=>'phonetics_price_guard_courses','id'=>'id','title'=>'title']]);
   $this->expectException(ServiceUnavailableHttpException::class);
   app(CourseCatalog::class)->isPaid((object)['id'=>1]);
  }finally{
   Schema::dropIfExists('phonetics_price_guard_courses');
  }
 }

 public function test_exam_creation_rejects_words_without_a_uk_us_pronunciation_difference():void{
  $this->markSystemInstalled();
  $this->activatePlugins(['course-management','phonetics']);
  $admin=$this->createUserWithRole('admin');
  $courseId=DB::table('courses')->insertGetId(['code'=>'PHON-SAME-001','title'=>'Same IPA Course','slug'=>'same-ipa-course','description'=>'Test course','price'=>0,'status'=>'published','created_at'=>now(),'updated_at'=>now()]);
  $word=PhoneticEntry::query()->create(['word'=>'ship-same-ipa','category'=>'test','uk_ipa'=>'/ʃɪp/','us_ipa'=>'/ʃɪp/','is_active'=>true,'sort_order'=>900]);
  $this->actingAs($admin)->postJson('/admin/phonetics/learning/exams',[
   'title'=>'Ambiguous IPA Exam','course_ref'=>(string)$courseId,'audience'=>'both',
   'duration_minutes'=>10,'pass_percentage'=>60,'max_attempts'=>1,'question_count'=>1,
   'question_type'=>'identify-ipa','word_ids'=>[$word->id],'status'=>'draft',
  ])->assertStatus(422);
  $this->assertDatabaseMissing('phonetic_exams',['title'=>'Ambiguous IPA Exam']);
 }


 public function test_course_access_fails_closed_without_a_publication_status_mapping():void{
  Schema::create('phonetics_course_status_guard',function(Blueprint $table):void{$table->string('id',20)->primary();$table->decimal('price',10,2)->default(0);});
  try{
   config(['phonetics.catalog.courses'=>['table'=>'phonetics_course_status_guard','id'=>'id','title'=>'title','price'=>'price']]);
   $this->expectException(ServiceUnavailableHttpException::class);
   app(CourseCatalog::class)->assertCourseAvailable((object)['id'=>'course-1','price'=>0]);
  }finally{
   Schema::dropIfExists('phonetics_course_status_guard');
  }
 }

 public function test_enrollment_mapping_without_an_active_status_column_fails_closed():void{
  Schema::create('phonetics_guard_courses',function(Blueprint $table):void{$table->string('id',20)->primary();$table->decimal('price',10,2)->default(0);$table->string('status',20)->default('published');});
  Schema::create('phonetics_guard_enrollments',function(Blueprint $table):void{$table->id();$table->string('user_id',128);$table->string('course_id',20);});
  try{
   $student=$this->createUserWithRole('student');
   DB::table('phonetics_guard_courses')->insert(['id'=>'course-1','price'=>0,'status'=>'published']);
   DB::table('phonetics_guard_enrollments')->insert(['user_id'=>(string)$student->getAuthIdentifier(),'course_id'=>'course-1']);
   config([
    'phonetics.catalog.courses'=>['table'=>'phonetics_guard_courses','id'=>'id','title'=>'title','price'=>'price','status'=>'status'],
    'phonetics.catalog.enrollments'=>['table'=>'phonetics_guard_enrollments','user_id'=>'user_id','course_id'=>'course_id','status'=>'status','active_statuses'=>['active']],
   ]);
   $this->expectException(ServiceUnavailableHttpException::class);
   app(CourseCatalog::class)->assertAccess($student,'course-1');
  }finally{
   Schema::dropIfExists('phonetics_guard_enrollments');
   Schema::dropIfExists('phonetics_guard_courses');
  }
 }


 public function test_branchless_student_cannot_access_branch_scoped_phonetics_course():void{
  Schema::create('phonetics_branch_guard_courses',function(Blueprint $table):void{$table->string('id',20)->primary();$table->decimal('price',10,2)->default(0);$table->string('status',20)->default('published');$table->unsignedBigInteger('branch_id')->nullable();});
  Schema::create('phonetics_branch_guard_enrollments',function(Blueprint $table):void{$table->id();$table->string('user_id',128);$table->string('course_id',20);$table->unsignedBigInteger('branch_id')->nullable();$table->string('status',20);});
  try{
   $student=$this->createUserWithRole('student');
   DB::table('phonetics_branch_guard_courses')->insert(['id'=>'branch-course','price'=>0,'status'=>'published','branch_id'=>2]);
   DB::table('phonetics_branch_guard_enrollments')->insert(['user_id'=>(string)$student->getAuthIdentifier(),'course_id'=>'branch-course','branch_id'=>2,'status'=>'active']);
   config([
    'phonetics.catalog.courses'=>['table'=>'phonetics_branch_guard_courses','id'=>'id','price'=>'price','status'=>'status','branch_id'=>'branch_id'],
    'phonetics.catalog.enrollments'=>['table'=>'phonetics_branch_guard_enrollments','user_id'=>'user_id','course_id'=>'course_id','status'=>'status','branch_id'=>'branch_id','active_statuses'=>['active']],
   ]);
   $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
   app(CourseCatalog::class)->assertAccess($student,'branch-course');
  }finally{
   Schema::dropIfExists('phonetics_branch_guard_enrollments');
   Schema::dropIfExists('phonetics_branch_guard_courses');
  }
 }


 public function test_branchless_student_cannot_access_branch_scoped_batch_vocabulary():void{
  Schema::create('phonetics_branch_batches',function(Blueprint $table):void{$table->unsignedBigInteger('id')->primary();$table->string('name');$table->unsignedBigInteger('branch_id')->nullable();});
  Schema::create('phonetics_branch_memberships',function(Blueprint $table):void{$table->id();$table->string('user_id',128);$table->string('batch_ref',128);$table->string('lms_driver',40);$table->boolean('is_active')->default(true);});
  try{
   $student=$this->createUserWithRole('student');
   DB::table('phonetics_branch_batches')->insert(['id'=>77,'name'=>'Branch Batch','branch_id'=>2]);
   DB::table('phonetics_branch_memberships')->insert(['user_id'=>(string)$student->getAuthIdentifier(),'batch_ref'=>'77','lms_driver'=>'aec','is_active'=>true]);
   config([
    'phonetics.catalog.batches'=>['table'=>'phonetics_branch_batches','id'=>'id','title'=>'name','branch_id'=>'branch_id'],
    'phonetics.catalog.batch_memberships'=>['table'=>'phonetics_branch_memberships','user_id'=>'user_id','batch_id'=>'batch_ref','driver_column'=>'lms_driver','active_column'=>'is_active','active_value'=>1],
   ]);
   $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
   app(CourseCatalog::class)->isBatchMember($student,'77');
  }finally{
   Schema::dropIfExists('phonetics_branch_memberships');
   Schema::dropIfExists('phonetics_branch_batches');
  }
 }


 public function test_standalone_provider_uses_generic_middleware_and_preserves_host_overrides():void{
  app(PluginManager::class)->discoverManifests();
  config([
   'phonetics.standalone'=>true,
   'phonetics.middleware'=>['practice'=>['web','verified']],
  ]);
  $provider=new StandalonePhoneticsServiceProvider(app());
  $provider->register();
  $this->assertSame(['web','verified'],config('phonetics.middleware.practice'));
  $this->assertSame(['web','auth'],config('phonetics.middleware.attempt'));
  $this->assertSame(['web','auth'],config('phonetics.middleware.exam'));
  $this->assertSame(['web','auth','can:manage-phonetics'],config('phonetics.middleware.admin'));
 }


 public function test_learning_admin_fails_closed_when_host_user_display_columns_are_missing():void{
  $this->markSystemInstalled();
  $this->activatePlugins(['phonetics']);
  Schema::create('phonetics_incomplete_users',function(Blueprint $table):void{$table->string('uuid',36)->primary();$table->string('full_name');});
  try{
   config(['phonetics.users'=>['table'=>'phonetics_incomplete_users','id'=>'uuid','name'=>'full_name','email'=>'email_address']]);
   $admin=$this->createUserWithRole('admin');
   $this->actingAs($admin)->get('/admin/phonetics/learning')->assertStatus(503);
  }finally{
   Schema::dropIfExists('phonetics_incomplete_users');
  }
 }

}