<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Security\AuditLogger;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Core\Plugins\PluginManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Plugins\Modules\StudentManagement\Models\Student;
use Plugins\Modules\TeacherManagement\Models\Teacher;
use Plugins\Modules\CourseManagement\Models\Course;
use Plugins\Modules\CourseManagement\Models\CourseCategory;
use Plugins\Modules\CourseManagement\Models\LiveClass;
use Plugins\Modules\ExamManagement\Models\Exam;
use Plugins\Modules\BatchManagement\Models\StudyBatch;
use Plugins\Modules\ClassManagement\Models\AcademicClass;
use Plugins\Modules\AdminBoosting\Models\BoostCampaign;
use Plugins\Modules\BranchManagement\Models\Branch;
use Plugins\Modules\CarouselBuilder\Models\CarouselItem;
use Plugins\Modules\CmsWebsite\Models\CmsCategory;
use Plugins\Modules\CmsWebsite\Models\CmsPost;
use Plugins\Modules\PageBuilder\Models\BuilderPage;
use Plugins\Modules\SliderBuilder\Models\HeroSlider;
use Plugins\Modules\SmsGateway\Models\SmsCampaign;
use Plugins\Modules\VisitorPopupAds\Models\VisitorPopup;

class RecycleBinController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PluginManager $plugins,
    ) {}

    public function index(Request $request): View
    {
        abort_unless($request->user()?->hasRole('admin'), 403);

        $students = new LengthAwarePaginator([], 0, 25);
        $teachers = new LengthAwarePaginator([], 0, 25);
        $courses = new LengthAwarePaginator([], 0, 25);
        $courseCategories = new LengthAwarePaginator([], 0, 25);
        $liveClasses = new LengthAwarePaginator([], 0, 25);
        $exams = new LengthAwarePaginator([], 0, 25);
        $batches = new LengthAwarePaginator([], 0, 25);
        $classes = new LengthAwarePaginator([], 0, 25);
        $boostCampaigns = new LengthAwarePaginator([], 0, 25);
        $branches = new LengthAwarePaginator([], 0, 25);
        $carouselItems = new LengthAwarePaginator([], 0, 25);
        $cmsCategories = new LengthAwarePaginator([], 0, 25);
        $cmsPosts = new LengthAwarePaginator([], 0, 25);
        $builderPages = new LengthAwarePaginator([], 0, 25);
        $heroSliders = new LengthAwarePaginator([], 0, 25);
        $smsCampaigns = new LengthAwarePaginator([], 0, 25);
        $visitorPopups = new LengthAwarePaginator([], 0, 25);
        if ($this->plugins->isEnabled('student-management')
            && Schema::hasTable('students')
            && Schema::hasColumn('students', 'deleted_at')) {
            $students = Student::withTrashed()
                ->with('user')
                ->whereNotNull('deleted_at')
                ->latest('deleted_at')
                ->paginate(25);
        }

        if ($this->plugins->isEnabled('teacher-management')
            && Schema::hasTable('teachers')
            && Schema::hasColumn('teachers', 'deleted_at')) {
            $teachers = Teacher::withTrashed()
                ->with('user')
                ->whereNotNull('deleted_at')
                ->latest('deleted_at')
                ->paginate(25, ['*'], 'teachers_page');
        }

        if ($this->plugins->isEnabled('course-management') && Schema::hasTable('courses') && Schema::hasColumn('courses', 'deleted_at')) {
            $courses = Course::withTrashed()->whereNotNull('deleted_at')->latest('deleted_at')->paginate(25, ['*'], 'courses_page');
        }
        if ($this->plugins->isEnabled('course-management') && Schema::hasTable('course_categories') && Schema::hasColumn('course_categories', 'deleted_at')) {
            $courseCategories = CourseCategory::withTrashed()->whereNotNull('deleted_at')->latest('deleted_at')->paginate(25, ['*'], 'course_categories_page');
        }
        if ($this->plugins->isEnabled('course-management') && Schema::hasTable('live_classes') && Schema::hasColumn('live_classes', 'deleted_at')) {
            $liveClasses = LiveClass::withTrashed()->with('course')->whereNotNull('deleted_at')->latest('deleted_at')->paginate(25, ['*'], 'live_classes_page');
        }
        if ($this->plugins->isEnabled('exam-management') && Schema::hasTable('exams') && Schema::hasColumn('exams', 'deleted_at')) {
            $exams = Exam::withTrashed()->whereNotNull('deleted_at')->latest('deleted_at')->paginate(25, ['*'], 'exams_page');
        }
        if ($this->plugins->isEnabled('batch-management') && Schema::hasTable('study_batches') && Schema::hasColumn('study_batches', 'deleted_at')) {
            $batches = StudyBatch::withTrashed()->whereNotNull('deleted_at')->latest('deleted_at')->paginate(25, ['*'], 'batches_page');
        }
        if ($this->plugins->isEnabled('class-management') && Schema::hasTable('academic_classes') && Schema::hasColumn('academic_classes', 'deleted_at')) {
            $classes = AcademicClass::withTrashed()->whereNotNull('deleted_at')->latest('deleted_at')->paginate(25, ['*'], 'classes_page');
        }

        if ($this->plugins->isEnabled('admin-boosting') && Schema::hasTable('boost_campaigns') && Schema::hasColumn('boost_campaigns', 'deleted_at')) {
            $boostCampaigns = BoostCampaign::withTrashed()->whereNotNull('deleted_at')->latest('deleted_at')->paginate(25, ['*'], 'boostCampaigns_page');
        }
        if ($this->plugins->isEnabled('branch-management') && Schema::hasTable('branches') && Schema::hasColumn('branches', 'deleted_at')) {
            $branches = Branch::withTrashed()->whereNotNull('deleted_at')->latest('deleted_at')->paginate(25, ['*'], 'branches_page');
        }
        if ($this->plugins->isEnabled('carousel-builder') && Schema::hasTable('cms_carousels') && Schema::hasColumn('cms_carousels', 'deleted_at')) {
            $carouselItems = CarouselItem::withTrashed()->whereNotNull('deleted_at')->latest('deleted_at')->paginate(25, ['*'], 'carouselItems_page');
        }
        if ($this->plugins->isEnabled('cms-website') && Schema::hasTable('cms_categories') && Schema::hasColumn('cms_categories', 'deleted_at')) {
            $cmsCategories = CmsCategory::withTrashed()->whereNotNull('deleted_at')->latest('deleted_at')->paginate(25, ['*'], 'cmsCategories_page');
        }
        if ($this->plugins->isEnabled('cms-website') && Schema::hasTable('cms_posts') && Schema::hasColumn('cms_posts', 'deleted_at')) {
            $cmsPosts = CmsPost::withTrashed()->whereNotNull('deleted_at')->latest('deleted_at')->paginate(25, ['*'], 'cmsPosts_page');
        }
        if ($this->plugins->isEnabled('page-builder') && Schema::hasTable('cms_pages') && Schema::hasColumn('cms_pages', 'deleted_at')) {
            $builderPages = BuilderPage::withTrashed()->whereNotNull('deleted_at')->latest('deleted_at')->paginate(25, ['*'], 'builderPages_page');
        }
        if ($this->plugins->isEnabled('slider-builder') && Schema::hasTable('cms_sliders') && Schema::hasColumn('cms_sliders', 'deleted_at')) {
            $heroSliders = HeroSlider::withTrashed()->whereNotNull('deleted_at')->latest('deleted_at')->paginate(25, ['*'], 'heroSliders_page');
        }
        if ($this->plugins->isEnabled('sms-gateway') && Schema::hasTable('sms_campaigns') && Schema::hasColumn('sms_campaigns', 'deleted_at')) {
            $smsCampaigns = SmsCampaign::withTrashed()->whereNotNull('deleted_at')->latest('deleted_at')->paginate(25, ['*'], 'smsCampaigns_page');
        }
        if ($this->plugins->isEnabled('visitor-popup-ads') && Schema::hasTable('visitor_popups') && Schema::hasColumn('visitor_popups', 'deleted_at')) {
            $visitorPopups = VisitorPopup::withTrashed()->whereNotNull('deleted_at')->latest('deleted_at')->paginate(25, ['*'], 'visitorPopups_page');
        }

        return view('admin.recycle-bin.index', compact('students', 'teachers', 'courses', 'courseCategories', 'liveClasses', 'exams', 'batches', 'classes', 'boostCampaigns', 'branches', 'carouselItems', 'cmsCategories', 'cmsPosts', 'builderPages', 'heroSliders', 'smsCampaigns', 'visitorPopups'));
    }

    public function restoreStudent(Request $request, int $studentId): RedirectResponse
    {
        abort_unless($request->user()?->hasRole('admin'), 403);
        abort_unless(
            $this->plugins->isEnabled('student-management')
            && Schema::hasTable('students')
            && Schema::hasColumn('students', 'deleted_at'),
            404
        );

        DB::transaction(function () use ($studentId, $request): void {
            // Shared lifecycle lock order: linked account -> student profile.
            // Read the immutable link first, then lock both rows in that order.
            $snapshot = Student::withTrashed()->findOrFail($studentId);
            $user = $snapshot->user_id
                ? User::query()->whereKey($snapshot->user_id)->lockForUpdate()->first()
                : null;
            abort_unless($snapshot->user_id === null || $user, 409, 'The linked student account is unavailable.');

            $student = Student::withTrashed()->whereKey($studentId)->lockForUpdate()->firstOrFail();
            abort_unless((int) $student->user_id === (int) $snapshot->user_id, 409, 'The linked student account changed during restore.');
            abort_unless($student->trashed(), 409, 'This student is not in the Recycle Bin.');

            $student->restore();
            $student->save();

            if ($user && $user->is_active) {
                $user->forceFill(['is_active' => false])->save();
            }

            $this->audit->logRequired('recycle_bin.student_restored', 'student', (string) $student->id, [
                'student_code' => $student->student_code,
                'account_reactivated' => false,
            ], $request->user()->id);
        });

        return redirect()->route('admin.recycle-bin.index')->with('status', 'Student profile restored. Account access remains disabled; an administrator must explicitly reactivate it after a fresh security check.');
    }
    public function restoreTeacher(Request $request, int $teacherId): RedirectResponse
    {
        abort_unless($request->user()?->hasRole('admin'), 403);
        abort_unless(
            $this->plugins->isEnabled('teacher-management')
            && Schema::hasTable('teachers')
            && Schema::hasColumn('teachers', 'deleted_at'),
            404
        );

        $actorId = (int) $request->user()->getKey();

        DB::transaction(function () use ($teacherId, $actorId): void {
            // Lock every participating account in deterministic numeric order,
            // then revalidate the actor's active state and admin role before
            // locking the soft-deleted teacher profile.
            $snapshot = Teacher::withTrashed()->findOrFail($teacherId);
            $linkedUserId = $snapshot->user_id !== null ? (int) $snapshot->user_id : null;
            $userIds = array_values(array_unique(array_filter([$actorId, $linkedUserId])));
            sort($userIds, SORT_NUMERIC);

            $lockedUsers = [];
            foreach ($userIds as $userId) {
                $lockedUsers[$userId] = User::query()->whereKey($userId)->lockForUpdate()->first();
                abort_unless(
                    $lockedUsers[$userId],
                    $userId === $linkedUserId ? 409 : 404,
                    $userId === $linkedUserId
                        ? 'The linked instructor account is unavailable.'
                        : 'The acting account is unavailable.'
                );
            }

            $actor = $lockedUsers[$actorId];
            abort_unless($actor->is_active, 403, 'Inactive accounts cannot restore teacher records.');

            $actorRolePivots = DB::table('role_user')
                ->where('user_id', $actorId)
                ->orderBy('role_id')
                ->lockForUpdate()
                ->get(['role_id']);
            $adminRoleId = DB::table('roles')->where('slug', 'admin')->value('id');
            abort_unless(
                $adminRoleId !== null && $actorRolePivots->contains(
                    fn ($pivot): bool => (int) $pivot->role_id === (int) $adminRoleId
                ),
                403,
                'Only an active administrator may restore teacher records.'
            );

            $user = $linkedUserId !== null ? $lockedUsers[$linkedUserId] : null;
            $teacher = Teacher::withTrashed()->whereKey($teacherId)->lockForUpdate()->firstOrFail();
            abort_unless((int) $teacher->user_id === (int) $linkedUserId, 409, 'The linked instructor account changed during restore.');
            abort_unless($teacher->trashed(), 409, 'This instructor is not in the Recycle Bin.');

            $teacher->restore();
            $teacher->save();

            if ($user && $user->is_active) {
                $user->forceFill(['is_active' => false])->save();
            }

            $this->audit->logRequired('recycle_bin.teacher_restored', 'teacher', (string) $teacher->id, [
                'employee_code' => $teacher->employee_code,
                'account_reactivated' => false,
            ], $actorId);
        });

        return redirect()->route('admin.recycle-bin.index')->with('status', 'Instructor profile restored. Account access remains disabled; an administrator must explicitly reactivate it after a fresh security check.');
    }

    public function restoreCourse(Request $request, int $recordId): RedirectResponse
    {
        abort_unless($request->user()?->hasRole('admin'), 403);
        abort_unless($this->plugins->isEnabled('course-management') && Schema::hasTable('courses') && Schema::hasColumn('courses', 'deleted_at'), 404);

        DB::transaction(function () use ($recordId, $request): void {
            $record = Course::withTrashed()->lockForUpdate()->findOrFail($recordId);
            abort_unless($record->trashed(), 409, 'This record is not in the Recycle Bin.');
            $record->restore();
            $this->audit->log('recycle_bin.course_restored', 'course', (string) $record->id, [
                'title' => $record->title,
            ], $request->user()->id);
        });

        return redirect()->route('admin.recycle-bin.index')->with('status', 'Course restored successfully.');
    }

    public function restoreExam(Request $request, int $recordId): RedirectResponse
    {
        abort_unless($request->user()?->hasRole('admin'), 403);
        abort_unless($this->plugins->isEnabled('exam-management') && Schema::hasTable('exams') && Schema::hasColumn('exams', 'deleted_at'), 404);

        DB::transaction(function () use ($recordId, $request): void {
            $record = Exam::withTrashed()->lockForUpdate()->findOrFail($recordId);
            abort_unless($record->trashed(), 409, 'This record is not in the Recycle Bin.');
            $record->restore();
            $this->audit->log('recycle_bin.exam_restored', 'exam', (string) $record->id, [
                'title' => $record->title,
            ], $request->user()->id);
        });

        return redirect()->route('admin.recycle-bin.index')->with('status', 'Exam restored successfully.');
    }

    public function restoreBatch(Request $request, int $recordId): RedirectResponse
    {
        abort_unless($request->user()?->hasRole('admin'), 403);
        abort_unless($this->plugins->isEnabled('batch-management') && Schema::hasTable('study_batches') && Schema::hasColumn('study_batches', 'deleted_at'), 404);

        DB::transaction(function () use ($recordId, $request): void {
            $record = StudyBatch::withTrashed()->lockForUpdate()->findOrFail($recordId);
            abort_unless($record->trashed(), 409, 'This record is not in the Recycle Bin.');
            $record->restore();
            $this->audit->log('recycle_bin.batch_restored', 'batch', (string) $record->id, [
                'name' => $record->name,
            ], $request->user()->id);
        });

        return redirect()->route('admin.recycle-bin.index')->with('status', 'Study batch restored successfully.');
    }

    public function restoreClass(Request $request, int $recordId): RedirectResponse
    {
        abort_unless($request->user()?->hasRole('admin'), 403);
        abort_unless($this->plugins->isEnabled('class-management') && Schema::hasTable('academic_classes') && Schema::hasColumn('academic_classes', 'deleted_at'), 404);

        DB::transaction(function () use ($recordId, $request): void {
            $record = AcademicClass::withTrashed()->lockForUpdate()->findOrFail($recordId);
            abort_unless($record->trashed(), 409, 'This record is not in the Recycle Bin.');
            $record->restore();
            $this->audit->log('recycle_bin.class_restored', 'class', (string) $record->id, [
                'name' => $record->name,
            ], $request->user()->id);
        });

        return redirect()->route('admin.recycle-bin.index')->with('status', 'Academic class restored successfully.');
    }

    public function restoreCourseCategory(Request $request, int $recordId): RedirectResponse
    {
        abort_unless($request->user()?->hasRole('admin'), 403);
        abort_unless($this->plugins->isEnabled('course-management') && Schema::hasTable('course_categories') && Schema::hasColumn('course_categories', 'deleted_at'), 404);

        DB::transaction(function () use ($recordId, $request): void {
            $record = CourseCategory::withTrashed()->lockForUpdate()->findOrFail($recordId);
            abort_unless($record->trashed(), 409, 'This category is not in the Recycle Bin.');
            $record->restore();
            $this->audit->log('recycle_bin.course_category_restored', 'course_category', (string) $record->id, [
                'name' => $record->name,
            ], $request->user()->id);
        });

        return redirect()->route('admin.recycle-bin.index')->with('status', 'Course category restored successfully.');
    }

    public function restoreLiveClass(Request $request, int $recordId): RedirectResponse
    {
        abort_unless($request->user()?->hasRole('admin'), 403);
        abort_unless($this->plugins->isEnabled('course-management') && Schema::hasTable('live_classes') && Schema::hasColumn('live_classes', 'deleted_at'), 404);

        DB::transaction(function () use ($recordId, $request): void {
            $record = LiveClass::withTrashed()->lockForUpdate()->findOrFail($recordId);
            abort_unless($record->trashed(), 409, 'This live class is not in the Recycle Bin.');
            $record->restore();
            $this->audit->log('recycle_bin.live_class_restored', 'live_class', (string) $record->id, [
                'title' => $record->title,
            ], $request->user()->id);
        });

        return redirect()->route('admin.recycle-bin.index')->with('status', 'Live class restored successfully.');
    }

    public function restoreBoostCampaign(Request $request, int $recordId): RedirectResponse
    {
        return $this->restoreEntity($request, $recordId, 'admin-boosting', 'boost_campaigns', BoostCampaign::class, 'boost_campaign', 'recycle_bin.boost_campaign_restored', 'campaign_name', 'Boost campaign');
    }

    public function restoreBranch(Request $request, int $recordId): RedirectResponse
    {
        return $this->restoreEntity($request, $recordId, 'branch-management', 'branches', Branch::class, 'branch', 'recycle_bin.branch_restored', 'name', 'Branch');
    }

    public function restoreCarouselItem(Request $request, int $recordId): RedirectResponse
    {
        return $this->restoreEntity($request, $recordId, 'carousel-builder', 'cms_carousels', CarouselItem::class, 'carousel_item', 'recycle_bin.carousel_item_restored', 'title', 'Carousel item');
    }

    public function restoreCmsCategory(Request $request, int $recordId): RedirectResponse
    {
        return $this->restoreEntity($request, $recordId, 'cms-website', 'cms_categories', CmsCategory::class, 'cms_category', 'recycle_bin.cms_category_restored', 'name', 'CMS category');
    }

    public function restoreCmsPost(Request $request, int $recordId): RedirectResponse
    {
        return $this->restoreEntity($request, $recordId, 'cms-website', 'cms_posts', CmsPost::class, 'cms_post', 'recycle_bin.cms_post_restored', 'title', 'CMS post');
    }

    public function restoreBuilderPage(Request $request, int $recordId): RedirectResponse
    {
        return $this->restoreEntity($request, $recordId, 'page-builder', 'cms_pages', BuilderPage::class, 'builder_page', 'recycle_bin.builder_page_restored', 'title', 'Page');
    }

    public function restoreHeroSlider(Request $request, int $recordId): RedirectResponse
    {
        return $this->restoreEntity($request, $recordId, 'slider-builder', 'cms_sliders', HeroSlider::class, 'hero_slider', 'recycle_bin.hero_slider_restored', 'title', 'Slider');
    }

    public function restoreSmsCampaign(Request $request, int $recordId): RedirectResponse
    {
        return $this->restoreEntity($request, $recordId, 'sms-gateway', 'sms_campaigns', SmsCampaign::class, 'sms_campaign', 'recycle_bin.sms_campaign_restored', 'title', 'SMS campaign');
    }

    public function restoreVisitorPopup(Request $request, int $recordId): RedirectResponse
    {
        return $this->restoreEntity($request, $recordId, 'visitor-popup-ads', 'visitor_popups', VisitorPopup::class, 'visitor_popup', 'recycle_bin.visitor_popup_restored', 'title', 'Visitor pop-up');
    }

    public function permanentlyDelete(Request $request, string $type, int $recordId): RedirectResponse
    {
        abort_unless($request->user()?->hasRole('admin'), 403);
        $request->validate(['confirm_permanent_delete' => ['required', 'accepted']]);

        $entities = [
            'students' => [Student::class, 'students', null, 'student'],
            'teachers' => [Teacher::class, 'teachers', null, 'teacher'],
            'courses' => [Course::class, 'courses', 'course-management', 'course'],
            'course-categories' => [CourseCategory::class, 'course_categories', 'course-management', 'course_category'],
            'live-classes' => [LiveClass::class, 'live_classes', 'course-management', 'live_class'],
            'exams' => [Exam::class, 'exams', 'exam-management', 'exam'],
            'batches' => [StudyBatch::class, 'study_batches', 'batch-management', 'batch'],
            'classes' => [AcademicClass::class, 'academic_classes', 'class-management', 'class'],
            'boost-campaigns' => [BoostCampaign::class, 'boost_campaigns', 'admin-boosting', 'boost_campaign'],
            'branches' => [Branch::class, 'branches', 'branch-management', 'branch'],
            'carousel-items' => [CarouselItem::class, 'cms_carousels', 'carousel-builder', 'carousel_item'],
            'cms-categories' => [CmsCategory::class, 'cms_categories', 'cms-website', 'cms_category'],
            'cms-posts' => [CmsPost::class, 'cms_posts', 'cms-website', 'cms_post'],
            'builder-pages' => [BuilderPage::class, 'cms_pages', 'page-builder', 'builder_page'],
            'hero-sliders' => [HeroSlider::class, 'cms_sliders', 'slider-builder', 'hero_slider'],
            'sms-campaigns' => [SmsCampaign::class, 'sms_campaigns', 'sms-gateway', 'sms_campaign'],
            'visitor-popups' => [VisitorPopup::class, 'visitor_popups', 'visitor-popup-ads', 'visitor_popup'],
        ];

        abort_unless(isset($entities[$type]), 404);
        [$modelClass, $table, $plugin, $entityType] = $entities[$type];
        abort_unless(($plugin === null || $this->plugins->isEnabled($plugin))
            && Schema::hasTable($table)
            && Schema::hasColumn($table, 'deleted_at'), 404);

        try {
            DB::transaction(function () use ($modelClass, $recordId, $entityType, $type, $request): void {
            $record = $modelClass::withTrashed()->lockForUpdate()->findOrFail($recordId);
            abort_unless($record->trashed(), 409, 'This record is not in the Recycle Bin.');

            $recordLabel = $record->getAttribute('name')
                ?? $record->getAttribute('title')
                ?? $record->getAttribute('student_code')
                ?? $record->getAttribute('employee_code')
                ?? ('#' . $recordId);

            if (!$record->forceDelete()) {
                throw new \RuntimeException('The record could not be permanently deleted.');
            }

            $this->audit->log('recycle_bin.permanently_deleted', $entityType, (string) $recordId, [
                'recycle_bin_type' => $type,
                'record_label' => (string) $recordLabel,
                'permanent' => true,
            ], $request->user()->id);
            });
        } catch (\Illuminate\Database\QueryException $exception) {
            report($exception);

            return back()->withErrors([
                'permanent_delete' => 'This record still has linked data and cannot be permanently deleted yet.',
            ]);
        }

        return redirect()->route('admin.recycle-bin.index')
            ->with('status', 'The selected record was permanently deleted.');
    }

    private function restoreEntity(
        Request $request,
        int $recordId,
        string $plugin,
        string $table,
        string $modelClass,
        string $entityType,
        string $event,
        string $displayColumn,
        string $label
    ): RedirectResponse {
        abort_unless($request->user()?->hasRole('admin'), 403);
        abort_unless($this->plugins->isEnabled($plugin) && Schema::hasTable($table) && Schema::hasColumn($table, 'deleted_at'), 404);

        DB::transaction(function () use ($recordId, $request, $modelClass, $entityType, $event, $displayColumn): void {
            $record = $modelClass::withTrashed()->lockForUpdate()->findOrFail($recordId);
            abort_unless($record->trashed(), 409, 'This record is not in the Recycle Bin.');
            $record->restore();
            $this->audit->log($event, $entityType, (string) $record->id, [
                $displayColumn => $record->getAttribute($displayColumn),
            ], $request->user()->id);
        });

        return redirect()->route('admin.recycle-bin.index')->with('status', $label . ' restored successfully.');
    }

}
