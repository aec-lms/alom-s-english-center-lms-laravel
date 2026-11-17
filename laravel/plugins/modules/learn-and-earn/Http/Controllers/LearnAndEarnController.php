<?php
declare(strict_types=1);
namespace Plugins\Modules\LearnAndEarn\Http\Controllers;
use App\Http\Controllers\Controller;
use App\Core\Plugins\PluginManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;
use Plugins\Modules\ExamManagement\Models\Exam;
use Plugins\Modules\ExamManagement\Models\ExamQuestion;
use Plugins\Modules\LearnAndEarn\Models\LearnEarnQuestion;
use Plugins\Modules\UniversalPayment\Models\UniversalPaymentTransaction;
use Plugins\Modules\UniversalPayment\Services\StorefrontPaymentGatewayService;
class LearnAndEarnController extends Controller {
 public function index():View {
  abort_unless(Schema::hasTable('exams')&&Schema::hasTable('learn_earn_questions'),503,'Install Exam Management and Learn & Earn migrations first.');
  return view('learn-and-earn::index',['categories'=>DB::table('learn_earn_categories')->orderBy('name')->get(),'questions'=>LearnEarnQuestion::with('category')->latest()->paginate(50),'exams'=>Exam::withCount('questions')->latest()->paginate(20)]);
 }
 public function storeCategory(Request $r):RedirectResponse {
  $v=$r->validate(['name'=>['required','string','max:120'],'description'=>['nullable','string']]);$base=Str::slug($v['name']);$slug=$base;$i=1;while(DB::table('learn_earn_categories')->where('slug',$slug)->exists())$slug=$base.'-'.(++$i);
  DB::table('learn_earn_categories')->insert(['name'=>$v['name'],'slug'=>$slug,'description'=>$v['description']??null,'is_active'=>true,'created_at'=>now(),'updated_at'=>now()]);return back()->with('status','Category created.');
 }
 public function storeQuestion(Request $r):RedirectResponse {
  $v=$r->validate(['category_id'=>['nullable','integer','exists:learn_earn_categories,id'],'question'=>['required','string'],'options'=>['required','array','size:4'],'options.*'=>['required','string','max:500'],'correct_option_index'=>['required','integer','between:0,3'],'positive_marks'=>['required','numeric','gt:0','max:1000'],'negative_marks'=>['required','numeric','min:0','max:1000']]);
  LearnEarnQuestion::create($v+['is_active'=>true]);return back()->with('status','MCQ saved.');
 }
 public function storeExam(Request $r):RedirectResponse {
  $v=$r->validate(['title'=>['required','string','max:180'],'question_ids'=>['required','array','min:1'],'question_ids.*'=>['required','integer','distinct','exists:learn_earn_questions,id'],'duration_minutes'=>['required','integer','min:5','max:360'],'pass_marks'=>['required','integer','min:0'],'type'=>['required','in:free,paid'],'access_price'=>['required_if:type,paid','numeric','gt:0'],'is_published'=>['nullable','boolean']]);
  DB::transaction(function()use($r,$v){$qs=LearnEarnQuestion::whereIn('id',$v['question_ids'])->where('is_active',true)->get();abort_if($qs->count()!==count($v['question_ids']),422,'Selected question unavailable.');
   $exam=Exam::create(['title'=>$v['title'],'duration_minutes'=>$v['duration_minutes'],'pass_marks'=>$v['pass_marks'],'is_published'=>$r->boolean('is_published'),'learn_earn_type'=>$v['type'],'access_price'=>$v['type']==='paid'?$v['access_price']:0]);
   foreach($qs as $q)ExamQuestion::create(['exam_id'=>$exam->id,'prompt'=>$q->question,'options'=>$q->options,'correct_option_index'=>$q->correct_option_index,'marks'=>$q->positive_marks,'negative_marks'=>$q->negative_marks]);
  });return back()->with('status','Exam created. Paid exams remain locked until verified payment access is granted.');
 }
 public function publicExams(Request $r):View {
  abort_unless($r->user() && $r->user()->hasRole('student'),403,'Student account required.');
  $exams=Exam::where('is_published',true)->latest()->paginate(20);
  $canPurchase = app(PluginManager::class)->isEnabled('universal-payment')
   && app(PluginManager::class)->isEnabled('gateway-sslcommerz')
   && Schema::hasTable('universal_payment_transactions')
   && Schema::hasColumn('universal_payment_transactions', 'learn_earn_exam_id');
  return view('learn-and-earn::exams',['exams'=>$exams,'canPurchase'=>$canPurchase]);
 }
 public function showExam(Request $r,Exam $exam):View {
  abort_unless($r->user() && $r->user()->hasRole('student'),403,'Student account required.');
  abort_unless($exam->is_published,404);
  if($exam->learn_earn_type==='paid') abort_unless(DB::table('learn_earn_exam_access')->where('exam_id',$exam->id)->where('user_id',$r->user()->id)->where('status','granted')->exists(),403,'Verified payment access is required for this exam.');
  $exam->load('questions');
  return view('learn-and-earn::take-exam',['exam'=>$exam]);
 }

 public function purchaseExam(Request $r, Exam $exam, StorefrontPaymentGatewayService $gateway): RedirectResponse {
  abort_unless($r->user() && $r->user()->hasRole('student'),403,'Student account required.');
  abort_unless($exam->is_published && $exam->learn_earn_type==='paid' && (float)$exam->access_price>0,404);
  abort_unless(app(PluginManager::class)->isEnabled('universal-payment')
   && app(PluginManager::class)->isEnabled('gateway-sslcommerz')
   && Schema::hasTable('universal_payment_transactions')
   && Schema::hasColumn('universal_payment_transactions','learn_earn_exam_id'),503,
   'Enable and configure Universal Payment and SSLCOMMERZ before purchasing paid exams.');
  if(DB::table('learn_earn_exam_access')->where('exam_id',$exam->id)->where('user_id',$r->user()->id)->where('status','granted')->exists()) {
   return redirect()->route('plugins.learn_earn.student.show',$exam)->with('status','You already have access to this exam.');
  }
  $v=$r->validate(['payer_contact'=>['required','string','max:120']]);
  abort_unless(filter_var((string)$r->user()->email,FILTER_VALIDATE_EMAIL),422,'A valid account email is required for checkout.');
  $tx=UniversalPaymentTransaction::query()->create([
   'reference'=>'UPAY-'.strtoupper(Str::random(10)),
   'payer_name'=>$r->user()->name,
   'payer_contact'=>$v['payer_contact'],
   'item_title'=>$exam->title,
   'gateway'=>'sslcommerz',
   'currency'=>app(PluginManager::class)->getSetting('universal-payment','default_currency','BDT') ?: 'BDT',
   'amount'=>$exam->access_price,
   'status'=>'pending',
   'user_id'=>$r->user()->id,
   'learn_earn_exam_id'=>$exam->id,
  ]);
  try {
   $checkout=$gateway->initiateSslcommerz($tx,$exam,$r->user(),$v['payer_contact']);
   return redirect()->away($checkout);
  } catch(\\Throwable $e) {
   report($e);
   return back()->withErrors(['payment'=>'Unable to start secure checkout. No exam access was granted.']);
  }
 }
 public function grantAccess(Request $r,Exam $exam):RedirectResponse {
  $v=$r->validate(['user_id'=>['required','integer','exists:users,id'],'payment_reference'=>['nullable','string','max:190']]);abort_unless($exam->learn_earn_type==='paid',422,'This is not a paid exam.');
  DB::table('learn_earn_exam_access')->updateOrInsert(['exam_id'=>$exam->id,'user_id'=>$v['user_id']],['status'=>'granted','payment_reference'=>$v['payment_reference']??null,'granted_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);return back()->with('status','Verified access granted.');
 }
}