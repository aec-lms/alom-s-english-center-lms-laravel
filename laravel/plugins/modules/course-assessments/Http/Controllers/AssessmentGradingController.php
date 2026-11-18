<?php

declare(strict_types=1);

namespace Plugins\Modules\CourseAssessments\Http\Controllers;

use App\Core\Security\AuditLogger;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Plugins\Modules\CourseAssessments\Models\AssessmentAttempt;

class AssessmentGradingController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request)
    {
        $attempts=AssessmentAttempt::query()->with(['questions'])
            ->whereIn('status',['pending_review','graded'])->latest('submitted_at')->paginate(25);
        return view('course-assessments::grading',compact('attempts'));
    }

    public function grade(Request $request,AssessmentAttempt $attempt): RedirectResponse
    {
        abort_unless(in_array($attempt->status,['pending_review','graded'],true),409,'Attempt is not awaiting manual grading.');
        $data=$request->validate([
            'scores'=>['required','array'],
            'scores.*'=>['nullable','numeric','min:0'],
            'feedback'=>['nullable','array'],
            'feedback.*'=>['nullable','string','max:5000'],
            'teacher_feedback'=>['nullable','string','max:10000'],
        ]);
        $actor=$request->user();
        $manual=0.0;
        DB::transaction(function()use($attempt,$data,$actor,&$manual){
            $locked=AssessmentAttempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            foreach($locked->questions()->whereIn('type',['short_answer','written'])->get() as $q){
                $score=(float)($data['scores'][$q->id]??0);
                abort_if($score>(float)$q->marks,422,'Awarded score exceeds question marks.');
                $q->update(['marks_awarded'=>$score,'manual_feedback'=>$data['feedback'][$q->id]??null,'graded_by'=>$actor->id,'graded_at'=>now()]);
                $manual+=$score;
            }
            $locked->update(['manual_score'=>$manual,'final_score'=>(float)$locked->auto_score+$manual,'status'=>'graded','graded_by'=>$actor->id,'graded_at'=>now(),'teacher_feedback'=>$data['teacher_feedback']??null]);
        });
        $this->audit->log('course_assessment.graded','course_assessment_attempt',(string)$attempt->id,['manual_score'=>$manual]);
        return back()->with('status','Assessment grading saved.');
    }
}
