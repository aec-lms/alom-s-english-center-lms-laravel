<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Plugins\PluginManager;
use App\Models\SystemSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Plugins\Modules\CmsWebsite\Models\CmsPost;
use Plugins\Modules\AdminBoosting\Models\BoostCampaign;

class PublicHomeController extends Controller
{
    public function index(): View
    {
        $homepage = $this->loadHomepageConfig();
        $homepageBoostCampaigns = $this->homepageBoostCampaigns();

        $liveStats = [
            'students' => Schema::hasTable('students') ? DB::table('students')->count() : 0,
            'courses' => Schema::hasTable('courses') ? DB::table('courses')->where('status', 'published')->count() : 0,
            'teachers' => Schema::hasTable('teachers') ? DB::table('teachers')->count() : 0,
            'videos' => Schema::hasTable('course_lessons')
                && Schema::hasColumn('course_lessons', 'media_path')
                && Schema::hasColumn('course_lessons', 'media_mime')
                ? DB::table('course_lessons')->whereNotNull('media_path')->where('media_path', '<>', '')
                    ->where('media_mime', 'like', 'video/%')->count()
                : 0,
        ];

        $stats = ($homepage['stats_mode'] ?? 'auto') === 'manual' ? [
            'students' => (int) ($homepage['stats_students_override'] ?? $liveStats['students']),
            'courses' => (int) ($homepage['stats_courses_override'] ?? $liveStats['courses']),
            'teachers' => (int) ($homepage['stats_teachers_override'] ?? $liveStats['teachers']),
            'videos' => (int) ($homepage['stats_videos_override'] ?? $homepage['stats_lessons_override'] ?? $liveStats['videos']),
        ] : $liveStats;

        $sliders = collect();
        $leftReviews = collect();
        $rightReviews = collect();

        if (Schema::hasTable('cms_sliders')) {
            $sliders = DB::table('cms_sliders')
                ->where('key_name', 'hero')
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get();
        }

        if (Schema::hasTable('cms_carousels')) {
            $leftReviews = DB::table('cms_carousels')
                ->where('carousel_group', 'testimonial_left')
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get();

            $rightReviews = DB::table('cms_carousels')
                ->where('carousel_group', 'testimonial_right')
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get();
        } elseif (Schema::hasTable('cms_sliders')) {
            $leftReviews = DB::table('cms_sliders')
                ->where('key_name', 'testimonial_left')
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get();

            $rightReviews = DB::table('cms_sliders')
                ->where('key_name', 'testimonial_right')
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get();
        }

        $digitalCarouselItems = $this->loadDigitalCarouselItems();

        return view('public.home', compact(
            'stats',
            'sliders',
            'leftReviews',
            'rightReviews',
            'homepage',
            'digitalCarouselItems',
            'homepageBoostCampaigns'
        ));
    }

    public function customPage(string $slug): View
    {
        if (!Schema::hasTable('cms_pages')) {
            abort(404);
        }

        $page = DB::table('cms_pages')
            ->where('slug', $slug)
            ->where('is_published', true)
            ->first();

        if ($page === null) {
            abort(404);
        }

        $storedBlocks = json_decode((string) ($page->blocks_json ?? '[]'), true);
        $blocks = [];

        foreach (is_array($storedBlocks) ? $storedBlocks : [] as $block) {
            if (!is_array($block)) {
                continue;
            }

            $type = (string) ($block['type'] ?? '');
            if ($type === 'rich_text') {
                $type = 'text';
            } elseif ($type === 'cta') {
                $type = 'button';
            }

            if (in_array($type, ['heading', 'text'], true)) {
                $content = trim((string) ($block['content'] ?? ''));
                if ($content !== '') {
                    $align = $block['align'] ?? 'left';
                    $blocks[] = [
                        'type' => $type,
                        'content' => $content,
                        'align' => in_array($align, ['left', 'center', 'right'], true) ? $align : 'left',
                    ];
                }
            } elseif ($type === 'image') {
                $url = trim((string) ($block['url'] ?? ''));
                if ($this->isSafePageBlockUrl($url)) {
                    $blocks[] = [
                        'type' => 'image',
                        'url' => $url,
                        'alt' => trim((string) ($block['alt'] ?? '')),
                    ];
                }
            } elseif ($type === 'button') {
                $url = trim((string) ($block['url'] ?? ''));
                $label = trim((string) ($block['label'] ?? $block['content'] ?? ''));
                if ($label !== '' && $this->isSafePageBlockUrl($url)) {
                    $align = $block['align'] ?? 'left';
                    $blocks[] = [
                        'type' => 'button',
                        'label' => $label,
                        'url' => $url,
                        'align' => in_array($align, ['left', 'center', 'right'], true) ? $align : 'left',
                    ];
                }
            } elseif ($type === 'spacer') {
                $blocks[] = [
                    'type' => 'spacer',
                    'height' => max(16, min(240, (int) ($block['height'] ?? 48))),
                ];
            } elseif (in_array($type, ['course_grid', 'instructor_grid'], true)) {
                $blocks[] = ['type' => $type];
            }
        }

        $courseCards = collect($blocks)->contains('type', 'course_grid')
            ? $this->catalogCourses()->take(6)
            : collect();
        $instructorCards = collect($blocks)->contains('type', 'instructor_grid')
            ? $this->catalogInstructors()
            : [];

        return view('public.custom-page', compact('page', 'blocks', 'courseCards', 'instructorCards'));
    }

    private function isSafePageBlockUrl(string $url): bool
    {
        if ($url === '') {
            return false;
        }

        if (str_starts_with($url, '/') && !str_starts_with($url, '//') && !str_contains($url, '..') && !str_contains($url, '\\')) {
            return true;
        }

        return filter_var($url, FILTER_VALIDATE_URL)
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }

    public function courses(Request $request): View
    {
        $activeCategory = trim((string) $request->query('category', 'all'));
        $searchTerm = mb_substr(trim((string) $request->query('q', '')), 0, 100);
        $courses = $this->catalogCourses();

        $courseSocialProofSettings = $this->courseSocialProofSettings();
        $courseSocialProofStats = [];
        if (($courseSocialProofSettings['enabled'] ?? false) && ($courseSocialProofSettings['show_on_cards'] ?? true)) {
            $viewCounts = Schema::hasTable('course_view_events')
                ? DB::table('course_view_events')->select('course_id')->selectRaw('COUNT(*) AS total')->groupBy('course_id')->pluck('total', 'course_id')
                : collect();
            $purchaseQuery = DB::table('enrollments')
                ->select('course_id')
                ->selectRaw('COUNT(*) AS total')
                ->whereIn('status', ['active', 'completed']);
            if (Schema::hasTable('fee_invoices')) {
                $purchaseQuery->whereNotNull('invoice_id')->whereExists(function ($invoiceQuery): void {
                            $invoiceQuery->selectRaw('1')
                                ->from('fee_invoices')
                                ->whereColumn('fee_invoices.id', 'enrollments.invoice_id')
                                ->where('fee_invoices.status', 'paid')
                                ->whereRaw('fee_invoices.paid_amount + 0.01 >= fee_invoices.net_amount');
                });
            } else {
                $purchaseQuery->whereRaw('1 = 0');
            }
            $purchaseCounts = Schema::hasTable('enrollments')
                ? $purchaseQuery->groupBy('course_id')->pluck('total', 'course_id')
                : collect();

            foreach ($courses as $catalogCourse) {
                $courseSocialProofStats[$catalogCourse->id] = [
                    'views' => (int) ($viewCounts[$catalogCourse->id] ?? 0),
                    'purchases' => (int) ($purchaseCounts[$catalogCourse->id] ?? 0),
                ];
            }
        }
        $categories = $courses->pluck('category')->filter()->unique()->values();
        $filteredCourses = $activeCategory === 'all' || $activeCategory === ''
            ? $courses
            : $courses->filter(fn ($c) => strcasecmp((string) ($c->category ?? ''), $activeCategory) === 0)->values();

        if ($searchTerm !== '') {
            $needle = mb_strtolower($searchTerm);
            $filteredCourses = $filteredCourses->filter(function ($course) use ($needle): bool {
                $haystack = mb_strtolower(implode(' ', [
                    (string) ($course->title ?? ''),
                    (string) ($course->category ?? ''),
                    strip_tags((string) ($course->description ?? '')),
                ]));
                return str_contains($haystack, $needle);
            })->values();
        }

        $boostCampaignsByCourse = [];
        if ($filteredCourses->isNotEmpty() && Schema::hasTable('boost_campaigns') && app(PluginManager::class)->isEnabled('admin-boosting')) {
            $courseIds = $filteredCourses->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $branchIds = $filteredCourses->pluck('branch_id')->filter()->unique()->map(fn ($id): int => (int) $id)->all();
            $activeBranchIds = Schema::hasTable('branches') && app(PluginManager::class)->isEnabled('branch-management') && $branchIds !== []
                ? DB::table('branches')->whereIn('id', $branchIds)->where('is_active', true)
                    ->when(Schema::hasColumn('branches', 'deleted_at'), fn ($query) => $query->whereNull('deleted_at'))
                    ->pluck('id')->map(fn ($id): int => (int) $id)->all()
                : [];

            $boostCampaigns = BoostCampaign::query()
                ->where('placement', 'course_listing')
                ->where('status', 'active')
                ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
                ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
                ->where(function ($query) use ($courseIds, $activeBranchIds): void {
                    $query->where(fn ($global) => $global->whereIn('entity_type', ['institution', 'offer'])->whereNull('entity_id'));
                    if ($courseIds !== []) {
                        $query->orWhere(fn ($course) => $course->where('entity_type', 'course')->whereIn('entity_id', $courseIds));
                    }
                    if ($activeBranchIds !== []) {
                        $query->orWhere(fn ($branch) => $branch->where('entity_type', 'branch')->whereIn('entity_id', $activeBranchIds));
                    }
                })
                ->orderByDesc('priority')
                ->orderBy('id')
                ->limit(150)
                ->get();

            foreach ($filteredCourses as $catalogCourse) {
                $catalogCourseId = (int) $catalogCourse->id;
                $catalogBranchId = (int) ($catalogCourse->branch_id ?? 0);
                $boostCampaignsByCourse[$catalogCourseId] = $boostCampaigns->filter(
                    fn (BoostCampaign $campaign): bool => (
                        in_array($campaign->entity_type, ['institution', 'offer'], true) && $campaign->entity_id === null
                    ) || (
                        $campaign->entity_type === 'course' && (int) $campaign->entity_id === $catalogCourseId
                    ) || (
                        $campaign->entity_type === 'branch' && $catalogBranchId > 0 && in_array($catalogBranchId, $activeBranchIds, true)
                        && (int) $campaign->entity_id === $catalogBranchId
                    )
                )->values();
            }
        }

        return view('public.courses', compact('courses', 'categories', 'filteredCourses', 'activeCategory', 'searchTerm', 'courseSocialProofSettings', 'courseSocialProofStats', 'boostCampaignsByCourse'));
    }

    public function courseShow(string $slug): View
    {
        $courses = $this->catalogCourses();
        $course = $courses->firstWhere('slug', $slug);
        if ($course === null) abort(404);

        $socialProofSettings = $this->courseSocialProofSettings();
        if (Schema::hasTable('course_view_events')) {
            $visitorId = session()->get('course_view_visitor_id');
            if (!is_string($visitorId) || preg_match('/^[a-f0-9]{64}$/D', $visitorId) !== 1) {
                $visitorId = bin2hex(random_bytes(32));
                session()->put('course_view_visitor_id', $visitorId);
            }
            $visitorKey = hash_hmac('sha256', $visitorId, (string) config('app.key'));
            DB::table('course_view_events')->insertOrIgnore([
                'course_id' => $course->id,
                'visitor_hash' => $visitorKey,
                'viewed_on' => now()->toDateString(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $courseViews = Schema::hasTable('course_view_events')
            ? (int) DB::table('course_view_events')->where('course_id', $course->id)->count()
            : 0;
        $coursePurchases = $this->verifiedCoursePurchaseCount((int) $course->id);

        $lessons = collect();
        if (Schema::hasTable('course_lessons') && !empty($course->id)) {
            $lessons = DB::table('course_lessons')->where('course_id', $course->id)->orderBy('sort_order')->get();
        }

        $syllabus = [];
        if (!empty($course->syllabus_json)) {
            $decoded = is_string($course->syllabus_json) ? json_decode($course->syllabus_json, true) : $course->syllabus_json;
            if (is_array($decoded)) {
                $syllabus = $decoded;
            }
        }
        if (empty($syllabus) && $lessons->isNotEmpty()) {
            $syllabus = $lessons->pluck('title')->all();
        }

        $linkedPost = null;
        if (!empty($course->cms_post_id) && Schema::hasTable('cms_posts')) {
            $linkedPost = DB::table('cms_posts')->where('id', $course->cms_post_id)->first();
        }

        return view('public.course-show', compact('course', 'lessons', 'syllabus', 'linkedPost', 'socialProofSettings', 'courseViews', 'coursePurchases'));
    }


    private function courseSocialProofSettings(): array
    {
        $defaults = ['enabled' => true, 'show_views' => true, 'show_purchases' => true, 'show_on_cards' => true, 'show_on_details' => true];
        if (!Schema::hasTable('system_settings')) {
            return $defaults;
        }
        $raw = SystemSetting::query()->where('key', 'course.social_proof')->value('value');
        $stored = is_string($raw) ? json_decode($raw, true) : [];
        return array_merge($defaults, is_array($stored) ? $stored : []);
    }

    private function verifiedCoursePurchaseCount(int $courseId): int
    {
        if (!Schema::hasTable('enrollments')) {
            return 0;
        }
        $query = DB::table('enrollments')
            ->where('course_id', $courseId)
            ->whereIn('status', ['active', 'completed']);

        if (Schema::hasTable('fee_invoices')) {
            $query->whereNotNull('invoice_id')->whereExists(function ($invoiceQuery): void {
                        $invoiceQuery->selectRaw('1')
                            ->from('fee_invoices')
                            ->whereColumn('fee_invoices.id', 'enrollments.invoice_id')
                            ->where('fee_invoices.status', 'paid')
                            ->whereRaw('fee_invoices.paid_amount + 0.01 >= fee_invoices.net_amount');
            });
        } else {
            return 0;
        }

        return (int) $query->count();
    }

    public function checkCoupon(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'coupon_code' => ['required', 'string', 'max:60'],
            'price' => ['required', 'numeric', 'min:0'],
        ]);

        $code = strtoupper(trim($validated['coupon_code']));
        $price = (float) $validated['price'];

        if (!Schema::hasTable('promo_coupons')) {
            return response()->json(['valid' => false, 'message' => 'Coupon validation unavailable.'], 422);
        }

        $coupon = DB::table('promo_coupons')->where('code', $code)->first();
        if (!$coupon || !(bool) $coupon->is_active) {
            return response()->json(['valid' => false, 'message' => 'Invalid or inactive coupon code.'], 422);
        }

        if (!empty($coupon->expires_at) && strtotime((string) $coupon->expires_at) < strtotime(date('Y-m-d'))) {
            return response()->json(['valid' => false, 'message' => 'This coupon code has expired.'], 422);
        }

        if ((int) $coupon->max_uses > 0 && (int) $coupon->used_count >= (int) $coupon->max_uses) {
            return response()->json(['valid' => false, 'message' => 'Coupon redemption limit reached.'], 422);
        }

        $percent = max(0, min(100, (int) $coupon->discount_percent));
        $discountAmount = round($price * ($percent / 100), 2);
        $finalPrice = max(0, round($price - $discountAmount, 2));

        return response()->json([
            'valid' => true,
            'code' => $coupon->code,
            'discount_percent' => $percent,
            'discount_amount' => $discountAmount,
            'final_price' => $finalPrice,
            'message' => "{$coupon->code} applied ({$percent}% off).",
        ]);
    }

    public function learn(): View
    {
        $lessons = [];
        if (Schema::hasTable('cms_posts')) {
            // Use the SoftDeletes model scope so recycled posts never appear publicly.
            $posts = CmsPost::query()->where('is_published', true)->orderByDesc('id')->get();
            foreach ($posts as $post) {
                $lessons[] = [
                    'slug' => $post->slug,
                    'title' => $post->title,
                    'category' => $post->category ?: 'Article',
                    'duration' => !empty($post->pdf_path) ? 'PDF + Guide' : 'Article',
                    'instructor' => $post->author ?: "Alom's English Center",
                    'thumbnail' => $post->thumbnail_path ?: ($post->pdf_thumbnail ?: null),
                    'pdf_path' => $post->pdf_path ?? null,
                    'pdf_thumbnail' => $post->pdf_thumbnail ?? null,
                ];
            }
        }

        return view('public.learn', compact('lessons'));
    }

    public function learnShow(string $slug): View
    {
        abort_unless(Schema::hasTable('cms_posts'), 404);
        $post = CmsPost::query()
            ->where('slug', $slug)
            ->where('is_published', true)
            ->firstOrFail();

        return view('public.learn-show', compact('post'));
    }

    public function instructors(): View
    {
        $instructors = $this->catalogInstructors();

        return view('public.instructors', compact('instructors'));
    }

    public function instructorShow(string $id): View
    {
        $instructors = collect($this->catalogInstructors());
        $instructor = $instructors->firstWhere('id', (int) $id);
        if ($instructor === null) abort(404);
        $courses = $this->catalogCourses()->take(4);

        return view('public.instructor-show', compact('instructor', 'courses'));
    }

    public function exams(): View
    {
        $exams = Schema::hasTable('exams')
            ? DB::table('exams')->where('is_published', true)->orderByDesc('id')->get()
            : collect();

        return view('public.exams', compact('exams'));
    }

        public function products(): View
    {
        $products = $this->catalogProducts();

        return view('public.products', compact('products'));
    }

    public function productShow(string $slug): View
    {
        $products = collect($this->catalogProducts());
        $product = $products->firstWhere('slug', $slug);
        if ($product === null) abort(404);

        return view('public.product-show', compact('product'));
    }

    public function ebooks(Request $request): View
    {
        $allBooks = $this->catalogEbooks();
        $activeCategory = trim((string) $request->query('category', 'all'));
        $priceFilter = trim((string) $request->query('type', 'all'));

        $categories = $allBooks->pluck('category')->unique()->values();
        $catFiltered = $activeCategory === 'all' || $activeCategory === ''
            ? $allBooks
            : $allBooks->filter(fn ($b) => strcasecmp($b['category'], $activeCategory) === 0)->values();

        $freeCount = $catFiltered->where('is_free', true)->count();
        $premiumCount = $catFiltered->where('is_free', false)->count();

        $visibleBooks = match ($priceFilter) {
            'free' => $catFiltered->where('is_free', true)->values(),
            'premium' => $catFiltered->where('is_free', false)->values(),
            default => $catFiltered,
        };

        return view('public.ebooks', compact(
            'allBooks',
            'categories',
            'activeCategory',
            'priceFilter',
            'catFiltered',
            'freeCount',
            'premiumCount',
            'visibleBooks'
        ));
    }

    public function ebookShow(string $slug): View
    {
        $allBooks = $this->catalogEbooks();
        $ebook = $allBooks->firstWhere('slug', $slug);
        if ($ebook === null) abort(404);

        $hasAccess = (bool) $ebook['is_free'];
        if (!$hasAccess && Auth::check() && Schema::hasTable('storefront_item_access')) {
            $hasAccess = DB::table('storefront_item_access')
                ->where('storefront_item_id', $ebook['id'])
                ->where('user_id', Auth::id())
                ->exists();
        }

        $canPurchase = !empty($ebook['download_url']) && str_starts_with((string) $ebook['download_url'], 'private:');

        return view('public.ebook-show', compact('ebook', 'hasAccess', 'canPurchase'));
    }

    public function ebookDownload(string $slug)
    {
        abort_unless(app(PluginManager::class)->isEnabled('storefront-catalog') && Schema::hasTable('storefront_items'), 404);
        $item = DB::table('storefront_items')->where('slug', $slug)->where('type', 'ebook')->where('is_active', true)->first();
        abort_if($item === null || empty($item->download_url), 404);

        $isFree = (bool) $item->is_free || (float) $item->price <= 0;
        if (!$isFree) {
            abort_unless(Auth::check(), 403, 'Please sign in to access this purchased e-book.');
            abort_unless(
                Schema::hasTable('storefront_item_access')
                    && DB::table('storefront_item_access')->where('storefront_item_id', $item->id)->where('user_id', Auth::id())->exists(),
                403,
                'Payment verification is required before downloading this e-book.'
            );
        }

        $storedPath = (string) $item->download_url;
        if (str_starts_with($storedPath, 'private:')) {
            $path = str_replace('\\', '/', substr($storedPath, 8));
            $segments = explode('/', $path);
            abort_if(
                $path === ''
                    || str_starts_with($path, '/')
                    || preg_match('/^[A-Za-z]:/', $path) === 1
                    || str_contains($path, "\0")
                    || in_array('.', $segments, true)
                    || in_array('..', $segments, true),
                404,
                'Invalid private e-book path.'
            );

            $disk = Storage::disk('local');
            abort_unless($disk->exists($path), 404);
            $root = realpath($disk->path(''));
            $absolutePath = realpath($disk->path($path));
            abort_unless(
                $root !== false
                    && $absolutePath !== false
                    && is_file($absolutePath)
                    && str_starts_with($absolutePath, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR),
                404,
                'E-book file is outside private storage.'
            );

            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($absolutePath) ?: 'application/octet-stream';
            abort_unless($mime === 'application/pdf', 415, 'The protected e-book is not a PDF file.');

            $response = response()->download(
                $absolutePath,
                \Illuminate\Support\Str::slug($item->title) . '.pdf'
            );
            // BinaryFileResponse applies a public cache default during construction;
            // set privacy headers after creating the response so it cannot override them.
            $response->headers->set('Content-Type', 'application/pdf');
            $response->headers->set('X-Content-Type-Options', 'nosniff');
            $response->setPrivate();
            $response->headers->set('Cache-Control', 'private, no-store, no-cache, must-revalidate');
            $response->headers->set('Pragma', 'no-cache');
            $response->headers->set('Referrer-Policy', 'same-origin');

            return $response;
        }

        if ($isFree && filter_var($storedPath, FILTER_VALIDATE_URL)) {
            return redirect()->away($storedPath);
        }

        if ($isFree && str_starts_with($storedPath, '/uploads/')) {
            $absolute = public_path(ltrim($storedPath, '/'));
            abort_unless(is_file($absolute), 404);
            return response()->download($absolute, \Illuminate\Support\Str::slug($item->title) . '.pdf');
        }

        abort(403, 'This e-book does not have a protected downloadable file configured.');
    }

    public function result(Request $request): View
    {
        $queryCode = trim((string) $request->query('code', ''));
        $lookupResult = null;
        $lookupError = null;

        if ($queryCode !== '') {
            if (Schema::hasTable('certificates')) {
                $candidate = DB::table('certificates')
                    ->where('verification_code', $queryCode)
                    ->first();

                if ($candidate !== null && ($candidate->status ?? 'issued') === 'revoked') {
                    $lookupError = 'Certificate "' . $queryCode . '" has been revoked by Alom\'s English Center.';
                } else {
                    $lookupResult = $candidate;
                }
            }

            if ($lookupResult === null && $lookupError === null) {
                $lookupError = 'No verified certificate or board result matched "' . $queryCode . '". Please check your registration or verification ID.';
            }
        }

        return view('public.result', compact('queryCode', 'lookupResult', 'lookupError'));
    }

    public function aboutUs(): View
    {
        $cmsPage = $this->findPublishedCmsPage('about-us');
        $homepage = $this->loadHomepageConfig();

        return view('public.info-page', [
            'pageKey' => 'about',
            'title' => $cmsPage?->title ?? "About Alom's English Center",
            'eyebrow' => 'About Our Academy',
            'body' => $this->extractCmsPageBody($cmsPage) ?: "Alom's English Center (https://www.alomsenglishcenter.com) is an English education and IELTS coaching institution dedicated to structured fluency, academic grammar, and globally benchmarked English mastery. Founded to bridge classroom clarity with scalable online learning, our curriculum serves learners across Bangladesh and worldwide via web, Apple iOS, and Google Android apps.",
            'homepage' => $homepage,
        ]);
    }

    public function contactUs(): View
    {
        $cmsPage = $this->findPublishedCmsPage('contact-us');
        $homepage = $this->loadHomepageConfig();

        return view('public.info-page', [
            'pageKey' => 'contact',
            'title' => $cmsPage?->title ?? "Contact Alom's English Center",
            'eyebrow' => 'Get In Touch',
            'body' => $this->extractCmsPageBody($cmsPage) ?: "Reach out to our academic counselors for IELTS batch admission, Spoken English placement, corporate training, or mobile app support.",
            'homepage' => $homepage,
        ]);
    }

    public function submitContact(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:40'],
            'subject' => ['required', 'string', 'max:180'],
            'message' => ['required', 'string', 'max:2500'],
        ]);

        if (Schema::hasTable('contact_inquiries')) {
            DB::table('contact_inquiries')->insert([
                'name' => $validated['name'],
                'email' => strtolower($validated['email']),
                'phone' => $validated['phone'] ?? null,
                'subject' => $validated['subject'],
                'message' => $validated['message'],
                'status' => 'new',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return back()->with('status', 'Thank you! Your inquiry has been received by our admissions team.');
    }

    public function legalPage(string $slug): View
    {
        $cmsPage = $this->findPublishedCmsPage($slug);
        $homepage = $this->loadHomepageConfig();

        $defaults = [
            'terms-and-conditions' => [
                'title' => 'Terms & Conditions',
                'eyebrow' => 'Student Agreement',
                'body' => "Enrollment in any online or campus course, E-Book, or printed study kit at Alom's English Center grants personal, non-transferable learning access through https://www.alomsenglishcenter.com and our official iOS and Android apps. Sharing account credentials or redistributing protected PDFs violates platform licensing.",
            ],
            'privacy-policy' => [
                'title' => 'Privacy Policy',
                'eyebrow' => 'Data Protection',
                'body' => "Alom's English Center collects only the student profile, attendance, assessment, and billing details required to deliver instruction, issue verifiable certificates, and notify learners regarding scheduled batches. We never sell student personal information to third parties.",
            ],
            'refund-policy' => [
                'title' => 'Refund & Cancellation Policy',
                'eyebrow' => 'Transparent Policy',
                'body' => "Printed product and course enrollment refund requests may be submitted within 7 days of purchase by contacting info@alomsenglishcenter.com with your transaction invoice ID. Instant-download digital E-Books are non-refundable once accessed.",
            ],
        ];

        $meta = $defaults[$slug] ?? $defaults['terms-and-conditions'];

        return view('public.info-page', [
            'pageKey' => $slug,
            'title' => $cmsPage?->title ?? $meta['title'],
            'eyebrow' => $meta['eyebrow'],
            'body' => $this->extractCmsPageBody($cmsPage) ?: $meta['body'],
            'homepage' => $homepage,
        ]);
    }

    public function profile(): RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('portal.dashboard');
        }

        return redirect()->route('login');
    }

    private function findPublishedCmsPage(string $slug): ?object
    {
        if (!Schema::hasTable('cms_pages')) {
            return null;
        }

        return DB::table('cms_pages')
            ->where('slug', $slug)
            ->where('is_published', true)
            ->first();
    }

    private function extractCmsPageBody(?object $page): string
    {
        if ($page === null) {
            return '';
        }

        $blocks = json_decode((string) ($page->blocks_json ?? '[]'), true);

        return is_array($blocks) ? trim((string) ($blocks[0]['content'] ?? '')) : '';
    }

    private function activeBoostCampaigns(string $placement): Collection
    {
        if (!Schema::hasTable('boost_campaigns') || !app(PluginManager::class)->isEnabled('admin-boosting')) {
            return collect();
        }

        return BoostCampaign::query()
            ->where('placement', $placement)
            ->where('status', 'active')
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->orderByDesc('priority')
            ->orderBy('id')
            ->limit(100)
            ->get();
    }

    private function homepageBoostCampaigns(): Collection
    {
        return $this->activeBoostCampaigns('homepage_featured')
            ->filter(function (BoostCampaign $campaign): bool {
                if (in_array($campaign->entity_type, ['institution', 'offer'], true)) {
                    return $campaign->entity_id === null;
                }

                if ($campaign->entity_type === 'course' && $campaign->entity_id !== null && Schema::hasTable('courses')) {
                    return DB::table('courses')->where('id', $campaign->entity_id)
                        ->where('status', 'published')
                        ->when(Schema::hasColumn('courses', 'deleted_at'), fn ($query) => $query->whereNull('deleted_at'))
                        ->exists();
                }

                return false;
            })
            ->take(4)
            ->values();
    }

    private function catalogCourses(): Collection
    {
        if (!Schema::hasTable('courses')) return collect();

        $query = DB::table('courses')->where('status', 'published')->orderByDesc('id')->get();
        $catMap = Schema::hasTable('course_categories')
            ? DB::table('course_categories')->pluck('name', 'id')
            : collect();

        return $query->map(function ($course) use ($catMap) {
            $course->category = $course->category ?? ($catMap[$course->category_id ?? 0] ?? 'English Course');
            $course->duration_weeks = $course->duration_weeks ?? 12;
            $course->original_price = $course->original_price ?? 0;
            return $course;
        });
    }

        private function catalogInstructors(): array
    {
        if (!Schema::hasTable('teachers') || !Schema::hasTable('users')) return [];

        return DB::table('teachers')
            ->join('users', 'teachers.user_id', '=', 'users.id')
            ->where('teachers.is_active', true)
            ->orderBy('teachers.id')
            ->get([
                'teachers.id',
                'users.name',
                'teachers.specialization as subject',
                'teachers.bio as role',
                'teachers.credentials',
                'teachers.photo_path as avatar',
                'teachers.youtube_url',
                'teachers.facebook_url',
            ])
            ->map(fn ($row) => [
                'id' => $row->id,
                'name' => $row->name,
                'subject' => $row->subject ?: 'English Instructor',
                'role' => $row->role ?: "Faculty, Alom's English Center",
                'credentials' => $row->credentials,
                'avatar' => $row->avatar,
                'youtube_url' => $row->youtube_url,
                'facebook_url' => $row->facebook_url,
            ])->all();
    }

        private function catalogProducts(): array
    {
        if (!Schema::hasTable('storefront_items')) return [];

        return DB::table('storefront_items')
            ->where('type', 'product')
            ->where('is_active', true)
            ->orderByDesc('id')
            ->get()
            ->map(fn ($row) => [
                'slug' => $row->slug,
                'title' => $row->title,
                'category' => $row->category ?: 'Study Kit',
                'price' => (float) $row->price,
                'original_price' => (float) $row->original_price,
                'badge' => $row->badge,
                'summary' => $row->summary,
                'image' => $row->thumbnail_path ?: $row->pdf_thumbnail_path,
                'pdf_thumbnail_path' => $row->pdf_thumbnail_path,
                'download_url' => $row->download_url,
                'stock_count' => $row->stock_count,
            ])->all();
    }

        private function catalogEbooks(): Collection
    {
        if (!Schema::hasTable('storefront_items')) return collect();

        $gradients = [
            'from-[#0B5CD5] via-[#0284C7] to-[#032D42]',
            'from-[#065F46] via-[#0D9488] to-[#022C22]',
            'from-[#8B5CF6] via-[#C026D3] to-[#581C87]',
            'from-[#1E3A8A] via-[#4338CA] to-[#172554]',
        ];

        return DB::table('storefront_items')
            ->where('type', 'ebook')
            ->where('is_active', true)
            ->orderByDesc('id')
            ->get()
            ->values()
            ->map(function ($row, $index) use ($gradients) {
                return [
                    'id' => $row->id,
                    'slug' => $row->slug,
                    'title' => $row->title,
                    'category' => $row->category ?: 'Digital E-Book',
                    'summary' => $row->summary,
                    'price' => (float) $row->price,
                    'original_price' => (float) $row->original_price,
                    'is_free' => (bool) $row->is_free || (float) $row->price <= 0,
                    'pages' => $row->pages,
                    'gradient' => $gradients[$index % count($gradients)],
                    'thumbnail_path' => $row->thumbnail_path,
                    'pdf_thumbnail_path' => $row->pdf_thumbnail_path,
                    'download_url' => $row->download_url,
                    'badge' => $row->badge,
                    'topics' => [],
                ];
            });
    }

        private function loadDigitalCarouselItems(): Collection
    {
        if (!Schema::hasTable('storefront_items')) return collect();

        return DB::table('storefront_items')
            ->where('is_active', true)
            ->orderByDesc('is_carousel_featured')
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'type' => $row->type,
                'slug' => $row->slug,
                'title' => $row->title,
                'category' => $row->category,
                'badge' => $row->badge,
                'price' => (float) $row->price,
                'original_price' => (float) $row->original_price,
                'is_free' => (bool) $row->is_free || (float) $row->price <= 0,
                'image' => $row->pdf_thumbnail_path ?: $row->thumbnail_path,
                'url' => $row->type === 'ebook' ? route('public.ebooks.show', $row->slug) : route('public.products.show', $row->slug),
            ]);
    }

        private function loadHomepageConfig(): array
    {
        $defaults = [
            'batches_badge' => 'Online Course',
            'batches_title' => 'Online Batches are ongoing!',
            'batches_subtitle' => 'Book your seat now!',
            'batch_1_title' => 'IELTS Academic & GT',
            'batch_1_desc' => 'Band 7.5+ Target Preparation',
            'batch_1_url' => '/courses',
            'batch_2_title' => 'Spoken & Grammar Foundation',
            'batch_2_desc' => 'Basic to Pro Live Batches',
            'batch_2_url' => '/courses',
            'stats_badge' => 'Online Course',
            'stats_title' => 'See the stats!',
            'stats_subtitle' => 'The trust we are building!',
            'stats_mode' => 'auto',
            'paths_title' => 'Choose Your Learning Path',
            'paths_subtitle' => 'Discover diverse courses across multiple disciplines designed to help you excel in your academic and admission journey.',
            'learning_paths' => [
                ['title' => 'Academic Courses', 'desc' => 'Comprehensive academic preparation for all levels', 'bg' => '#D6E9FF', 'border' => '#BBD8FF', 'accent' => '#1D63FF', 'abbr' => 'AC', 'url' => '/courses', 'enabled' => true],
                ['title' => 'Admission Courses', 'desc' => 'Specialized courses for university & IELTS admissions', 'bg' => '#CEFCE0', 'border' => '#B2F5CD', 'accent' => '#00A647', 'abbr' => 'AD', 'url' => '/courses', 'enabled' => true],
                ['title' => 'Grammar & Logic', 'desc' => 'Advanced grammar concepts and problem solving', 'bg' => '#F0DEFF', 'border' => '#E1C2FF', 'accent' => '#9333EA', 'abbr' => 'GR', 'url' => '/ebooks', 'enabled' => true],
                ['title' => 'Spoken & Phonetics', 'desc' => 'Pronunciation clarity, fluency & speaking drills', 'bg' => '#FFE6C7', 'border' => '#FFD09B', 'accent' => '#F94E00', 'abbr' => 'SP', 'url' => '/learn', 'enabled' => true],
                ['title' => 'Technology & Skills', 'desc' => 'Modern writing, presentation & career skills', 'bg' => '#DDE6FF', 'border' => '#C4D2FF', 'accent' => '#4F46E5', 'abbr' => 'SK', 'url' => '/exams', 'enabled' => true],
                ['title' => 'Community', 'desc' => 'Join our supportive peer learning community', 'bg' => '#FFE0EE', 'border' => '#FFC4DE', 'accent' => '#EC0075', 'abbr' => 'CM', 'url' => '/instructors', 'enabled' => true],
            ],
            'carousel_title' => 'Digital Products & Study Guides',
            'carousel_subtitle' => "Browse published digital products and study guides from Alom's English Center.",
            'carousel_cta_text' => 'Explore Catalog',
            'reviews_title' => 'What Our Students Say',
            'reviews_subtitle' => "Feedbacks from students who have transformed their learning journey with Alom's English Center.",
            'app_banner_eyebrow' => 'iOS & Android Mobile Learning App',
            'app_banner_title' => "Take Alom's English Center Anywhere on Apple iOS & Android",
            'app_banner_subtitle' => 'Stream HD lectures, read downloaded PDF E-Books offline, join live Zoom classes, and track mock exam scores directly from your iPhone, iPad, or Android device.',
            'app_ios_url' => '',
            'app_android_url' => '',
            'footer_description' => "Alom's English Center is a structured online and campus learning platform providing IELTS, Spoken English, and Grammar programs delivered by certified educators nationwide.",
            'footer_license_no' => '',
            'footer_address' => '',
            'footer_phone' => '',
            'footer_email' => '',
            'footer_youtube_url' => '',
            'footer_facebook_url' => '',
            'footer_payment_strip' => false,
            'show_slider' => true,
            'show_batches' => false,
            'show_stats' => true,
            'show_learning_paths' => true,
            'show_digital_carousel' => true,
            'show_reviews' => false,
            'show_app_banner' => false,
        ];

        if (Schema::hasTable('system_settings')) {
            $raw = DB::table('system_settings')->where('key', 'homepage_customizer')->value('value');
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $defaults = array_merge($defaults, $decoded);
                }
            }
            $mobileRaw = DB::table('system_settings')->where('key', 'mobile_app_config')->value('value');
            if (is_string($mobileRaw) && $mobileRaw !== '') {
                $mob = json_decode($mobileRaw, true);
                if (is_array($mob)) {
                    $defaults['app_ios_url'] = $mob['ios_store_url'] ?? $defaults['app_ios_url'];
                    $defaults['app_android_url'] = $mob['android_store_url'] ?? $defaults['app_android_url'];
                }
            }
        }

        return $defaults;
    }
}
