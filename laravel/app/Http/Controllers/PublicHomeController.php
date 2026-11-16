<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class PublicHomeController extends Controller
{
    public function index(): View
    {
        $homepage = $this->loadHomepageConfig();

        $liveStats = [
            'students' => Schema::hasTable('students') ? DB::table('students')->count() : 0,
            'courses' => Schema::hasTable('courses') ? DB::table('courses')->where('status', 'published')->count() : 0,
            'teachers' => Schema::hasTable('teachers') ? DB::table('teachers')->count() : 0,
            'lessons' => Schema::hasTable('course_lessons') ? DB::table('course_lessons')->count() : 0,
        ];

        $stats = ($homepage['stats_mode'] ?? 'auto') === 'manual' ? [
            'students' => (int) ($homepage['stats_students_override'] ?? $liveStats['students']),
            'courses' => (int) ($homepage['stats_courses_override'] ?? $liveStats['courses']),
            'teachers' => (int) ($homepage['stats_teachers_override'] ?? $liveStats['teachers']),
            'lessons' => (int) ($homepage['stats_lessons_override'] ?? $liveStats['lessons']),
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
            'digitalCarouselItems'
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

        $blocks = json_decode((string) ($page->blocks_json ?? '[]'), true);
        $body = is_array($blocks) ? (string) ($blocks[0]['content'] ?? '') : '';

        return view('public.custom-page', compact('page', 'body'));
    }

    public function courses(Request $request): View
    {
        $activeCategory = trim((string) $request->query('category', 'all'));
        $courses = $this->catalogCourses();

        $categories = $courses->pluck('category')->filter()->unique()->values();
        $filteredCourses = $activeCategory === 'all' || $activeCategory === ''
            ? $courses
            : $courses->filter(fn ($c) => strcasecmp((string) ($c->category ?? ''), $activeCategory) === 0)->values();

        return view('public.courses', compact('courses', 'categories', 'filteredCourses', 'activeCategory'));
    }

    public function courseShow(string $slug): View
    {
        $courses = $this->catalogCourses();
        $course = $courses->firstWhere('slug', $slug);
        if ($course === null) abort(404);

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

        return view('public.course-show', compact('course', 'lessons', 'syllabus', 'linkedPost'));
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
            $posts = DB::table('cms_posts')->where('is_published', true)->orderByDesc('id')->get();
            foreach ($posts as $post) {
                $lessons[] = [
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

        return view('public.ebook-show', compact('ebook', 'hasAccess'));
    }

    public function ebookDownload(string $slug)
    {
        abort_unless(Schema::hasTable('storefront_items'), 404);
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
            $path = substr($storedPath, 8);
            abort_unless(Storage::disk('local')->exists($path), 404);
            return Storage::disk('local')->download($path, \Illuminate\Support\Str::slug($item->title) . '.pdf');
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
