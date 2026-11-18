<?php

declare(strict_types=1);

namespace Plugins\Modules\MobileAppApi\Http\Controllers;

use App\Core\Plugins\PluginManager;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class InstructorMobileApiController extends Controller
{
    private function teacher(Request $request): array
    {
        $actor = $request->user();
        abort_unless($actor && $actor->hasRole('teacher'), 403);
        abort_unless(app(PluginManager::class)->isEnabled('course-management') && Schema::hasTable('course_teacher_assignments') && Schema::hasTable('courses'), 409, 'Course teacher assignments are not configured.');
        $ids = DB::table('course_teacher_assignments')->where('teacher_user_id', $actor->id)->pluck('course_id')->map(fn ($id) => (int) $id)->all();
        return [$actor, $ids];
    }

    public function courses(Request $request): JsonResponse
    {
        [$actor, $ids] = $this->teacher($request);
        $courses = DB::table('courses')->whereIn('id', $ids)->whereNull('deleted_at')
            ->when($actor->branch_id, fn ($q) => $q->where('branch_id', $actor->branch_id))
            ->orderBy('title')->paginate(30);
        return response()->json(['data' => $courses->getCollection()->map(fn ($c) => ['id'=>$c->id,'title'=>$c->title,'slug'=>$c->slug,'branch_id'=>$c->branch_id])->values(), 'meta'=>['current_page'=>$courses->currentPage(),'last_page'=>$courses->lastPage(),'total'=>$courses->total()]]);
    }

    public function courseStudents(Request $request, string $slug): JsonResponse
    {
        [$actor, $ids] = $this->teacher($request);
        $course = DB::table('courses')->where('slug',$slug)->whereIn('id',$ids)->first();
        abort_unless($course,404);
        $students = DB::table('enrollments')->join('users','users.id','=','enrollments.user_id')
            ->where('enrollments.course_id',$course->id)->where('enrollments.branch_id',$course->branch_id)->whereIn('enrollments.status',['active','completed'])
            ->when($actor->branch_id,fn($q)=>$q->where('enrollments.branch_id',$actor->branch_id))
            ->select('users.id','users.name','users.email','enrollments.status','enrollments.created_at')
            ->orderBy('users.name')->paginate(50);
        return response()->json(['data'=>$students->items(),'meta'=>['current_page'=>$students->currentPage(),'last_page'=>$students->lastPage(),'total'=>$students->total()]]);
    }

    public function batches(Request $request): JsonResponse
    {
        [$actor,$ids]=$this->teacher($request);
        abort_unless(Schema::hasTable('batches'),409,'Course batches are unavailable.');
        $rows=DB::table('batches')->join('courses','courses.id','=','batches.course_id')->whereIn('batches.course_id',$ids)
            ->when($actor->branch_id,fn($q)=>$q->where('batches.branch_id',$actor->branch_id))
            ->select('batches.id','batches.name as title','batches.course_id','batches.branch_id','courses.title as course','courses.slug as course_slug','batches.capacity')
            ->orderBy('batches.name')->paginate(50);
        return response()->json(['data'=>$rows->items(),'meta'=>['current_page'=>$rows->currentPage(),'last_page'=>$rows->lastPage(),'total'=>$rows->total()]]);
    }

    public function showBatch(Request $request,int $batch): JsonResponse
    {
        [$actor,$ids]=$this->teacher($request);
        $record=DB::table('batches')->where('id',$batch)->whereIn('course_id',$ids)->first();
        abort_unless($record && (!$actor->branch_id || (int)$record->branch_id===(int)$actor->branch_id),404);
        $students=DB::table('enrollments')->join('users','users.id','=','enrollments.user_id')->where('enrollments.course_id',$record->course_id)
            ->whereIn('enrollments.status',['active','completed'])->select('users.id','users.name','users.email','enrollments.status')->orderBy('users.name')->paginate(50);
        return response()->json(['batch'=>['id'=>$record->id,'title'=>$record->name,'course_id'=>$record->course_id,'capacity'=>$record->capacity],'data'=>$students->items(),'meta'=>['current_page'=>$students->currentPage(),'last_page'=>$students->lastPage(),'total'=>$students->total()]]);
    }

    public function assignments(Request $request): JsonResponse
    {
        [$actor,$ids]=$this->teacher($request);
        abort_unless(app(PluginManager::class)->isEnabled('assignment-management') && Schema::hasTable('assignment_tasks') && Schema::hasTable('assignment_submissions'),409,'Assignment Management is not enabled.');
        $rows=DB::table('assignment_tasks')->where(function($q)use($actor,$ids){$q->where('created_by',$actor->id)->orWhereIn('course_id',$ids);})
            ->when($actor->branch_id,fn($q)=>$q->where(function($branch)use($actor){$branch->whereNull('branch_id')->orWhere('branch_id',$actor->branch_id);}))
            ->orderByDesc('created_at')->paginate(30);
        return response()->json(['data'=>$rows->items(),'meta'=>['current_page'=>$rows->currentPage(),'last_page'=>$rows->lastPage(),'total'=>$rows->total()]]);
    }

    public function storeAssignment(Request $request,int $batch): JsonResponse
    {
        [$actor,$ids]=$this->teacher($request);
        abort_unless(app(PluginManager::class)->isEnabled('assignment-management') && app(PluginManager::class)->isEnabled('course-management') && Schema::hasTable('assignment_tasks') && Schema::hasTable('batches'),409);
        $record=DB::table('batches')->where('id',$batch)->whereIn('course_id',$ids)->first();
        abort_unless($record && (!$actor->branch_id || (int)$record->branch_id===(int)$actor->branch_id),404);
        $data=$request->validate(['title'=>['required','string','max:180'],'instructions'=>['required','string','max:20000'],'due_at'=>['nullable','date','after:now'],'max_score'=>['required','numeric','gt:0','max:100000'],'allow_late'=>['sometimes','boolean']]);
        $id=DB::table('assignment_tasks')->insertGetId(['branch_id'=>$record->branch_id,'course_id'=>$record->course_id,'created_by'=>$actor->id,'title'=>$data['title'],'instructions'=>$data['instructions'],'due_at'=>$data['due_at']??null,'max_score'=>$data['max_score'],'allow_late'=>(bool)($data['allow_late']??false),'status'=>'published','created_at'=>now(),'updated_at'=>now()]);
        return response()->json(['message'=>'Assignment published.','assignment'=>DB::table('assignment_tasks')->find($id)],201);
    }

    public function submissions(Request $request,int $assignment): JsonResponse
    {
        [$actor,$ids]=$this->teacher($request);
        $item=DB::table('assignment_tasks')->where('id',$assignment)->where(function($q)use($actor,$ids){$q->where('created_by',$actor->id)->orWhereIn('course_id',$ids);})->first();
        abort_unless($item,404);
        $rows=DB::table('assignment_submissions')->join('users','users.id','=','assignment_submissions.student_id')->where('assignment_id',$assignment)
            ->select('assignment_submissions.*','users.name as student_name','users.email as student_email')->orderByDesc('submitted_at')->paginate(50);
        return response()->json(['assignment'=>$item,'data'=>$rows->items(),'meta'=>['current_page'=>$rows->currentPage(),'last_page'=>$rows->lastPage(),'total'=>$rows->total()]]);
    }

    public function grade(Request $request,int $assignment,int $submission): JsonResponse
    {
        [$actor,$ids]=$this->teacher($request);
        $item=DB::table('assignment_tasks')->where('id',$assignment)->where(function($q)use($actor,$ids){$q->where('created_by',$actor->id)->orWhereIn('course_id',$ids);})->first();
        abort_unless($item,404);
        $row=DB::table('assignment_submissions')->where('id',$submission)->where('assignment_id',$assignment)->first();
        abort_unless($row,404);
        $data=$request->validate(['score'=>['required','numeric','min:0','max:'.$item->max_score],'feedback'=>['nullable','string','max:10000']]);
        DB::table('assignment_submissions')->where('id',$submission)->update(['score'=>$data['score'],'feedback'=>$data['feedback']??null,'status'=>'graded','graded_by'=>$actor->id,'graded_at'=>now(),'updated_at'=>now()]);
        return response()->json(['message'=>'Submission graded.']);
    }

    public function exams(Request $request): JsonResponse
    {
        [$actor,$ids]=$this->teacher($request);
        abort_unless(app(PluginManager::class)->isEnabled('exam-management') && Schema::hasTable('exams'),409,'Exam Management is not enabled.');
        $rows=DB::table('exams')->whereIn('course_id',$ids)->where('is_published',true)
            ->select('exams.*')->orderByDesc('created_at')->paginate(30);
        $data=$rows->getCollection()->map(fn($e)=>['id'=>$e->id,'title'=>$e->title,'status'=>$e->is_published?'published':'draft','course'=>DB::table('courses')->where('id',$e->course_id)->value('title'),'questions_count'=>Schema::hasTable('exam_questions')?DB::table('exam_questions')->where('exam_id',$e->id)->count():0,'submitted_attempts_count'=>Schema::hasTable('exam_attempts')?DB::table('exam_attempts')->where('exam_id',$e->id)->count():0]);
        return response()->json(['data'=>$data->values(),'meta'=>['current_page'=>$rows->currentPage(),'last_page'=>$rows->lastPage(),'total'=>$rows->total()]]);
    }

    public function examResults(Request $request,int $exam): JsonResponse
    {
        [$actor,$ids]=$this->teacher($request);
        $record=DB::table('exams')->where('id',$exam)->whereIn('course_id',$ids)->first();
        abort_unless($record,404);
        $rows=DB::table('exam_attempts')->join('users','users.id','=','exam_attempts.user_id')->where('exam_id',$exam)
            ->select('exam_attempts.*','users.name as student_name')->orderByDesc('submitted_at')->paginate(50);
        return response()->json(['exam'=>['id'=>$record->id,'title'=>$record->title,'pass_marks'=>$record->pass_marks],'data'=>$rows->items(),'meta'=>['current_page'=>$rows->currentPage(),'last_page'=>$rows->lastPage(),'total'=>$rows->total()]]);
    }

    public function lessons(Request $request,string $slug): JsonResponse
    {
        [, $ids]=$this->teacher($request);
        $course=DB::table('courses')->where('slug',$slug)->whereIn('id',$ids)->first(); abort_unless($course,404);
        $rows=DB::table('course_lessons')->where('course_id',$course->id)->orderBy('sort_order')->orderBy('id')->get();
        return response()->json(['data'=>$rows,'meta'=>['total'=>$rows->count()]]);
    }

    public function storeLesson(Request $request,string $slug): JsonResponse
    {
        [, $ids]=$this->teacher($request);
        $course=DB::table('courses')->where('slug',$slug)->whereIn('id',$ids)->first(); abort_unless($course,404);
        $data=$request->validate(['title'=>['required','string','max:180'],'content'=>['nullable','string','max:50000'],'order'=>['nullable','integer','min:0'],'video_url'=>['nullable','url','max:255','regex:/^https:\/\/(www\.)?(youtube\.com|youtu\.be|vimeo\.com)\//i'],'is_preview'=>['sometimes','boolean']]);
        $id=DB::table('course_lessons')->insertGetId(['course_id'=>$course->id,'title'=>$data['title'],'content'=>($data['content']??'').(!empty($data['video_url'])?"

Video: ".$data['video_url']:''),'sort_order'=>$data['order']??0,'created_at'=>now(),'updated_at'=>now()]);
        return response()->json(['message'=>'Lesson created.','lesson'=>DB::table('course_lessons')->find($id)],201);
    }

    public function updateLesson(Request $request,string $slug,int $lesson): JsonResponse
    {
        [, $ids]=$this->teacher($request);
        $course=DB::table('courses')->where('slug',$slug)->whereIn('id',$ids)->first(); abort_unless($course,404);
        abort_unless(DB::table('course_lessons')->where('id',$lesson)->where('course_id',$course->id)->exists(),404);
        $data=$request->validate(['title'=>['required','string','max:180'],'content'=>['nullable','string','max:50000'],'sort_order'=>['nullable','integer','min:0']]);
        DB::table('course_lessons')->where('id',$lesson)->update(['title'=>$data['title'],'content'=>$data['content']??null,'sort_order'=>$data['order']??0,'updated_at'=>now()]);
        return response()->json(['message'=>'Lesson updated.','lesson'=>DB::table('course_lessons')->find($lesson)]);
    }

    public function destroyLesson(Request $request,string $slug,int $lesson): JsonResponse
    {
        [, $ids]=$this->teacher($request);
        $course=DB::table('courses')->where('slug',$slug)->whereIn('id',$ids)->first(); abort_unless($course,404);
        $deleted=DB::table('course_lessons')->where('id',$lesson)->where('course_id',$course->id)->delete();
        abort_unless($deleted,404);
        return response()->json(['message'=>'Lesson deleted.']);
    }
}
