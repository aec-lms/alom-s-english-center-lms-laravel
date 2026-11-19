<?php

declare(strict_types=1);

namespace Plugins\Modules\MobileAppApi\Http\Controllers;

use App\Core\Plugins\PluginManager;
use App\Core\Security\BranchAccess;
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
        $branchId = app(BranchAccess::class)->contextBranchId($actor);
        $actor->setAttribute('branch_id', $branchId);
        $ids = DB::table('course_teacher_assignments')
            ->join('courses', 'courses.id', '=', 'course_teacher_assignments.course_id')
            ->where('course_teacher_assignments.teacher_user_id', $actor->id)
            ->where(function ($query) use ($branchId): void {
                $query->whereNull('courses.branch_id');
                if ($branchId !== null) {
                    $query->orWhere('courses.branch_id', $branchId);
                }
            })
            ->pluck('course_teacher_assignments.course_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return [$actor, $ids];
    }

    public function courses(Request $request): JsonResponse
    {
        [$actor, $ids] = $this->teacher($request);
        $courses = DB::table('courses')->whereIn('id', $ids)->whereNull('deleted_at')
            ->when($actor->branch_id !== null, fn ($q) => $q->where(function ($branch) use ($actor): void {
                $branch->whereNull('branch_id')->orWhere('branch_id', $actor->branch_id);
            }))
            ->orderBy('title')->paginate(30);
        return response()->json(['data' => $courses->getCollection()->map(fn ($c) => ['id'=>$c->id,'title'=>$c->title,'slug'=>$c->slug,'branch_id'=>$c->branch_id,'status'=>$c->status,'students_count'=>DB::table('enrollments')->where('course_id',$c->id)->whereIn('status',['active','completed'])->count()])->values(), 'meta'=>['current_page'=>$courses->currentPage(),'last_page'=>$courses->lastPage(),'total'=>$courses->total()]]);
    }

    public function courseStudents(Request $request, string $slug): JsonResponse
    {
        [$actor, $ids] = $this->teacher($request);
        $course = DB::table('courses')->where('slug', $slug)->whereIn('id', $ids)->first();
        abort_unless($course, 404);

        $hasStudentDirectory = app(PluginManager::class)->isEnabled('student-management')
            && Schema::hasTable('students');
        $studentsQuery = DB::table('enrollments')
            ->join('users', 'users.id', '=', 'enrollments.user_id')
            ->where('enrollments.course_id', $course->id)
            ->whereIn('enrollments.status', ['active', 'completed'])
            ->when($course->branch_id !== null, fn ($q) => $q->where('enrollments.branch_id', $course->branch_id))
            ->when($course->branch_id === null && $actor->branch_id !== null, fn ($q) => $q->where('enrollments.branch_id', $actor->branch_id));

        if ($hasStudentDirectory) {
            $studentsQuery->join('students', 'students.user_id', '=', 'users.id')
                ->when($course->branch_id !== null, fn ($q) => $q->where('students.branch_id', $course->branch_id))
                ->when($course->branch_id === null && $actor->branch_id !== null, fn ($q) => $q->where('students.branch_id', $actor->branch_id))
                ->when(Schema::hasColumn('students', 'deleted_at'), fn ($q) => $q->whereNull('students.deleted_at'));
        }

        $studentSelect = $hasStudentDirectory
            ? ['students.id as student_id', 'students.student_code']
            : [DB::raw('users.id as student_id'), DB::raw('NULL as student_code')];

        $students = $studentsQuery
            ->select(array_merge($studentSelect, ['users.id as user_id', 'users.name', 'users.email', 'enrollments.status', 'enrollments.created_at']))
            ->distinct()
            ->orderBy('users.name')
            ->paginate(50);

        $items = $students->getCollection()->map(fn ($row) => [
            'id' => $row->user_id,
            'name' => $row->name,
            'email' => $row->email,
            'student_code' => $row->student_code,
            'status' => $row->status,
            'enrolled_at' => $row->created_at,
            'student' => [
                'id' => $row->student_id,
                'student_code' => $row->student_code,
                'user_id' => $row->user_id,
                'name' => $row->name,
                'email' => $row->email,
            ],
        ]);

        return response()->json([
            'data' => $items,
            'meta' => ['current_page' => $students->currentPage(), 'last_page' => $students->lastPage(), 'total' => $students->total()],
        ]);
    }

    public function batches(Request $request): JsonResponse
    {
        [$actor,$ids]=$this->teacher($request);
        abort_unless(Schema::hasTable('batches'),409,'Course batches are unavailable.');
        $rows=DB::table('batches')->join('courses','courses.id','=','batches.course_id')->whereIn('batches.course_id',$ids)
            ->when($actor->branch_id,fn($q)=>$q->where('batches.branch_id',$actor->branch_id))
            ->select('batches.id','batches.name as title','batches.course_id','batches.branch_id','courses.title as course','courses.slug as course_slug','batches.capacity')
            ->orderBy('batches.name')->paginate(50);
        $batchData=$rows->getCollection()->map(function($row){$row->status='active';$row->assignments_count=Schema::hasTable('assignment_tasks')?DB::table('assignment_tasks')->where('course_id',$row->course_id)->count():0;return $row;});
        return response()->json(['data'=>$batchData,'meta'=>['current_page'=>$rows->currentPage(),'last_page'=>$rows->lastPage(),'total'=>$rows->total()]]);
    }

    public function showBatch(Request $request, int $batch): JsonResponse
    {
        [$actor, $ids] = $this->teacher($request);
        abort_unless(Schema::hasTable('batches'), 409, 'Course batches are unavailable.');

        $record = DB::table('batches')->join('courses', 'courses.id', '=', 'batches.course_id')
            ->where('batches.id', $batch)
            ->whereIn('batches.course_id', $ids)
            ->when($actor->branch_id !== null, fn ($q) => $q->where('batches.branch_id', $actor->branch_id))
            ->select('batches.*', 'courses.title as course_title')
            ->first();
        abort_unless($record, 404);

        $hasStudentDirectory = app(PluginManager::class)->isEnabled('student-management')
            && Schema::hasTable('students');
        $mapping = Schema::hasTable('attendance_batch_mappings')
            ? DB::table('attendance_batch_mappings')->where('source_type', 'course_batch')->where('source_id', $record->id)->first()
            : null;
        $rosterConfigured = $hasStudentDirectory && $mapping && Schema::hasTable('attendance_batch_rosters')
            && DB::table('attendance_batch_rosters')->where('attendance_batch_mapping_id', $mapping->id)->where('is_active', true)->exists();

        $studentQuery = DB::table('enrollments')
            ->join('users', 'users.id', '=', 'enrollments.user_id')
            ->where('enrollments.course_id', $record->course_id)
            ->where('enrollments.branch_id', $record->branch_id)
            ->whereIn('enrollments.status', ['active', 'completed'])
            ->when($actor->branch_id !== null, fn ($q) => $q->where('enrollments.branch_id', $actor->branch_id));

        if ($hasStudentDirectory) {
            $studentQuery->join('students', 'students.user_id', '=', 'users.id')
                ->where('students.branch_id', $record->branch_id)
                ->when(Schema::hasColumn('students', 'deleted_at'), fn ($q) => $q->whereNull('students.deleted_at'));
        }

        if ($rosterConfigured) {
            $studentQuery->join('attendance_batch_rosters', 'attendance_batch_rosters.student_id', '=', 'students.id')
                ->where('attendance_batch_rosters.attendance_batch_mapping_id', $mapping->id)
                ->where('attendance_batch_rosters.is_active', true);
        }

        $studentSelect = $hasStudentDirectory
            ? ['students.id as student_id', 'students.student_code']
            : [DB::raw('users.id as student_id'), DB::raw('NULL as student_code')];

        $students = $studentQuery
            ->select(array_merge($studentSelect, ['users.id as user_id', 'users.name', 'users.email', 'enrollments.status']))
            ->distinct()
            ->orderBy('users.name')
            ->paginate(50);

        $items = $students->getCollection()->map(fn ($row) => [
            'id' => $row->user_id,
            'name' => $row->name,
            'email' => $row->email,
            'student_code' => $row->student_code,
            'status' => $row->status,
            'student' => [
                'id' => $row->student_id,
                'student_code' => $row->student_code,
                'user_id' => $row->user_id,
                'name' => $row->name,
                'email' => $row->email,
            ],
        ]);

        return response()->json([
            'batch' => [
                'id' => $record->id,
                'title' => $record->name,
                'course' => $record->course_title,
                'course_id' => $record->course_id,
                'capacity' => $record->capacity,
                'enrolled_count' => $students->total(),
                'starts_at' => null,
                'ends_at' => null,
                'status' => 'active',
                'roster_configured' => (bool) $rosterConfigured,
            ],
            'data' => $items,
            'meta' => ['current_page' => $students->currentPage(), 'last_page' => $students->lastPage(), 'total' => $students->total()],
        ]);
    }

    public function assignments(Request $request): JsonResponse
    {
        [$actor,$ids]=$this->teacher($request);
        abort_unless(app(PluginManager::class)->isEnabled('assignment-management') && Schema::hasTable('assignment_tasks') && Schema::hasTable('assignment_submissions'),409,'Assignment Management is not enabled.');
        $rows=DB::table('assignment_tasks')->where(function($q)use($actor,$ids){$q->where('created_by',$actor->id)->orWhereIn('course_id',$ids);})
            ->when($actor->branch_id,fn($q)=>$q->where(function($branch)use($actor){$branch->whereNull('branch_id')->orWhere('branch_id',$actor->branch_id);}))
            ->orderByDesc('created_at')->paginate(30);
        $items=$rows->getCollection()->map(function($row){$row->batch=DB::table('courses')->where('id',$row->course_id)->value('title')??'Course assignment';$row->max_marks=$row->max_score;$row->submissions_count=DB::table('assignment_submissions')->where('assignment_id',$row->id)->count();return $row;});
        return response()->json(['data'=>$items,'meta'=>['current_page'=>$rows->currentPage(),'last_page'=>$rows->lastPage(),'total'=>$rows->total()]]);
    }

    public function storeAssignment(Request $request, int $batch): JsonResponse
    {
        [$actor, $ids] = $this->teacher($request);
        abort_unless(
            app(PluginManager::class)->isEnabled('assignment-management')
                && app(PluginManager::class)->isEnabled('course-management')
                && Schema::hasTable('assignment_tasks')
                && Schema::hasTable('batches'),
            409,
            'Assignment or Course Management is not enabled.'
        );
        $record = DB::table('batches')->where('id', $batch)->whereIn('course_id', $ids)
            ->when($actor->branch_id !== null, fn ($q) => $q->where('branch_id', $actor->branch_id))
            ->first();
        abort_unless($record, 404);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'instructions' => ['required', 'string', 'max:20000'],
            'due_at' => ['nullable', 'date', 'after:now'],
            'max_marks' => ['required_without:max_score', 'numeric', 'gt:0', 'max:100000'],
            'max_score' => ['required_without:max_marks', 'numeric', 'gt:0', 'max:100000'],
            'allow_late' => ['sometimes', 'boolean'],
        ]);
        $maxScore = (float) ($data['max_marks'] ?? $data['max_score']);

        $id = DB::table('assignment_tasks')->insertGetId([
            'branch_id' => $record->branch_id,
            'course_id' => $record->course_id,
            'created_by' => $actor->id,
            'title' => trim($data['title']),
            'instructions' => $data['instructions'],
            'due_at' => $data['due_at'] ?? null,
            'max_score' => $maxScore,
            'allow_late' => (bool) ($data['allow_late'] ?? false),
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'message' => 'Assignment published.',
            'assignment' => DB::table('assignment_tasks')->find($id),
        ], 201);
    }

    public function submissions(Request $request,int $assignment): JsonResponse
    {
        [$actor,$ids]=$this->teacher($request);
        $item=DB::table('assignment_tasks')->where('id',$assignment)->where(function($q)use($actor,$ids){$q->where('created_by',$actor->id)->orWhereIn('course_id',$ids);})->first();
        abort_unless($item,404);
        $rows=DB::table('assignment_submissions')->join('users','users.id','=','assignment_submissions.student_id')->where('assignment_id',$assignment)
            ->select('assignment_submissions.*','users.name as student_name','users.email as student_email')->orderByDesc('submitted_at')->paginate(50);
        $items=$rows->getCollection()->map(fn($row)=>['id'=>$row->id,'status'=>$row->status,'submitted_at'=>$row->submitted_at,'score'=>$row->score,'feedback'=>$row->feedback,'answer'=>$row->answer_text,'student'=>['id'=>$row->student_id,'name'=>$row->student_name,'email'=>$row->student_email]]);
        return response()->json(['assignment'=>['id'=>$item->id,'title'=>$item->title,'max_marks'=>$item->max_score],'data'=>$items,'meta'=>['current_page'=>$rows->currentPage(),'last_page'=>$rows->lastPage(),'total'=>$rows->total()]]);
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
        $count=DB::table('exam_attempts')->where('exam_id',$exam)->count();
        $passed=DB::table('exam_attempts')->where('exam_id',$exam)->where('passed',true)->count();
        $average=DB::table('exam_attempts')->where('exam_id',$exam)->avg('score_obtained');
        $items=$rows->getCollection()->map(fn($row)=>['id'=>$row->id,'score'=>(float)$row->score_obtained,'passed'=>(bool)$row->passed,'submitted_at'=>$row->submitted_at,'student'=>['id'=>$row->user_id,'name'=>$row->student_name]]);
        return response()->json(['exam'=>['id'=>$record->id,'title'=>$record->title,'pass_marks'=>$record->pass_marks,'course'=>DB::table('courses')->where('id',$record->course_id)->value('title'),'batch'=>'Course assessments'],'summary'=>['submitted_attempts'=>$count,'passed_attempts'=>$passed,'pass_rate'=>$count?round($passed*100/$count,1):0,'average_score'=>$average===null?null:round((float)$average,2)],'data'=>$items,'meta'=>['current_page'=>$rows->currentPage(),'last_page'=>$rows->lastPage(),'total'=>$rows->total()]]);
    }

    public function lessons(Request $request,string $slug): JsonResponse
    {
        [, $ids]=$this->teacher($request);
        $course=DB::table('courses')->where('slug',$slug)->whereIn('id',$ids)->first();
        abort_unless($course,404);
        $rows=DB::table('course_lessons')->where('course_id',$course->id)->orderBy('sort_order')->orderBy('id')->get();
        return response()->json(['data'=>$rows->map(fn($row)=>$this->lessonData($row))->values(),'meta'=>['total'=>$rows->count()]]);
    }

    public function storeLesson(Request $request,string $slug): JsonResponse
    {
        [, $ids]=$this->teacher($request);
        $course=DB::table('courses')->where('slug',$slug)->whereIn('id',$ids)->first();
        abort_unless($course,404);
        $data=$request->validate([
            'title'=>['required','string','max:180'],
            'content'=>['nullable','string','max:50000'],
            'duration_minutes'=>['nullable','integer','min:0','max:600'],
            'order'=>['nullable','integer','min:0'],
            'video_url'=>['nullable','url','max:255','regex:/^https:\/\/(www\.)?(youtube\.com|youtu\.be|vimeo\.com)\//i'],
            'is_preview'=>['sometimes','boolean'],
        ]);
        $id=DB::table('course_lessons')->insertGetId([
            'course_id'=>$course->id,'title'=>$data['title'],'content'=>$data['content']??null,
            'sort_order'=>$data['order']??0,'video_url'=>$data['video_url']??null,
            'duration_minutes'=>$data['duration_minutes']??0,'is_preview'=>(bool)($data['is_preview']??false),
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        return response()->json(['message'=>'Lesson created.','lesson'=>$this->lessonData(DB::table('course_lessons')->find($id))],201);
    }

    public function updateLesson(Request $request,string $slug,int $lesson): JsonResponse
    {
        [, $ids]=$this->teacher($request);
        $course=DB::table('courses')->where('slug',$slug)->whereIn('id',$ids)->first();
        abort_unless($course,404);
        abort_unless(DB::table('course_lessons')->where('id',$lesson)->where('course_id',$course->id)->exists(),404);
        $data=$request->validate([
            'title'=>['required','string','max:180'],
            'content'=>['nullable','string','max:50000'],
            'duration_minutes'=>['nullable','integer','min:0','max:600'],
            'order'=>['nullable','integer','min:0'],
            'video_url'=>['nullable','url','max:255','regex:/^https:\/\/(www\.)?(youtube\.com|youtu\.be|vimeo\.com)\//i'],
            'is_preview'=>['sometimes','boolean'],
        ]);
        DB::table('course_lessons')->where('id',$lesson)->update([
            'title'=>$data['title'],'content'=>$data['content']??null,'sort_order'=>$data['order']??0,
            'video_url'=>$data['video_url']??null,'duration_minutes'=>$data['duration_minutes']??0,
            'is_preview'=>(bool)($data['is_preview']??false),'updated_at'=>now(),
        ]);
        return response()->json(['message'=>'Lesson updated.','lesson'=>$this->lessonData(DB::table('course_lessons')->find($lesson))]);
    }

    private function lessonData(object $row): array
    {
        return [
            'id'=>$row->id,'course_id'=>$row->course_id,'title'=>$row->title,'content'=>$row->content,
            'duration_minutes'=>(int)($row->duration_minutes??0),'order'=>(int)$row->sort_order,
            'video_url'=>$row->video_url??null,'is_preview'=>(bool)($row->is_preview??false),
        ];
    }

    public function destroyLesson(Request $request,string $slug,int $lesson): JsonResponse
    {
        [, $ids]=$this->teacher($request);
        $course=DB::table('courses')->where('slug',$slug)->whereIn('id',$ids)->first(); abort_unless($course,404);
        $deleted=DB::table('course_lessons')->where('id',$lesson)->where('course_id',$course->id)->delete();
        abort_unless($deleted,404);
        return response()->json(['message'=>'Lesson deleted.']);
    }

    public function liveClasses(Request $request): JsonResponse
    {
        [$actor,$ids]=$this->teacher($request);
        abort_unless(app(PluginManager::class)->isEnabled('course-management') && Schema::hasTable('live_classes'),409,'Live classes are unavailable.');
        $rows=DB::table('live_classes')->join('courses','courses.id','=','live_classes.course_id')->whereIn('live_classes.course_id',$ids)
            ->when($actor->branch_id,fn($q)=>$q->where('courses.branch_id',$actor->branch_id))
            ->select('live_classes.*','courses.title as course')->orderByDesc('starts_at')->paginate(30);
        $items=$rows->getCollection()->map(function($row){$row->batch=$row->course;$row->status=$row->status==='upcoming'?'scheduled':$row->status;return $row;});
        return response()->json(['data'=>$items,'meta'=>['current_page'=>$rows->currentPage(),'last_page'=>$rows->lastPage(),'total'=>$rows->total()]]);
    }

    public function storeLiveClass(Request $request,int $batch): JsonResponse
    {
        [$actor,$ids]=$this->teacher($request);
        abort_unless(Schema::hasTable('batches') && Schema::hasTable('live_classes'),409);
        $record=DB::table('batches')->where('id',$batch)->whereIn('course_id',$ids)->first();
        abort_unless($record && (!$actor->branch_id || (int)$record->branch_id===(int)$actor->branch_id),404);
        $data=$request->validate([
            'title'=>['required','string','max:180'],'description'=>['nullable','string','max:5000'],
            'meeting_url'=>['required','url','max:255','regex:/^https:\/\//i'],
            'starts_at'=>['required','date','after:now'],'ends_at'=>['required','date','after:starts_at'],
            'platform'=>['nullable','string','max:40'],
        ]);
        $id=DB::table('live_classes')->insertGetId([
            'course_id'=>$record->course_id,'title'=>$data['title'],'description'=>$data['description']??null,
            'instructor_name'=>$actor->name,'platform'=>$data['platform']??'other','meeting_url'=>$data['meeting_url'],
            'starts_at'=>$data['starts_at'],'ends_at'=>$data['ends_at'],'capacity'=>300,'status'=>'upcoming',
            'created_by'=>$actor->id,'created_at'=>now(),'updated_at'=>now(),
        ]);
        $created=DB::table('live_classes')->find($id);$created->status='scheduled';$created->batch=DB::table('courses')->where('id',$record->course_id)->value('title');
        return response()->json(['message'=>'Live class scheduled.','live_class'=>$created],201);
    }

    public function updateLiveClass(Request $request,int $liveClass): JsonResponse
    {
        [$actor,$ids]=$this->teacher($request);
        $record=DB::table('live_classes')->where('id',$liveClass)->whereIn('course_id',$ids)->first();
        abort_unless($record,404);
        $data=$request->validate([
            'status'=>['sometimes','in:scheduled,upcoming,live,completed,cancelled'],
            'recording_url'=>['nullable','url','max:255','regex:/^https:\/\//i'],
            'recording_notes'=>['nullable','string','max:5000'],
        ]);
        $update=[];
        foreach(['status','recording_url','recording_notes'] as $key) if(array_key_exists($key,$data)) $update[$key]=$key==='status' && $data[$key]==='scheduled'?'upcoming':$data[$key];
        if(($data['status']??null)==='completed' && !empty($data['recording_url'])) $update['recording_published_at']=now();
        if($update) { $update['updated_at']=now(); DB::table('live_classes')->where('id',$liveClass)->update($update); }
        $updated=DB::table('live_classes')->find($liveClass);$updated->status=$updated->status==='upcoming'?'scheduled':$updated->status;$updated->batch=DB::table('courses')->where('id',$updated->course_id)->value('title');
        return response()->json(['message'=>'Live class updated.','live_class'=>$updated]);
    }

    public function notices(Request $request): JsonResponse
    {
        [$actor] = $this->teacher($request);
        abort_unless(
            app(PluginManager::class)->isEnabled('notice-board')
                && Schema::hasTable('notices')
                && Schema::hasTable('notice_reads'),
            409,
            'Notice Board read tracking is unavailable.'
        );

        $rows = DB::table('notices')
            ->whereIn('audience_role', ['all', 'teacher'])
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $actor->branch_id))
            ->where(fn ($q) => $q->whereNull('published_at')->orWhere('published_at', '<=', now()))
            ->leftJoin('notice_reads', fn ($join) => $join->on('notice_reads.notice_id', '=', 'notices.id')->where('notice_reads.user_id', '=', $actor->id))
            ->select('notices.id', 'notices.title', 'notices.body', 'notices.published_at', 'notices.is_pinned', 'notice_reads.read_at')
            ->orderByDesc('notices.is_pinned')
            ->orderByDesc('notices.published_at')
            ->paginate(30);

        $items = $rows->getCollection()->map(fn ($row) => [
            'id' => $row->id,
            'title' => $row->title,
            'body' => $row->body,
            'published_at' => $row->published_at,
            'is_pinned' => (bool) $row->is_pinned,
            'read_at' => $row->read_at,
            'is_read' => $row->read_at !== null,
        ]);
        $unreadCount = DB::table('notices')
            ->leftJoin('notice_reads', fn ($join) => $join->on('notice_reads.notice_id', '=', 'notices.id')->where('notice_reads.user_id', '=', $actor->id))
            ->whereIn('notices.audience_role', ['all', 'teacher'])
            ->where(fn ($q) => $q->whereNull('notices.branch_id')->orWhere('notices.branch_id', $actor->branch_id))
            ->where(fn ($q) => $q->whereNull('notices.published_at')->orWhere('notices.published_at', '<=', now()))
            ->whereNull('notice_reads.read_at')
            ->count();

        return response()->json([
            'data' => $items,
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'total' => $rows->total(),
                'unread_count' => $unreadCount,
            ],
        ]);
    }

    public function markNoticeRead(Request $request,int $notice): JsonResponse
    {
        [$actor]=$this->teacher($request);
        abort_unless(app(PluginManager::class)->isEnabled('notice-board') && Schema::hasTable('notices') && Schema::hasTable('notice_reads'),409,'Notice Board read tracking is unavailable.');
        $exists=DB::table('notices')->where('id',$notice)->whereIn('audience_role',['all','teacher'])
            ->where(fn($q)=>$q->whereNull('branch_id')->orWhere('branch_id',$actor->branch_id))->exists();
        abort_unless($exists,404);
        DB::table('notice_reads')->updateOrInsert(['notice_id'=>$notice,'user_id'=>$actor->id],['read_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
        return response()->json(['message'=>'Notice marked as read.']);
    }

    public function attendancePolicy(Request $request): JsonResponse
    {
        [$actor] = $this->teacher($request);
        $ready = app(PluginManager::class)->isEnabled('attendance-management')
            && app(PluginManager::class)->isEnabled('course-management')
            && app(PluginManager::class)->isEnabled('student-management')
            && Schema::hasTable('attendance_gps_consents')
            && Schema::hasTable('attendance_entries')
            && Schema::hasTable('attendance_entry_audit_logs')
            && Schema::hasTable('course_teacher_assignments');
        $consented = $ready && DB::table('attendance_gps_consents')
            ->where('user_id', $actor->id)
            ->where('policy_version', 'instructor-offline-gps-v1')
            ->whereNotNull('consented_at')
            ->whereNull('revoked_at')
            ->exists();

        return response()->json([
            'offline_sync_enabled' => $consented,
            'gps_consent_granted' => $consented,
            'consent_required' => $ready && !$consented,
            'allow_excused' => false,
            'max_location_age_minutes' => 10,
            'max_location_accuracy_meters' => 100,
            'message' => !$ready
                ? 'Offline GPS attendance sync requires Attendance, Course, Student and audit integrations.'
                : ($consented ? 'Offline GPS attendance sync is enabled for this instructor device.' : 'Review and accept the location-use notice before enabling offline attendance sync.'),
        ]);
    }

    public function grantAttendanceGpsConsent(Request $request): JsonResponse
    {
        [$actor] = $this->teacher($request);
        abort_unless(app(PluginManager::class)->isEnabled('attendance-management')
            && Schema::hasTable('attendance_gps_consents')
            && Schema::hasTable('attendance_entry_audit_logs'), 409, 'Attendance consent storage is unavailable.');

        DB::table('attendance_gps_consents')->where('user_id', $actor->id)
            ->where('policy_version', 'instructor-offline-gps-v1')
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'updated_at' => now()]);

        DB::table('attendance_gps_consents')->insert([
            'user_id' => $actor->id,
            'policy_version' => 'instructor-offline-gps-v1',
            'consented_at' => now(),
            'revoked_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['consented' => true, 'offline_sync_enabled' => true]);
    }

    public function revokeAttendanceGpsConsent(Request $request): JsonResponse
    {
        [$actor] = $this->teacher($request);
        abort_unless(Schema::hasTable('attendance_gps_consents'), 409, 'Attendance consent storage is unavailable.');
        $updated = DB::table('attendance_gps_consents')->where('user_id', $actor->id)
            ->where('policy_version', 'instructor-offline-gps-v1')
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'updated_at' => now()]);

        return response()->json(['consented' => false, 'revoked' => $updated > 0]);
    }

    public function createAttendanceQr(Request $request,int $batch): JsonResponse
    {
        [$actor,$ids]=$this->teacher($request);
        abort_unless(app(PluginManager::class)->isEnabled('attendance-management') && app(PluginManager::class)->isEnabled('course-management'),409);
        $record=DB::table('batches')->where('id',$batch)->whereIn('course_id',$ids)->first();
        abort_unless($record && (!$actor->branch_id || (int)$record->branch_id===(int)$actor->branch_id),404);
        $mapping=\Plugins\Modules\AttendanceManagement\Models\AttendanceBatchMapping::resolve('course_batch',(int)$record->id,(int)$record->branch_id,$record->name??null);
        $response=app(\Plugins\Modules\AttendanceManagement\Http\Controllers\AttendanceQrController::class)->issue($request,(int)$mapping->id);
        $payload=$response->getData(true);
        return response()->json(['status'=>$payload['status']??'ok','session_id'=>$payload['session_id']??null,'check_in_url'=>$payload['checkin_url']??null,'expires_at'=>$payload['expires_at']??null],$response->getStatusCode());
    }

}
