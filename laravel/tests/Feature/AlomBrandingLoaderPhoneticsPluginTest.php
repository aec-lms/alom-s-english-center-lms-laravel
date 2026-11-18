<?php
declare(strict_types=1);
namespace Tests\Feature;
use App\Core\Plugins\PluginManager;use Illuminate\Support\Facades\DB;use Plugins\Modules\Phonetics\Models\PhoneticEntry;use Tests\TestCase;
final class AlomBrandingLoaderPhoneticsPluginTest extends TestCase{
 public function test_al_branding_loader_and_phonetics_are_independent_plugins():void{$m=app(PluginManager::class)->discoverManifests();$this->assertArrayHasKey('al-branding-loader',$m);$this->assertArrayHasKey('phonetics',$m);$this->assertSame([],$m['al-branding-loader']->dependencies);$this->assertSame([],$m['phonetics']->dependencies);$this->assertContains('course-management',$m['phonetics']->optionalDependencies);$this->assertNotContains('phonetics',$m['al-branding-loader']->dependencies);$this->assertNotContains('al-branding-loader',$m['phonetics']->dependencies);}
 public function test_admin_routes_are_permission_protected():void{$l=file_get_contents(base_path('plugins/modules/al-branding-loader/Routes/web.php'));$p=file_get_contents(base_path('plugins/modules/phonetics/Routes/web.php'));$this->assertStringContainsString('permission:al-branding-loader.manage',$l);$this->assertStringContainsString('permission:phonetics.manage',$p);}

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
  $this->assertSame($entry->id,$challenge['entry_id']);
  $this->actingAs($student)->postJson('/courses/british-american-phonetics/phonetics-practice/attempts',[
   'selected_answer'=>$challenge['expected'],
   'is_correct'=>false,
   'entry_id'=>999999,
   'target_accent'=>'us',
   'quiz_mode'=>'identify-accent',
  ])->assertOk()->assertJson(['saved'=>true,'correct'=>true]);
  $this->assertDatabaseHas('alom_english_center_phonetic_attempts',['user_id'=>$student->id,'entry_id'=>$entry->id,'is_correct'=>true]);
 }
}