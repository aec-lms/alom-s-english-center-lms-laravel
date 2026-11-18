<?php

declare(strict_types=1);

namespace Plugins\Modules\MobileAppApi\Http\Controllers;

use App\Core\Plugins\PluginManager;
use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Plugins\Modules\CourseManagement\Models\Course;
use Plugins\Modules\CourseManagement\Models\Enrollment;
use Plugins\Modules\MobileAppApi\Models\MobileDeviceToken;

class MobileApiController extends Controller
{
    public function __construct(private readonly PluginManager $plugins) {}

    public function config(Request $request): JsonResponse
    {
        $cfg = $this->loadMobileConfig();
        $platform = strtolower((string) $request->query('platform', 'all'));

        return response()->json([
            'brand' => "Alom's English Center",
            'domain' => 'https://www.alomsenglishcenter.com',
            'support_email' => 'info@alomsenglishcenter.com',
            'deep_link_scheme' => $cfg['deep_link_scheme'],
            'maintenance_mode' => (bool) $cfg['maintenance_mode'],
            'maintenance_message' => $cfg['maintenance_message'],
            'push_notifications_enabled' => (bool) $cfg['push_enabled'],
            'platform_requested' => in_array($platform, ['ios', 'android'], true) ? $platform : 'all',
            'ios' => [
                'enabled' => (bool) $cfg['ios_enabled'],
                'bundle_id' => $cfg['ios_bundle_id'],
                'app_store_url' => $cfg['ios_store_url'],
                'min_version' => $cfg['ios_min_version'],
                'latest_version' => $cfg['ios_latest_version'],
                'force_update' => (bool) $cfg['ios_force_update'],
            ],
            'android' => [
                'enabled' => (bool) $cfg['android_enabled'],
                'package_name' => $cfg['android_package_name'],
                'play_store_url' => $cfg['android_store_url'],
                'min_version' => $cfg['android_min_version'],
                'latest_version' => $cfg['android_latest_version'],
                'force_update' => (bool) $cfg['android_force_update'],
            ],
        ]);
    }

    public function home(Request $request): JsonResponse
    {
        $actor = $this->mobileActor($request);
        $cfg = $this->loadMobileConfig();

        $sliders = $this->plugins->isEnabled('slider-builder') && Schema::hasTable('cms_sliders')
            ? DB::table('cms_sliders')->where('is_active', true)->orderBy('sort_order')->get(['id', 'title', 'subtitle', 'image_path', 'cta_url'])
            : collect();

        $courses = $this->plugins->isEnabled('course-management') && Schema::hasTable('courses')
            ? DB::table('courses')->where('status', 'published')->orderByDesc('id')->limit(12)->get(['id', 'code', 'title', 'slug', 'price', 'thumbnail', 'instructor_name'])
            : collect();

        $carouselItems = $this->plugins->isEnabled('storefront-catalog') && Schema::hasTable('storefront_items')
            ? DB::table('storefront_items')
                ->where('is_active', true)
                ->orderByDesc('is_carousel_featured')
                ->orderByDesc('id')
                ->limit(12)
                ->get(['id', 'type', 'slug', 'title', 'category', 'badge', 'price', 'original_price', 'is_free', 'pages', 'summary', 'thumbnail_path', 'pdf_thumbnail_path'])
            : collect();

        $ebooks = $this->plugins->isEnabled('storefront-catalog') && Schema::hasTable('storefront_items')
            ? DB::table('storefront_items')->where('type', 'ebook')->where('is_active', true)->orderByDesc('id')->limit(12)->get(['id', 'type', 'slug', 'title', 'category', 'badge', 'price', 'original_price', 'is_free', 'pages', 'summary', 'thumbnail_path', 'pdf_thumbnail_path'])
            : collect();

        $products = $this->plugins->isEnabled('storefront-catalog') && Schema::hasTable('storefront_items')
            ? DB::table('storefront_items')->where('type', 'product')->where('is_active', true)->orderByDesc('id')->limit(12)->get(['id', 'type', 'slug', 'title', 'category', 'badge', 'price', 'original_price', 'summary', 'thumbnail_path'])
            : collect();

        $visibleNoticeRoles = $actor ? $actor->roles()->pluck('slug')->all() : [];
        $visibleNoticeRoles[] = 'all';
        $notices = $this->plugins->isEnabled('notice-board') && Schema::hasTable('notices')
            ? DB::table('notices')
                ->where(function ($query) use ($actor): void {
                    $query->whereNull('branch_id');
                    if ($actor && $actor->branch_id !== null) {
                        $query->orWhere('branch_id', $actor->branch_id);
                    }
                })
                ->where(function ($query) use ($visibleNoticeRoles): void {
                    $query->whereNull('audience_role')->orWhereIn('audience_role', $visibleNoticeRoles);
                })
                ->where(function ($query): void {
                    $query->whereNull('published_at')->orWhere('published_at', '<=', now());
                })
                ->orderByDesc('id')
                ->limit(12)
                ->get()
            : collect();

        $stats = [
            'students' => $this->plugins->isEnabled('student-management') && Schema::hasTable('students') ? DB::table('students')->count() : 0,
            'courses' => $this->plugins->isEnabled('course-management') && Schema::hasTable('courses') ? DB::table('courses')->where('status', 'published')->count() : 0,
            'teachers' => $this->plugins->isEnabled('teacher-management') && Schema::hasTable('teachers') ? DB::table('teachers')->count() : 0,
            'lessons' => $this->plugins->isEnabled('course-management') && Schema::hasTable('course_lessons') ? DB::table('course_lessons')->count() : 0,
        ];

        return response()->json([
            'brand' => "Alom's English Center",
            'sliders' => $sliders,
            'courses' => $courses,
            'ebooks' => $ebooks,
            'products' => $products,
            'notices' => $notices,
            'digital_carousel' => $carouselItems,
            'stats' => $stats,
            'apps' => [
                'ios_store_url' => $cfg['ios_store_url'],
                'android_store_url' => $cfg['android_store_url'],
            ],
        ]);
    }

    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'platform' => ['nullable', 'in:ios,android'],
            'device_name' => ['required', 'string', 'max:80'],
            'device_id' => ['nullable', 'string', 'max:160'],
            'app_version' => ['nullable', 'string', 'max:40'],
            'push_token' => ['nullable', 'string', 'max:500'],
        ]);

        $user = User::query()->create([
            'name' => $validated['name'],
            'email' => strtolower($validated['email']),
            'password' => Hash::make($validated['password']),
            'is_active' => true,
        ]);

        if (Schema::hasTable('roles') && Schema::hasTable('role_user')) {
            $studentRole = Role::query()->where('slug', 'student')->first();
            if ($studentRole) {
                $user->roles()->syncWithoutDetaching([$studentRole->id]);
            }
        }

        $this->recordDeviceToken($user, $validated);
        $token = $user->createToken($validated['device_name'])->plainTextToken;

        return response()->json([
            'token' => $token,
            'access_token' => $token,
            'platform' => $validated['platform'] ?? 'android',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'branch_id' => $user->branch_id,
            ],
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:80'],
            'platform' => ['nullable', 'in:ios,android'],
            'device_id' => ['nullable', 'string', 'max:160'],
            'app_version' => ['nullable', 'string', 'max:40'],
            'push_token' => ['nullable', 'string', 'max:500'],
        ]);

        $user = User::query()->where('email', strtolower($validated['email']))->first();
        if (!$user || !$user->is_active || !Hash::check($validated['password'], $user->password)) {
            return response()->json(['message' => 'Invalid mobile credentials.'], 401);
        }

        $this->recordDeviceToken($user, $validated);
        $token = $user->createToken($validated['device_name'])->plainTextToken;

        return response()->json([
            'token' => $token,
            'access_token' => $token,
            'platform' => $validated['platform'] ?? 'android',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'branch_id' => $user->branch_id,
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user && $user->currentAccessToken()) {
            $user->currentAccessToken()->delete();
        }

        return response()->json(['message' => 'Signed out successfully.']);
    }

    public function registerPushToken(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'platform' => ['required', 'in:ios,android'],
            'device_name' => ['required', 'string', 'max:120'],
            'device_id' => ['nullable', 'string', 'max:160'],
            'app_version' => ['nullable', 'string', 'max:40'],
            'push_token' => ['required', 'string', 'max:500'],
        ]);

        $record = $this->recordDeviceToken($request->user(), $validated);

        return response()->json([
            'message' => 'Mobile push token registered.',
            'device' => $record,
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $enrollments = $this->plugins->isEnabled('course-management') && Schema::hasTable('enrollments')
            ? Enrollment::query()
                ->where('user_id', $user->id)
                ->with('course:id,code,title,slug,thumbnail,pdf_thumbnail')
                ->get()
            : collect();

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'branch_id' => $user->branch_id,
            ],
            'enrollments' => $enrollments,
        ]);
    }

    public function courses(): JsonResponse
    {
        abort_unless($this->plugins->isEnabled('course-management') && Schema::hasTable('courses'), 409, 'Course Management is disabled.');
        return response()->json([
            'data' => Course::query()
                ->where('status', 'published')
                ->select(['id', 'code', 'title', 'slug', 'price', 'description', 'thumbnail', 'instructor_name'])
                ->latest()
                ->paginate(20),
        ]);
    }

    public function courseShow(string $slug): JsonResponse
    {
        abort_unless($this->plugins->isEnabled('course-management') && Schema::hasTable('courses'), 404, 'Course Management is disabled.');
        // Course currently has no Eloquent category/lessons relationships.
        // Return the persisted course record instead of eager-loading undefined relations.
        $course = Course::query()
            ->select(['id', 'code', 'title', 'slug', 'description', 'price', 'thumbnail', 'instructor_name', 'status'])
            ->where('slug', $slug)
            ->where('status', 'published')
            ->first();

        if (!$course) {
            return response()->json(['message' => 'Course not found.'], 404);
        }

        return response()->json(['data' => $course, 'lessons' => []]);
    }

    public function lessons(Request $request, string $slug): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor, 401);
        abort_unless(
            $this->plugins->isEnabled('course-management')
                && Schema::hasTable('courses')
                && Schema::hasTable('course_lessons')
                && Schema::hasTable('enrollments'),
            409,
            'Course content access is unavailable until Course Management is installed.'
        );

        $course = Course::query()
            ->where('slug', $slug)
            ->where('status', 'published')
            ->firstOrFail();

        $hasAccess = Enrollment::query()
            ->where('user_id', $actor->id)
            ->where('course_id', $course->id)
            ->whereIn('status', ['active', 'completed'])
            ->exists();
        abort_unless($hasAccess, 403, 'An active course enrollment is required to view lessons.');

        $lessons = DB::table('course_lessons')
            ->where('course_id', $course->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'course_id', 'title', 'content', 'sort_order']);

        return response()->json(['data' => $lessons]);
    }

    public function ebooks(): JsonResponse
    {
        $items = $this->plugins->isEnabled('storefront-catalog') && Schema::hasTable('storefront_items')
            ? DB::table('storefront_items')->where('type', 'ebook')->where('is_active', true)->orderByDesc('id')->get(['id', 'type', 'slug', 'title', 'category', 'badge', 'price', 'original_price', 'is_free', 'pages', 'summary', 'thumbnail_path', 'pdf_thumbnail_path'])
            : collect();

        return response()->json(['data' => $items]);
    }

    public function products(): JsonResponse
    {
        $items = $this->plugins->isEnabled('storefront-catalog') && Schema::hasTable('storefront_items')
            ? DB::table('storefront_items')->where('type', 'product')->where('is_active', true)->orderByDesc('id')->get(['id', 'type', 'slug', 'title', 'category', 'badge', 'price', 'original_price', 'summary', 'thumbnail_path'])
            : collect();

        return response()->json(['data' => $items]);
    }

    public function exams(): JsonResponse
    {
        $exams = $this->plugins->isEnabled('exam-management') && Schema::hasTable('exams')
            ? DB::table('exams')->where('is_published', true)->orderByDesc('id')->limit(30)->get()
            : collect();

        return response()->json(['data' => $exams]);
    }

    public function notices(Request $request): JsonResponse
    {
        $actor = $this->mobileActor($request);
        $visibleNoticeRoles = $actor ? $actor->roles()->pluck('slug')->all() : [];
        $visibleNoticeRoles[] = 'all';
        $notices = $this->plugins->isEnabled('notice-board') && Schema::hasTable('notices')
            ? DB::table('notices')
                ->where(function ($query) use ($actor): void {
                    $query->whereNull('branch_id');
                    if ($actor && $actor->branch_id !== null) {
                        $query->orWhere('branch_id', $actor->branch_id);
                    }
                })
                ->where(function ($query) use ($visibleNoticeRoles): void {
                    $query->whereNull('audience_role')->orWhereIn('audience_role', $visibleNoticeRoles);
                })
                ->where(function ($query): void {
                    $query->whereNull('published_at')->orWhere('published_at', '<=', now());
                })
                ->orderByDesc('id')
                ->limit(30)
                ->get()
            : collect();

        return response()->json(['data' => $notices]);
    }

    public function verifyCertificate(string $code): JsonResponse
    {
        if (!$this->plugins->isEnabled('certificate-management') || !Schema::hasTable('certificates')) {
            return response()->json(['verified' => false, 'message' => 'Certificate verification unavailable.'], 404);
        }

        $cert = DB::table('certificates')->where('verification_code', $code)->first();
        if (!$cert || ($cert->status ?? 'issued') === 'revoked') {
            return response()->json(['verified' => false, 'message' => 'Certificate not found or revoked.'], 404);
        }

        return response()->json(['verified' => true, 'data' => $cert]);
    }


    private function mobileActor(Request $request): ?User
    {
        // Public mobile endpoints accept optional Sanctum bearer authentication.
        // The API guard is not required so anonymous app visitors remain supported.
        return $request->bearerToken() !== null
            ? $request->user('sanctum')
            : $request->user();
    }

    private function recordDeviceToken(?User $user, array $validated): ?MobileDeviceToken
    {
        if (!Schema::hasTable('mobile_device_tokens')) {
            return null;
        }

        $platform = strtolower((string) ($validated['platform'] ?? 'android'));
        $deviceId = $validated['device_id'] ?? Str::slug($validated['device_name']);

        return MobileDeviceToken::query()->updateOrCreate(
            [
                'user_id' => $user?->id,
                'platform' => $platform,
                'device_id' => $deviceId,
            ],
            [
                'device_name' => $validated['device_name'],
                'app_version' => $validated['app_version'] ?? '1.0.0',
                'push_token' => $validated['push_token'] ?? null,
                'last_seen_at' => now(),
                'is_active' => true,
            ]
        );
    }

    private function loadMobileConfig(): array
    {
        $defaults = MobileAppConfigController::defaultConfig();

        if (!Schema::hasTable('system_settings')) {
            return $defaults;
        }

        $raw = SystemSetting::query()->where('key', 'mobile_app_config')->value('value');
        if (!is_string($raw) || $raw === '') {
            return $defaults;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? array_merge($defaults, $decoded) : $defaults;
    }
}
