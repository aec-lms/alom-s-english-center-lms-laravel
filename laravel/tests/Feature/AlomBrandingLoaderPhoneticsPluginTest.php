<?php
declare(strict_types=1);
namespace Tests\Feature;
use App\Core\Plugins\PluginManager;use Illuminate\Http\UploadedFile;use Illuminate\Support\Facades\DB;
use Plugins\Modules\Phonetics\Models\PhoneticSetting;use Illuminate\Support\Facades\File;use Plugins\Modules\Phonetics\Models\PhoneticEntry;use Tests\TestCase;
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
  $this->actingAs($student)->get('/courses/british-american-phonetics/phonetics-practice')->assertOk();
  $challenge=session('phonetics.challenge');
  $this->assertIsArray($challenge);
  $challengeEntry=PhoneticEntry::query()->findOrFail($challenge['entry_id']);
  $this->actingAs($student)->postJson('/courses/british-american-phonetics/phonetics-practice/attempts',[
   'selected_answer'=>$challenge['expected'],
   'is_correct'=>false,
   'entry_id'=>999999,
   'target_accent'=>'us',
   'quiz_mode'=>'identify-accent',
  ])->assertOk()->assertJson(['saved'=>true,'correct'=>true]);
  $this->assertDatabaseHas('alom_english_center_phonetic_attempts',['user_id'=>$student->id,'entry_id'=>$challengeEntry->id,'is_correct'=>true]);
  // A challenge is single-use: replaying the same session token must not create another attempt.
  $this->actingAs($student)->postJson('/courses/british-american-phonetics/phonetics-practice/attempts',[
   'selected_answer'=>$challenge['expected'],
  ])->assertStatus(409);
  $this->assertSame(1,DB::table('alom_english_center_phonetic_attempts')->where('user_id',$student->id)->count());
  DB::table('enrollments')->where('user_id',$student->id)->delete();
  $this->actingAs($student)->get('/courses/british-american-phonetics/phonetics-practice')->assertForbidden();
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
   $this->assertDatabaseHas('audit_logs',['action'=>'phonetics.entry.created','entity_id'=>(string)$entry->id]);
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
   'trigger_mode'=>'every-load','excluded_paths_text'=>"admin/*\napi/*",
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

}