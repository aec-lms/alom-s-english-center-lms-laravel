<?php

declare(strict_types=1);

namespace Plugins\Modules\CourseContentStudio\Http\Controllers;

use App\Core\Plugins\PluginManager;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Plugins\Modules\CourseContentStudio\Models\CourseContentPost;
use Plugins\Modules\CourseContentStudio\Models\CourseContentProgress;
use Plugins\Modules\CourseContentStudio\Models\CourseCurriculumItem;

class CourseContentLearningController extends Controller
{
    public function show(Request $request,string $course,string $post): View
    {
        $this->ensureCourses();
        $courseRow=DB::table('courses')->where('slug',$course)->where('status','published')->whereNull('deleted_at')->first();
        abort_unless($courseRow,404);
        $content=CourseContentPost::published()->where('slug',$post)->firstOrFail();
        $item=CourseCurriculumItem::query()->where('course_id',$courseRow->id)->where('course_content_post_id',$content->id)->where('is_active',true)->firstOrFail();
        $user=$request->user();
        $enrolled=$user && $user->hasRole('student') && DB::table('enrollments')->where('user_id',$user->id)->where('course_id',$courseRow->id)->whereIn('status',['active','completed'])->exists();
        if ($user && $user->hasRole('student') && app(PluginManager::class)->isEnabled('learning-paths')) {
            app(\Plugins\Modules\LearningPaths\Services\LearningPathAccess::class)->assertCourseUnlocked((int) $user->id, (int) $courseRow->id);
        }
        $canManage=$this->canManageCourse($user,(int)$courseRow->id);
        $allowed=$content->access_type==='free' || $item->is_preview || $enrolled || $canManage;
        abort_unless($allowed,403,'Enroll in this course to unlock this lesson.');
        $progress=null;
        if($enrolled){$progress=CourseContentProgress::query()->updateOrCreate(['user_id'=>$user->id,'course_curriculum_item_id'=>$item->id],['last_viewed_at'=>now()]);}
        $items=CourseCurriculumItem::query()->with('post')->where('course_id',$courseRow->id)->where('is_active',true)->orderBy('position')->get()->filter(fn($row)=>$row->post?->status==='published' && $row->post->published_at?->lte(now()))->values();
        $trackableItems=$items->where('is_preview',false)->values();
        $totalLessonsCount=$trackableItems->count();
        $completedLessonsCount=$enrolled && $totalLessonsCount>0
            ? CourseContentProgress::query()->where('user_id',$user->id)->whereIn('course_curriculum_item_id',$trackableItems->pluck('id'))->whereNotNull('completed_at')->count()
            : 0;
        $progressPercent=$totalLessonsCount>0
            ? min(100,(int)round(($completedLessonsCount/$totalLessonsCount)*100))
            : 0;
        $index=$items->search(fn($row)=>$row->id===$item->id);
        return view('course-content-studio::learning',[
            'course'=>$courseRow,'post'=>$content,'item'=>$item,'progress'=>$progress,
            'previousItem'=>$index!==false && $index>0?$items[$index-1]:null,
            'nextItem'=>$index!==false?($items[$index+1]??null):null,
            'canComplete'=>(bool)$enrolled && !$item->is_preview,
            'isEnrolled'=>(bool)$enrolled,
            'totalLessonsCount'=>$totalLessonsCount,
            'completedLessonsCount'=>$completedLessonsCount,
            'progressPercent'=>$progressPercent,
        ]);
    }

    public function file(Request $request,string $course,string $post)
    {
        $this->ensureCourses();
        $courseRow=DB::table('courses')->where('slug',$course)->where('status','published')->firstOrFail();
        $content=CourseContentPost::published()->where('slug',$post)->firstOrFail();
        $item=CourseCurriculumItem::query()->where('course_id',$courseRow->id)->where('course_content_post_id',$content->id)->where('is_active',true)->firstOrFail();
        $user=$request->user();
        $enrolled=$user && $user->hasRole('student') && DB::table('enrollments')->where('user_id',$user->id)->where('course_id',$courseRow->id)->whereIn('status',['active','completed'])->exists();
        if ($user && $user->hasRole('student') && app(PluginManager::class)->isEnabled('learning-paths')) {
            app(\Plugins\Modules\LearningPaths\Services\LearningPathAccess::class)->assertCourseUnlocked((int) $user->id, (int) $courseRow->id);
        }
        abort_unless($content->access_type==='free' || $item->is_preview || $enrolled || $this->canManageCourse($user,(int)$courseRow->id),403);
        abort_unless($content->content_file_path && Storage::disk('local')->exists($content->content_file_path),404);
        $mime=$content->content_file_mime?:'application/octet-stream';
        $inline=in_array($mime,['application/pdf','video/mp4','audio/mpeg','image/jpeg','image/png','image/webp','image/gif'],true);
        return response()->file(Storage::disk('local')->path($content->content_file_path),[
            'Content-Type'=>$mime,
            'Content-Disposition'=>($inline?'inline':'attachment').'; filename="'.str_replace('"','',$content->content_file_name?:'lesson-file').'"',
            'X-Content-Type-Options'=>'nosniff','Cache-Control'=>'private, no-store, max-age=0','Referrer-Policy'=>'same-origin',
        ]);
    }

    public function complete(Request $request,string $course,string $post): RedirectResponse
    {
        $student=$request->user();
        abort_unless($student && $student->hasRole('student'),403);
        $this->ensureCourses();
        $courseRow=DB::table('courses')->where('slug',$course)->where('status','published')->firstOrFail();
        abort_unless(DB::table('enrollments')->where('user_id',$student->id)->where('course_id',$courseRow->id)->whereIn('status',['active','completed'])->exists(),403);
        if (app(PluginManager::class)->isEnabled('learning-paths')) {
            app(\Plugins\Modules\LearningPaths\Services\LearningPathAccess::class)->assertCourseUnlocked((int) $student->id, (int) $courseRow->id);
        }
        $content=CourseContentPost::published()->where('slug',$post)->firstOrFail();
        $item=CourseCurriculumItem::query()->where('course_id',$courseRow->id)->where('course_content_post_id',$content->id)->where('is_active',true)->firstOrFail();
        abort_if($item->is_preview,403,'Preview lessons do not count toward completion.');
        CourseContentProgress::query()->updateOrCreate(['user_id'=>$student->id,'course_curriculum_item_id'=>$item->id],['last_viewed_at'=>now(),'completed_at'=>now()]);
        return back()->with('status','Lesson marked as complete.');
    }


    private function canManageCourse($user,int $courseId): bool
    {
        if(!$user)return false;
        if($user->hasRole('admin'))return true;
        if(!$user->hasRole('teacher') || !Schema::hasTable('course_teacher_assignments'))return false;
        return DB::table('course_teacher_assignments')->where('course_id',$courseId)->where('teacher_user_id',$user->id)->exists();
    }

    private function ensureCourses(): void
    {
        abort_unless(app(PluginManager::class)->isEnabled('course-management') && Schema::hasTable('courses') && Schema::hasTable('enrollments'),409,'Course Management and Enrollment are required for course learning.');
    }
}
