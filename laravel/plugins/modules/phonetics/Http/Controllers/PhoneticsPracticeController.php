<?php
declare(strict_types=1);
namespace Plugins\\Modules\\Phonetics\\Http\\Controllers;
use App\\Http\\Controllers\\Controller;
use Illuminate\\Http\\Request;
use Illuminate\\Http\\JsonResponse;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Facades\\Schema;
use Illuminate\\View\\View;
use Plugins\\Modules\\Phonetics\\Models\\PhoneticSetting;
use Plugins\\Modules\\Phonetics\\Models\\PhoneticEntry;
use Plugins\\Modules\\Phonetics\\Models\\PhoneticAttempt;
final class PhoneticsPracticeController extends Controller {
 public function index(Request $r,string $courseSlug):View {
  $s=PhoneticSetting::current();abort_unless($s->enabled,404);abort_if($s->course_scoped_only&&$courseSlug!==$s->course_slug,404);
  if($s->require_course_enrollment)$this->assertCourseAccess($r,$courseSlug);
  $entries=PhoneticEntry::query()->where('is_active',true)->orderBy('sort_order')->orderBy('word')->get();
  $statsQuery=$r->user()?PhoneticAttempt::query()->where('user_id',$r->user()->id):PhoneticAttempt::query()->whereRaw('1=0');
  $challenge=null;
  if($entries->isNotEmpty()){
   $entry=$entries->random();
   $accent=random_int(0,1)===0?'uk':'us';
   $mode=random_int(0,1)===0?'identify-ipa':'identify-accent';
   $expected=$mode==='identify-ipa'?(string)$entry->{$accent.'_ipa'}:$accent;
   if($mode==='identify-ipa'){
    $choices=[['value'=>(string)$entry->uk_ipa,'label'=>'British: '.$entry->uk_ipa],['value'=>(string)$entry->us_ipa,'label'=>'American: '.$entry->us_ipa]];
    $prompt='Choose the IPA symbol for '.($accent==='uk'?'British':'American').' pronunciation of “'.$entry->word.'”.';
   }else{
    $ipa=(string)$entry->{$accent.'_ipa'};
    $choices=[['value'=>'uk','label'=>'British English'],['value'=>'us','label'=>'American English']];
    $prompt='Which accent uses '.$ipa.' for “'.$entry->word.'”?';
   }
   shuffle($choices);
   $challenge=['entry_id'=>$entry->id,'target_accent'=>$accent,'quiz_mode'=>$mode,'expected'=>$expected,'expires_at'=>now()->addMinutes(15)->timestamp,'word'=>$entry->word,'prompt'=>$prompt,'choices'=>$choices];
   $r->session()->put('phonetics.challenge',$challenge);
  }else{$r->session()->forget('phonetics.challenge');}
  return view('phonetics::practice.index',['setting'=>$s,'entries'=>$entries,'challenge'=>$challenge,'stats'=>['attempted'=>(clone $statsQuery)->count(),'correct'=>(clone $statsQuery)->where('is_correct',true)->count()]]);
 }
 public function storeAttempt(Request $r,string $courseSlug):JsonResponse {
  $s=PhoneticSetting::current();abort_unless($s->enabled,404);abort_if($s->course_scoped_only&&$courseSlug!==$s->course_slug,404);abort_unless($r->user(),401);
  if($s->require_course_enrollment)$this->assertCourseAccess($r,$courseSlug);
  $v=$r->validate(['selected_answer'=>'required|string|max:120']);
  $challenge=$r->session()->get('phonetics.challenge');
  abort_unless(is_array($challenge)&&isset($challenge['entry_id'],$challenge['target_accent'],$challenge['quiz_mode'],$challenge['expected'],$challenge['expires_at'])&&(int)$challenge['expires_at']>=now()->timestamp,409,'Your phonetics challenge expired. Reload the practice page.');
  $r->session()->forget('phonetics.challenge');
  $entry=PhoneticEntry::query()->whereKey((int)$challenge['entry_id'])->where('is_active',true)->firstOrFail();
  $selected=trim($v['selected_answer']);$correct=hash_equals((string)$challenge['expected'],$selected);
  $attempt=PhoneticAttempt::query()->create(['user_id'=>$r->user()->id,'entry_id'=>$entry->id,'target_accent'=>$challenge['target_accent'],'quiz_mode'=>$challenge['quiz_mode'],'selected_answer'=>$selected,'is_correct'=>$correct]);
  return response()->json(['saved'=>true,'correct'=>$correct,'attempt_id'=>$attempt->id,'message'=>$correct?'Correct answer.':'Not quite. Review the comparison and try a new challenge.']);
 }
 private function assertCourseAccess(Request $r,string $slug):void {
  $u=$r->user();abort_unless($u,403,'Sign in and enroll to practice.');if($u->hasRole('admin'))return;
  abort_unless(Schema::hasTable('enrollments')&&Schema::hasTable('courses'),503,'Course enrollment is unavailable.');
  $q=DB::table('enrollments')->join('courses','courses.id','=','enrollments.course_id')->where('enrollments.user_id',$u->id)->where('courses.slug',$slug)->whereIn('enrollments.status',['active','completed']);
  if($u->branch_id!==null)$q->where(fn($b)=>$b->whereNull('enrollments.branch_id')->orWhere('enrollments.branch_id',$u->branch_id))->where(fn($b)=>$b->whereNull('courses.branch_id')->orWhere('courses.branch_id',$u->branch_id));
  abort_unless($q->exists(),403,'Phonetics practice is restricted to enrolled students.');
 }
}