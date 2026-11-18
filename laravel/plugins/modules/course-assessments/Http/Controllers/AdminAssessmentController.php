<?php

declare(strict_types=1);

namespace Plugins\Modules\CourseAssessments\Http\Controllers;

use App\Core\Plugins\PluginManager;
use App\Core\Security\AuditLogger;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Plugins\Modules\CourseAssessments\Models\AssessmentSet;

class AdminAssessmentController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): View
    {
        $actor = $request->user();
        $sets = AssessmentSet::query()
            ->withCount(['questions','attempts'])
            ->when($actor?->hasRole('branch_manager'), fn ($q) => $q->where('branch_id', (int) $actor->branch_id))
            ->latest()->paginate(25);
        $courses = collect();
        if (app(PluginManager::class)->isEnabled('course-management') && Schema::hasTable('courses')) {
            $courses = DB::table('courses')
                ->when($actor?->hasRole('branch_manager'), fn ($q) => $q->where('branch_id', (int) $actor->branch_id))
                ->whereNull('deleted_at')->orderBy('title')->get(['id','title','branch_id']);
        }
        $bankQuestions = collect();
        if (app(PluginManager::class)->isEnabled('question-bank') && Schema::hasTable('question_bank_questions')) {
            $bankQuestions = DB::table('question_bank_questions')
                ->whereNull('deleted_at')->where('is_active', true)
                ->when($actor?->hasRole('branch_manager'), fn ($q) => $q->where('branch_id', (int) $actor->branch_id))
                ->orderBy('subject')->limit(250)->get(['id','subject','topic','question_type','prompt','options','correct_answer','explanation','difficulty','default_marks','default_negative_marks','branch_id']);
        }
        return view('course-assessments::admin-index', compact('sets','courses','bankQuestions'));
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $request->user();
        $data = $request->validate([
            'course_id'=>['nullable','integer'],
            'title'=>['required','string','max:180'],
            'description'=>['nullable','string','max:10000'],
            'status'=>['required','in:draft,published'],
            'time_limit_minutes'=>['nullable','integer','min:1','max:600'],
            'max_attempts'=>['nullable','integer','min:1','max:50'],
            'questions_per_attempt'=>['nullable','integer','min:1','max:200'],
            'randomize_questions'=>['sometimes','boolean'],
            'available_from'=>['nullable','date'],
            'available_until'=>['nullable','date','after_or_equal:available_from'],
            'source_question_ids'=>['nullable','array','max:200'],
            'source_question_ids.*'=>['integer','distinct'],
            'questions'=>['nullable','array','max:200'],
            'questions.*.type'=>['required','in:mcq,true_false,short_answer,written'],
            'questions.*.question'=>['required','string','max:20000'],
            'questions.*.options'=>['nullable','array','min:2','max:8'],
            'questions.*.options.*'=>['required','string','max:1000','distinct'],
            'questions.*.correct_option'=>['nullable','integer','min:0','max:7'],
            'questions.*.correct_answer'=>['nullable','string','max:10000'],
            'questions.*.explanation'=>['nullable','string','max:10000'],
            'questions.*.marks'=>['required','numeric','gt:0','max:1000'],
            'questions.*.difficulty'=>['nullable','in:easy,medium,hard'],
        ]);
        $course = null;
        if (!empty($data['course_id'])) {
            abort_unless(app(PluginManager::class)->isEnabled('course-management') && Schema::hasTable('courses'), 409, 'Enable Course Management to attach an assessment to a course.');
            $course = DB::table('courses')->where('id',$data['course_id'])->whereNull('deleted_at')->first();
            abort_unless($course,422,'Selected course is unavailable.');
        }
        if ($actor?->hasRole('branch_manager')) {
            abort_unless($actor->branch_id && app(PluginManager::class)->isEnabled('branch-management'),403,'Branch manager must be assigned to an active branch.');
            if ($course) abort_unless((int)$course->branch_id === (int)$actor->branch_id,403,'Course belongs to another branch.');
        }
        $source = collect();
        if (!empty($data['source_question_ids'])) {
            abort_unless(app(PluginManager::class)->isEnabled('question-bank') && Schema::hasTable('question_bank_questions'),409,'Enable Question Bank to import questions.');
            $source = DB::table('question_bank_questions')->whereIn('id',$data['source_question_ids'])->whereNull('deleted_at')->where('is_active',true)
                ->when($actor?->hasRole('branch_manager'),fn($q)=>$q->where('branch_id',(int)$actor->branch_id))->get();
            abort_unless($source->count() === count($data['source_question_ids']),422,'One or more source questions are unavailable in your branch.');
        }
        $manual = collect($data['questions'] ?? []);
        abort_if($source->isEmpty() && $manual->isEmpty(),422,'Add at least one question or import from Question Bank.');
        foreach ($manual as $i => $q) {
            if (in_array($q['type'],['mcq','true_false'],true)) {
                abort_unless(count($q['options'] ?? []) >= 2 && isset($q['correct_option']) && $q['correct_option'] < count($q['options']),422,"Question ".($i+1)." requires options and a valid correct answer.");
            }
            if (in_array($q['type'],['short_answer','written'],true)) abort_unless(trim((string)($q['correct_answer'] ?? '')) !== '',422,"Question ".($i+1)." requires a reference answer.");
        }
        $set = DB::transaction(function () use ($data,$actor,$course,$source,$manual): AssessmentSet {
            $set = AssessmentSet::query()->create([
                'course_id'=>$course?->id,
                'branch_id'=>$actor?->hasRole('branch_manager') ? (int)$actor->branch_id : ($course->branch_id ?? null),
                'title'=>$data['title'],'description'=>$data['description']??null,'status'=>$data['status'],
                'time_limit_minutes'=>$data['time_limit_minutes']??null,'max_attempts'=>$data['max_attempts']??null,
                'questions_per_attempt'=>$data['questions_per_attempt']??null,'randomize_questions'=>(bool)($data['randomize_questions']??false),
                'available_from'=>$data['available_from']??null,'available_until'=>$data['available_until']??null,'created_by'=>$actor?->id,
            ]);
            $position=10;
            foreach($source as $q){
                $options=is_string($q->options)?json_decode($q->options,true):$q->options;
                $type=$q->question_type==='mcq'?'mcq':'short_answer';
                $correctOption=null;
                if($type==='mcq'){
                    $correctOption=collect($options??[])->search(fn($v)=>trim((string)$v)===trim((string)$q->correct_answer));
                    abort_if($correctOption===false,422,"Question Bank MCQ {$q->id} has no matching correct option text.");
                }
                $set->questions()->create([
                    'source_question_id'=>$q->id,'type'=>$type,'question'=>$q->prompt,'options'=>$options,
                    'correct_option'=>$correctOption,'correct_answer'=>$q->correct_answer,'explanation'=>$q->explanation,
                    'difficulty'=>$q->difficulty,'tags'=>[],'marks'=>$q->default_marks,'position'=>$position,
                ]);
                $position+=10;
            }
            foreach($manual as $q){
                $set->questions()->create([
                    'type'=>$q['type'],'question'=>$q['question'],'options'=>$q['options']??null,
                    'correct_option'=>$q['correct_option']??null,'correct_answer'=>$q['correct_answer']??null,
                    'explanation'=>$q['explanation']??null,'difficulty'=>$q['difficulty']??'medium',
                    'marks'=>$q['marks'],'position'=>$position,
                ]);
                $position+=10;
            }
            return $set;
        });
        $this->audit->log('course_assessment.created','course_assessment_set',(string)$set->id,['questions'=>$set->questions()->count(),'course_id'=>$set->course_id]);
        return back()->with('status','Assessment set created with immutable source snapshots.');
    }

    public function publish(Request $request, AssessmentSet $set): RedirectResponse
    {
        $this->ensureSetBranch($request->user(),$set);
        abort_unless($set->questions()->exists(),422,'Add questions before publishing.');
        $set->update(['status'=>'published']);
        $this->audit->log('course_assessment.published','course_assessment_set',(string)$set->id,[]);
        return back()->with('status','Assessment published.');
    }

    private function ensureSetBranch($actor,AssessmentSet $set): void
    {
        if($actor?->hasRole('branch_manager')) abort_unless($actor->branch_id && (int)$set->branch_id===(int)$actor->branch_id,403,'Branch isolation violation.');
    }
}
