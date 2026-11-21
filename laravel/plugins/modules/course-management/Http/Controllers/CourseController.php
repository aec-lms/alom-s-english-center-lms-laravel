<?php

declare(strict_types=1);

namespace Plugins\Modules\CourseManagement\Http\Controllers;

use App\Core\Security\AuditLogger;
use App\Core\Plugins\PluginManager;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Plugins\Modules\CourseManagement\Models\Course;
use Plugins\Modules\CourseManagement\Models\CourseCategory;
use Plugins\Modules\CourseManagement\Models\LiveClass;

class CourseController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): View
    {
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);
        $isBranchManager = $actor && $actor->hasRole('branch_manager');
        $courses = Schema::hasTable('courses')
            ? Course::query()->when($isBranchManager, fn ($q) => $q->where('branch_id', $actor->branch_id))->latest()->paginate(20)
            : collect();
        $categories = Schema::hasTable('course_categories')
            ? CourseCategory::query()->orderBy('name')->get()
            : collect();
        $cmsCategories = Schema::hasTable('cms_categories')
            ? DB::table('cms_categories')->where('is_active', true)->orderBy('name')->get()
            : collect();
        $cmsPosts = Schema::hasTable('cms_posts')
            ? DB::table('cms_posts')->orderByDesc('id')->limit(100)->get()
            : collect();
        $liveClasses = Schema::hasTable('live_classes')
            ? LiveClass::query()->with('course')->when($isBranchManager, fn ($q) => $q->whereHas('course', fn ($courseQuery) => $courseQuery->where('branch_id', $actor->branch_id)))->latest('starts_at')->get()
            : collect();

        return view('course-management::index', compact('courses', 'categories', 'cmsCategories', 'cmsPosts', 'liveClasses'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:40', 'unique:courses,code'],
            'title' => ['nullable', 'string', 'max:180'],
            'category' => ['nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'integer'],
            'cms_category_id' => ['nullable', 'integer'],
            'cms_post_id' => ['nullable', 'integer'],
            'instructor_name' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:5000'],
            'syllabus_lines' => ['nullable', 'string', 'max:5000'],
            'price' => ['required', 'numeric', 'min:0'],
            'original_price' => ['nullable', 'numeric', 'min:0'],
            'duration_weeks' => ['nullable', 'integer', 'min:1', 'max:104'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'status' => ['nullable', 'in:published,draft,archived'],
            'thumbnail' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'thumbnail_url' => ['nullable', 'string', 'max:255'],
            'pdf_file' => ['nullable', 'file', 'mimes:pdf', 'max:20480'],
            'pdf_url' => ['nullable', 'string', 'max:255'],
            'pdf_thumbnail' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'pdf_thumbnail_url' => ['nullable', 'string', 'max:255'],
            'seo_title' => ['nullable', 'string', 'max:180'],
            'seo_description' => ['nullable', 'string', 'max:320'],
        ]);

        $actor = $request->user();
        $this->ensureAssignedBranch($actor);
        if ($actor && $actor->hasRole('branch_manager')) {
            $validated['branch_id'] = $actor->branch_id;
        }

        $linkedPost = null;
        $cmsPostId = $validated['cms_post_id'] ?? null;
        if ($cmsPostId && Schema::hasTable('cms_posts')) {
            $linkedPost = DB::table('cms_posts')->where('id', $cmsPostId)->first();
        }

        $title = trim((string) ($validated['title'] ?? ''));
        if ($title === '' && $linkedPost) {
            $title = (string) $linkedPost->title;
        }
        if ($title === '') {
            return back()->withErrors(['title' => 'Course title or linked CMS post is required.'])->withInput();
        }

        $categoryName = trim((string) ($validated['category'] ?? ''));
        $categoryId = $validated['category_id'] ?? null;
        $cmsCategoryId = $validated['cms_category_id'] ?? null;

        if ($cmsCategoryId && Schema::hasTable('cms_categories')) {
            $cmsCat = DB::table('cms_categories')->where('id', $cmsCategoryId)->first();
            if ($cmsCat) {
                $categoryName = (string) $cmsCat->name;
            }
        } elseif ($categoryId && Schema::hasTable('course_categories')) {
            $cat = CourseCategory::query()->find($categoryId);
            if ($cat) {
                $categoryName = $cat->name;
            }
        } elseif ($linkedPost && $categoryName === '') {
            $categoryName = (string) ($linkedPost->category ?? 'General');
            $cmsCategoryId = $cmsCategoryId ?? ($linkedPost->category_id ?? null);
        }

        if ($categoryName === '') {
            $categoryName = 'General';
        }

        $thumbPath = $request->hasFile('thumbnail')
            ? $this->storeUploadedFile($request->file('thumbnail'), 'courses')
            : ($validated['thumbnail_url'] ?? ($linkedPost->thumbnail_path ?? null));

        $pdfPath = $request->hasFile('pdf_file')
            ? $this->storeUploadedFile($request->file('pdf_file'), 'courses/pdfs')
            : ($validated['pdf_url'] ?? ($linkedPost->pdf_path ?? null));

        $pdfThumbPath = $request->hasFile('pdf_thumbnail')
            ? $this->storeUploadedFile($request->file('pdf_thumbnail'), 'courses/pdf-thumbs')
            : ($validated['pdf_thumbnail_url'] ?? ($linkedPost->pdf_thumbnail ?? null));

        $description = trim((string) ($validated['description'] ?? ''));
        if ($description === '' && $linkedPost) {
            $description = (string) ($linkedPost->excerpt ?: Str::limit(strip_tags((string) $linkedPost->content), 400));
        }

        $syllabus = $this->parseSyllabusLines((string) ($validated['syllabus_lines'] ?? ''));

        $course = Course::query()->create([
            'code' => strtoupper(trim($validated['code'])),
            'title' => $title,
            'category' => $categoryName,
            'category_id' => $categoryId,
            'cms_post_id' => $cmsPostId,
            'cms_category_id' => $cmsCategoryId,
            'instructor_name' => ($validated['instructor_name'] ?? '') ?: ($linkedPost->author ?? 'Engr. Mahbubul Alom'),
            'slug' => Str::slug($title) . '-' . Str::lower(Str::random(4)),
            'description' => $description !== '' ? $description : null,
            'syllabus_json' => !empty($syllabus) ? $syllabus : null,
            'thumbnail' => $thumbPath,
            'pdf_path' => $pdfPath,
            'pdf_thumbnail' => $pdfThumbPath,
            'seo_title' => $validated['seo_title'] ?? ($linkedPost->seo_title ?? $title),
            'seo_description' => $validated['seo_description'] ?? ($linkedPost->seo_description ?? null),
            'price' => $validated['price'],
            'original_price' => $validated['original_price'] ?? null,
            'duration_weeks' => (int) ($validated['duration_weeks'] ?? 12),
            'branch_id' => $validated['branch_id'] ?? null,
            'status' => $validated['status'] ?? 'published',
        ]);

        $this->audit->log('course.created', 'course', (string) $course->id, [
            'code' => $course->code,
            'cms_post_id' => $course->cms_post_id,
            'cms_category_id' => $course->cms_category_id,
        ]);

        return back()->with('status', "Course '{$course->title}' created.");
    }

    public function update(Request $request, Course $course): RedirectResponse
    {
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);
        if ($actor && $actor->hasRole('branch_manager') && (int) $actor->branch_id !== (int) $course->branch_id) {
            abort(403, 'Branch isolation violation.');
        }
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:40', 'unique:courses,code,' . $course->id],
            'title' => ['required', 'string', 'max:180'],
            'category' => ['nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'integer'],
            'cms_category_id' => ['nullable', 'integer'],
            'cms_post_id' => ['nullable', 'integer'],
            'instructor_name' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:5000'],
            'syllabus_lines' => ['nullable', 'string', 'max:5000'],
            'price' => ['required', 'numeric', 'min:0'],
            'original_price' => ['nullable', 'numeric', 'min:0'],
            'duration_weeks' => ['nullable', 'integer', 'min:1', 'max:104'],
            'status' => ['required', 'in:published,draft,archived'],
            'thumbnail' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'thumbnail_url' => ['nullable', 'string', 'max:255'],
            'pdf_file' => ['nullable', 'file', 'mimes:pdf', 'max:20480'],
            'pdf_url' => ['nullable', 'string', 'max:255'],
            'pdf_thumbnail' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'pdf_thumbnail_url' => ['nullable', 'string', 'max:255'],
            'seo_title' => ['nullable', 'string', 'max:180'],
            'seo_description' => ['nullable', 'string', 'max:320'],
        ]);

        $categoryName = $validated['category'] ?? $course->category ?? 'General';
        $categoryId = $validated['category_id'] ?? $course->category_id;
        $cmsCategoryId = $validated['cms_category_id'] ?? $course->cms_category_id;

        if ($cmsCategoryId && Schema::hasTable('cms_categories')) {
            $cmsCat = DB::table('cms_categories')->where('id', $cmsCategoryId)->first();
            if ($cmsCat) {
                $categoryName = (string) $cmsCat->name;
            }
        } elseif ($categoryId && Schema::hasTable('course_categories')) {
            $cat = CourseCategory::query()->find($categoryId);
            if ($cat) {
                $categoryName = $cat->name;
            }
        }

        $oldAssets = [$course->thumbnail, $course->pdf_path, $course->pdf_thumbnail];

        $thumbPath = $course->thumbnail;
        if ($request->hasFile('thumbnail')) {
            $thumbPath = $this->storeUploadedFile($request->file('thumbnail'), 'courses');
        } elseif (!empty($validated['thumbnail_url'])) {
            $thumbPath = $validated['thumbnail_url'];
        }

        $pdfPath = $course->pdf_path;
        if ($request->hasFile('pdf_file')) {
            $pdfPath = $this->storeUploadedFile($request->file('pdf_file'), 'courses/pdfs');
        } elseif (!empty($validated['pdf_url'])) {
            $pdfPath = $validated['pdf_url'];
        }

        $pdfThumbPath = $course->pdf_thumbnail;
        if ($request->hasFile('pdf_thumbnail')) {
            $pdfThumbPath = $this->storeUploadedFile($request->file('pdf_thumbnail'), 'courses/pdf-thumbs');
        } elseif (!empty($validated['pdf_thumbnail_url'])) {
            $pdfThumbPath = $validated['pdf_thumbnail_url'];
        }

        $syllabus = $request->filled('syllabus_lines')
            ? $this->parseSyllabusLines((string) $validated['syllabus_lines'])
            : $course->syllabus_json;

        $this->assertCoursePathIntegrity((int) $course->id, false, (string) $validated['status']);
        $course->update([
            'code' => strtoupper(trim($validated['code'])),
            'title' => $validated['title'],
            'category' => $categoryName,
            'category_id' => $categoryId,
            'cms_post_id' => $validated['cms_post_id'] ?? $course->cms_post_id,
            'cms_category_id' => $cmsCategoryId,
            'instructor_name' => $validated['instructor_name'] ?? $course->instructor_name,
            'description' => $validated['description'] ?? $course->description,
            'syllabus_json' => $syllabus,
            'thumbnail' => $thumbPath,
            'pdf_path' => $pdfPath,
            'pdf_thumbnail' => $pdfThumbPath,
            'seo_title' => $validated['seo_title'] ?? $course->seo_title,
            'seo_description' => $validated['seo_description'] ?? $course->seo_description,
            'price' => $validated['price'],
            'original_price' => $validated['original_price'] ?? $course->original_price,
            'duration_weeks' => (int) ($validated['duration_weeks'] ?? $course->duration_weeks ?? 12),
            'status' => $validated['status'],
        ]);

        foreach ([[$oldAssets[0], $thumbPath], [$oldAssets[1], $pdfPath], [$oldAssets[2], $pdfThumbPath]] as [$oldPath, $newPath]) {
            if ($oldPath && $oldPath !== $newPath) $this->deleteUnusedUpload($oldPath, (int) $course->id);
        }

        $this->audit->log('course.updated', 'course', (string) $course->id, ['code' => $course->code]);

        return back()->with('status', "Course '{$course->title}' updated.");
    }

    public function destroy(Request $request, Course $course): RedirectResponse
    {
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);
        if ($actor && $actor->hasRole('branch_manager') && (int) $actor->branch_id !== (int) $course->branch_id) {
            abort(403, 'Branch isolation violation.');
        }
        $this->assertCoursePathIntegrity((int) $course->id, true);
        foreach ([$course->thumbnail, $course->pdf_path, $course->pdf_thumbnail] as $assetPath) {
            $this->deleteUnusedUpload($assetPath, (int) $course->id);
        }
        $title = $course->title;
        $id = (string) $course->id;
        $course->delete();

        $this->audit->log('course.deleted', 'course', $id, ['title' => $title]);

        return back()->with('status', "Course '{$title}' deleted.");
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        $this->ensureGlobalCategoryAccess($request->user());
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['nullable', 'alpha_dash', 'max:140', 'unique:course_categories,slug'],
            'description' => ['nullable', 'string', 'max:320'],
            'color' => ['nullable', 'string', 'max:32'],
        ]);

        $slug = !empty($validated['slug'])
            ? strtolower($validated['slug'])
            : Str::slug($validated['name']) . '-' . Str::lower(Str::random(3));

        $cat = CourseCategory::query()->create([
            'name' => $validated['name'],
            'slug' => $slug,
            'description' => $validated['description'] ?? null,
            'color' => $validated['color'] ?? 'blue',
            'is_active' => true,
        ]);

        $this->audit->log('course_category.created', 'course_category', (string) $cat->id, ['name' => $cat->name]);

        return back()->with('status', "Category '{$cat->name}' created.");
    }

    public function updateCategory(Request $request, CourseCategory $category): RedirectResponse
    {
        $this->ensureGlobalCategoryAccess($request->user());
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'alpha_dash', 'max:140', 'unique:course_categories,slug,' . $category->id],
            'description' => ['nullable', 'string', 'max:320'],
            'color' => ['nullable', 'string', 'max:32'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $category->update([
            'name' => $validated['name'],
            'slug' => strtolower($validated['slug']),
            'description' => $validated['description'] ?? null,
            'color' => $validated['color'] ?? $category->color,
            'is_active' => $request->boolean('is_active'),
        ]);

        $this->audit->log('course_category.updated', 'course_category', (string) $category->id, ['name' => $category->name]);

        return back()->with('status', "Category '{$category->name}' updated.");
    }

    public function destroyCategory(CourseCategory $category): RedirectResponse
    {
        $this->ensureGlobalCategoryAccess(request()->user());
        $name = $category->name;
        $id = (string) $category->id;
        $category->delete();

        $this->audit->log('course_category.deleted', 'course_category', $id, ['name' => $name]);

        return back()->with('status', "Category '{$name}' deleted.");
    }

    public function storeLiveClass(Request $request): RedirectResponse
    {
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);
        $validated = $request->validate([
            'course_id' => ['required', 'integer', 'exists:courses,id'],
            'title' => ['required', 'string', 'max:180'],
            'instructor_name' => ['nullable', 'string', 'max:120'],
            'platform' => ['required', 'in:zoom,google_meet,teams'],
            'meeting_url' => ['required', 'url', 'max:255'],
            'starts_at' => ['required', 'date'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:2000'],
        ]);

        $course = Course::query()->findOrFail((int) $validated['course_id']);
        if ($actor && $actor->hasRole('branch_manager') && (int) $actor->branch_id !== (int) $course->branch_id) {
            abort(403, 'Branch isolation violation.');
        }

        $live = LiveClass::query()->create([
            'course_id' => $validated['course_id'],
            'title' => $validated['title'],
            'instructor_name' => ($validated['instructor_name'] ?? '') ?: "Engr. Mahbubul Alom",
            'platform' => $validated['platform'],
            'meeting_url' => $validated['meeting_url'],
            'starts_at' => $validated['starts_at'],
            'capacity' => (int) ($validated['capacity'] ?? 300),
            'status' => 'upcoming',
        ]);

        $this->audit->log('live_class.scheduled', 'live_class', (string) $live->id, ['title' => $live->title]);

        return back()->with('status', "Live class '{$live->title}' scheduled.");
    }

    public function updateLiveClass(Request $request, LiveClass $liveClass): RedirectResponse
    {
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);
        if ($actor && $actor->hasRole('branch_manager') && (int) $actor->branch_id !== (int) $liveClass->course?->branch_id) {
            abort(403, 'Branch isolation violation.');
        }
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'instructor_name' => ['nullable', 'string', 'max:120'],
            'platform' => ['required', 'in:zoom,google_meet,teams'],
            'meeting_url' => ['required', 'url', 'max:255'],
            'recording_url' => ['nullable', 'url', 'max:255'],
            'starts_at' => ['required', 'date'],
            'status' => ['required', 'in:upcoming,live,completed,cancelled'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:2000'],
        ]);

        $validated['capacity'] = (int) ($validated['capacity'] ?? $liveClass->capacity ?? 300);
        $liveClass->update($validated);

        $this->audit->log('live_class.updated', 'live_class', (string) $liveClass->id, ['status' => $liveClass->status]);

        return back()->with('status', "Live class '{$liveClass->title}' updated ({$liveClass->status}).");
    }

    public function destroyLiveClass(Request $request, LiveClass $liveClass): RedirectResponse
    {
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);
        if ($actor && $actor->hasRole('branch_manager') && (int) $actor->branch_id !== (int) $liveClass->course?->branch_id) {
            abort(403, 'Branch isolation violation.');
        }
        $title = $liveClass->title;
        $id = (string) $liveClass->id;
        $liveClass->delete();

        $this->audit->log('live_class.deleted', 'live_class', $id, ['title' => $title]);

        return back()->with('status', "Live class '{$title}' removed.");
    }

    private function ensureGlobalCategoryAccess($actor): void
    {
        abort_unless(
            $actor && $actor->hasRole('admin'),
            403,
            'Only a central administrator can manage global course categories.'
        );
    }

    private function deleteUnusedUpload(?string $path, ?int $ignoreCourseId = null): void
    {
        if (!$path || !str_starts_with($path, '/uploads/')) return;
        $references = [
            'cms_posts' => ['thumbnail_path', 'pdf_thumbnail', 'pdf_path'],
            'courses' => ['thumbnail', 'pdf_thumbnail', 'pdf_path'],
            'storefront_items' => ['thumbnail_path', 'pdf_thumbnail_path', 'download_url'],
        ];
        foreach ($references as $table => $columns) {
            if (!Schema::hasTable($table)) continue;
            foreach ($columns as $column) {
                if (!Schema::hasColumn($table, $column)) continue;
                $query = DB::table($table)->where($column, $path);
                if ($table === 'courses' && $ignoreCourseId) $query->where('id', '!=', $ignoreCourseId);
                if ($query->exists()) return;
            }
        }
        $absolute = public_path(ltrim($path, '/'));
        $root = realpath(public_path('uploads'));
        $file = realpath($absolute);
        if ($root && $file && str_starts_with($file, $root . DIRECTORY_SEPARATOR) && is_file($file)) @unlink($file);
    }

    private function parseSyllabusLines(string $raw): array
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $raw) ?: [])));
        $modules = [];
        foreach ($lines as $idx => $line) {
            $modules[] = [
                'week' => $idx + 1,
                'title' => $line,
            ];
        }

        return $modules;
    }

    private function storeUploadedFile(UploadedFile $file, string $folder): string
    {
        $dir = public_path('uploads/' . $folder);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $extension = strtolower((string) $file->guessExtension());
        abort_unless(in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'pdf'], true), 422, 'Unsupported course upload file type.');

        // Public uploads must use a server-detected extension, never the client filename.
        $filename = Str::uuid()->toString() . '.' . $extension;
        $file->move($dir, $filename);

        return '/uploads/' . $folder . '/' . $filename;
    }
    private function assertCoursePathIntegrity(int $courseId, bool $deleting = false, ?string $newStatus = null): void
    {
        $plugins = app(PluginManager::class);
        if (!$plugins->isEnabled('learning-paths')
            || !Schema::hasTable('learning_path_courses')
            || !Schema::hasTable('learning_path_enrollments')
        ) {
            return;
        }

        $hasActivePathLearners = DB::table('learning_path_courses as item')
            ->join('learning_path_enrollments as enrollment', 'enrollment.learning_path_id', '=', 'item.learning_path_id')
            ->where('item.course_id', $courseId)
            ->where('enrollment.status', 'active')
            ->exists();

        if (!$hasActivePathLearners) {
            return;
        }

        abort_if($deleting, 409, 'This course belongs to an active learning path. Complete or withdraw path learners before deleting it.');
        abort_unless($newStatus === 'published', 409,
            'This course belongs to an active learning path and cannot be unpublished or archived until those learners finish.');
    }

    private function ensureAssignedBranch($actor): void
    {
        if (!$actor || !$actor->hasRole('branch_manager')) {
            return;
        }

        abort_unless(
            $actor->branch_id !== null
                && app(PluginManager::class)->isEnabled('branch-management')
                && Schema::hasTable('branches')
                && DB::table('branches')
                    ->where('id', $actor->branch_id)
                    ->where('is_active', true)
                    ->exists(),
            403,
            'Branch manager must be assigned to an active branch.'
        );
    }

}
