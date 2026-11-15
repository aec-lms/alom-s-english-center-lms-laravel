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
        $course = $courses->firstWhere('slug', $slug) ?? $courses->first();

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
        if (empty($syllabus)) {
            $syllabus = $lessons->isNotEmpty()
                ? $lessons->pluck('title')->all()
                : [
                    'Module 01: Core Foundation & Diagnostic Assessment',
                    'Module 02: Intensive Live Practice & Guided Worksheets',
                    'Module 03: Weekly Mock Evaluation & Examiner Feedback',
                    'Module 04: Final Readiness Mock & Certification',
                ];
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
                    'thumbnail' => $post->thumbnail_path ?: ($post->pdf_thumbnail ?: asset('assets/images/hero_educator_studio_1794552233836.jpg')),
                    'pdf_path' => $post->pdf_path ?? null,
                    'pdf_thumbnail' => $post->pdf_thumbnail ?? null,
                ];
            }
        }

        if (empty($lessons)) {
            $lessons = [
                [
                    'title' => 'How to Think in English Without Translating',
                    'category' => 'Spoken Fluency',
                    'duration' => '18:45',
                    'instructor' => 'Engr. Mahbubul Alom',
                    'thumbnail' => asset('assets/images/hero_educator_studio_1794552233836.jpg'),
                    'pdf_path' => null,
                    'pdf_thumbnail' => null,
                ],
                [
                    'title' => 'IELTS Writing Task 2: Band 8 Essay Blueprint',
                    'category' => 'IELTS Writing',
                    'duration' => '24:10',
                    'instructor' => 'Nusrat Jahan',
                    'thumbnail' => asset('assets/images/course_ielts_masterclass_1794552235026.jpg'),
                    'pdf_path' => null,
                    'pdf_thumbnail' => null,
                ],
                [
                    'title' => 'IPA Sound Clinic & Pronunciation Clarity',
                    'category' => 'Phonetics',
                    'duration' => '15:30',
                    'instructor' => 'Tanvir Ahmed',
                    'thumbnail' => asset('assets/images/course_spoken_fluency_1794552235255.jpg'),
                    'pdf_path' => null,
                    'pdf_thumbnail' => null,
                ],
                [
                    'title' => 'Complex Sentence Logic & Freehand Translation',
                    'category' => 'Grammar Lab',
                    'duration' => '21:15',
                    'instructor' => 'Rafiqul Islam',
                    'thumbnail' => asset('assets/images/course_grammar_bundle_1794552235221.jpg'),
                    'pdf_path' => null,
                    'pdf_thumbnail' => null,
                ],
            ];
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
        $instructor = $instructors->firstWhere('id', (int) $id) ?? $instructors->first();
        $courses = $this->catalogCourses()->take(4);

        return view('public.instructor-show', compact('instructor', 'courses'));
    }

    public function exams(): View
    {
        $exams = collect();
        if (Schema::hasTable('exams')) {
            $exams = DB::table('exams')->orderByDesc('id')->get();
        }

        if ($exams->isEmpty()) {
            $exams = collect([
                (object) ['id' => 1, 'title' => 'IELTS Academic Reading Full Mock Test 01', 'category' => 'IELTS Mock', 'duration_minutes' => 60, 'total_marks' => 40],
                (object) ['id' => 2, 'title' => 'Spoken English Fluency & Pronunciation Check', 'category' => 'Spoken', 'duration_minutes' => 25, 'total_marks' => 30],
                (object) ['id' => 3, 'title' => 'Grammar & Freehand Sentence Diagnostic', 'category' => 'Grammar Lab', 'duration_minutes' => 35, 'total_marks' => 50],
            ]);
        }

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
        $product = $products->firstWhere('slug', $slug) ?? $products->first();

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
        $ebook = $allBooks->firstWhere('slug', $slug) ?? $allBooks->first();

        return view('public.ebook-show', compact('ebook'));
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
            return redirect()->route('admin.dashboard');
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
        if (Schema::hasTable('courses')) {
            $query = DB::table('courses')->where('status', 'published')->orderByDesc('id')->get();
            if ($query->isNotEmpty()) {
                $catMap = Schema::hasTable('course_categories')
                    ? DB::table('course_categories')->pluck('name', 'id')
                    : collect();

                return $query->map(function ($c) use ($catMap) {
                    $c->category = $c->category ?? ($catMap[$c->category_id ?? 0] ?? 'English Course');
                    $c->duration_weeks = $c->duration_weeks ?? 12;
                    $c->original_price = $c->original_price ?? ((float) $c->price > 0 ? round((float) $c->price * 1.35) : 0);
                    return $c;
                });
            }
        }

        return collect([
            (object) [
                'id' => 1,
                'slug' => 'ielts-academic-gt-masterclass',
                'title' => 'IELTS Academic & GT Complete Masterclass (Band 7.5+ Target)',
                'category' => 'IELTS Preparation',
                'duration_weeks' => 12,
                'price' => 4500,
                'original_price' => 6500,
                'instructor_name' => 'Engr. Mahbubul Alom',
                'description' => 'Comprehensive 4-module IELTS batch covering Listening traps, Reading skimming, Writing Task 1 & 2, and live 1-on-1 Speaking mocks.',
                'thumbnail' => asset('assets/images/course_ielts_masterclass_1794552235026.jpg'),
                'pdf_path' => null,
                'pdf_thumbnail' => null,
                'syllabus_json' => json_encode([
                    'Week 1-3: Listening Speed & Distractor Traps',
                    'Week 4-6: Academic Reading Passage Mastery',
                    'Week 7-9: Writing Task 1 & 2 Band 8 Structure',
                    'Week 10-12: 1-on-1 Speaking Mock & Band Audit',
                ]),
            ],
            (object) [
                'id' => 2,
                'slug' => 'spoken-english-fluency-bootcamp',
                'title' => 'Spoken English & Real-Life Fluency Bootcamp',
                'category' => 'Spoken English',
                'duration_weeks' => 8,
                'price' => 2500,
                'original_price' => 4000,
                'instructor_name' => 'Tanvir Ahmed',
                'description' => 'Overcome speaking hesitation with IPA phonetics, daily partner speaking rooms, workplace scripts, and native collocations.',
                'thumbnail' => asset('assets/images/course_spoken_fluency_1794552235255.jpg'),
                'pdf_path' => null,
                'pdf_thumbnail' => null,
                'syllabus_json' => json_encode([
                    'Week 1-2: IPA Phonetics & Intonation Clarity',
                    'Week 3-4: Daily Conversation Blueprints',
                    'Week 5-6: Presentation, Debate & Workplace Fluency',
                    'Week 7-8: Final Speaking Assessment',
                ]),
            ],
            (object) [
                'id' => 3,
                'slug' => 'zero-to-pro-english-grammar',
                'title' => 'Zero-to-Pro English Grammar & Freehand Writing',
                'category' => 'Grammar Lab',
                'duration_weeks' => 10,
                'price' => 2200,
                'original_price' => 3500,
                'instructor_name' => 'Rafiqul Islam',
                'description' => 'Master clause synthesis, tense timelines, newspaper translation, and error-free academic paragraph composition.',
                'thumbnail' => asset('assets/images/course_grammar_bundle_1794552235221.jpg'),
                'pdf_path' => null,
                'pdf_thumbnail' => null,
                'syllabus_json' => json_encode([
                    'Module 1: Sentence Architecture & Clause Logic',
                    'Module 2: Tense Precision & Active/Passive Control',
                    'Module 3: Newspaper Translation & Vocabulary',
                    'Module 4: Academic Essay & Paragraph Polishing',
                ]),
            ],
        ]);
    }

    private function catalogInstructors(): array
    {
        if (Schema::hasTable('teachers') && Schema::hasTable('users')) {
            $rows = DB::table('teachers')
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
                ]);

            if ($rows->isNotEmpty()) {
                return $rows->map(fn ($r) => [
                    'id' => $r->id,
                    'name' => $r->name,
                    'subject' => $r->subject ?: 'English Instructor',
                    'role' => $r->role ?: "Senior Faculty, Alom's English Center",
                    'credentials' => $r->credentials ?: 'Certified English Mentor',
                    'avatar' => $r->avatar ?: asset('assets/images/hero_educator_studio_1794552233836.jpg'),
                    'youtube_url' => $r->youtube_url,
                    'facebook_url' => $r->facebook_url,
                ])->all();
            }
        }

        return [
            [
                'id' => 1,
                'name' => 'Engr. Mahbubul Alom',
                'subject' => 'IELTS & Method Architect',
                'role' => "Founder & Lead Mentor, Alom's English Center",
                'credentials' => 'IELTS Band 8.5 · 15+ Years Teaching Excellence',
                'avatar' => asset('assets/images/hero_educator_studio_1794552233836.jpg'),
                'youtube_url' => null,
                'facebook_url' => null,
            ],
            [
                'id' => 2,
                'name' => 'Tanvir Ahmed',
                'subject' => 'Spoken & Phonetics',
                'role' => 'Senior Fluency & Pronunciation Trainer',
                'credentials' => 'BA & MA in English, DU · CELTA Certified',
                'avatar' => asset('assets/images/instructor_tanvir_1794552825904.jpg'),
                'youtube_url' => null,
                'facebook_url' => null,
            ],
            [
                'id' => 3,
                'name' => 'Nusrat Jahan',
                'subject' => 'Academic Writing & Grammar',
                'role' => 'IELTS Writing Task 1 & 2 Specialist',
                'credentials' => 'TESOL Specialist · Band 8.0 Writing Mentor',
                'avatar' => asset('assets/images/instructor_nusrat_1794552825686.jpg'),
                'youtube_url' => null,
                'facebook_url' => null,
            ],
            [
                'id' => 4,
                'name' => 'Shamim Reza',
                'subject' => 'Business & Career English',
                'role' => 'Mock Evaluation & Career Skills Lead',
                'credentials' => 'Corporate Communication & Interview Coach',
                'avatar' => asset('assets/images/instructor_shamim_1794552825944.jpg'),
                'youtube_url' => null,
                'facebook_url' => null,
            ],
            [
                'id' => 5,
                'name' => 'Farhana Yasmin',
                'subject' => 'Reading & Listening',
                'role' => 'IELTS Academic Reading & Trap Strategy Lead',
                'credentials' => 'MA in Applied Linguistics · Band 8.5 Scorer',
                'avatar' => asset('assets/images/instructor_nusrat_1794552825686.jpg'),
                'youtube_url' => null,
                'facebook_url' => null,
            ],
            [
                'id' => 6,
                'name' => 'Rafiqul Islam',
                'subject' => 'Grammar & Freehand Writing',
                'role' => 'Senior Foundation Grammar & Sentence Craft Mentor',
                'credentials' => '12+ Years HSC, Admission & BCS English Specialist',
                'avatar' => asset('assets/images/instructor_tanvir_1794552825904.jpg'),
                'youtube_url' => null,
                'facebook_url' => null,
            ],
        ];
    }

    private function catalogProducts(): array
    {
        if (Schema::hasTable('storefront_items')) {
            $rows = DB::table('storefront_items')
                ->where('type', 'product')
                ->where('is_active', true)
                ->orderByDesc('id')
                ->get();

            if ($rows->isNotEmpty()) {
                return $rows->map(fn ($r) => [
                    'slug' => $r->slug,
                    'title' => $r->title,
                    'category' => $r->category ?: 'Study Kit',
                    'price' => (float) $r->price,
                    'original_price' => (float) $r->original_price,
                    'badge' => $r->badge ?: 'Official',
                    'summary' => $r->summary ?: 'Official study material by Alom\'s English Center.',
                    'image' => $r->thumbnail_path ?: ($r->pdf_thumbnail_path ?: asset('assets/images/product_ielts_books_kit_1794553908137.jpg')),
                    'pdf_thumbnail_path' => $r->pdf_thumbnail_path ?? null,
                    'download_url' => $r->download_url ?? null,
                    'stock_count' => $r->stock_count ?? 100,
                ])->all();
            }
        }

        return [
            [
                'slug' => 'complete-ielts-band-8-printed-books-kit',
                'title' => 'Complete IELTS Band 8+ Printed Books Kit (Set of 4)',
                'category' => 'Printed Books',
                'price' => 850,
                'original_price' => 1200,
                'badge' => 'Bestseller',
                'summary' => '4 printed spiral-bound volumes covering Listening, Reading, Writing Task 1/2, and Speaking Cue Cards.',
                'image' => asset('assets/images/product_ielts_books_kit_1794553908137.jpg'),
                'pdf_thumbnail_path' => null,
                'download_url' => null,
                'stock_count' => 150,
            ],
            [
                'slug' => 'spoken-english-collocations-flashcard-box',
                'title' => 'Spoken English & Collocations Flashcard Box',
                'category' => 'Study Kit',
                'price' => 490,
                'original_price' => 690,
                'badge' => 'Popular',
                'summary' => '300 color-coded pocket flashcards with phonetic transcription and daily speaking prompts.',
                'image' => asset('assets/images/product_spoken_flashcards_box_1794553909303.jpg'),
                'pdf_thumbnail_path' => null,
                'download_url' => null,
                'stock_count' => 200,
            ],
            [
                'slug' => 'academy-study-desk-flask-planner-combo',
                'title' => 'Academy Study Desk Flask & Planner Combo',
                'category' => 'Merchandise',
                'price' => 650,
                'original_price' => 900,
                'badge' => 'Limited',
                'summary' => 'Insulated matte stainless flask paired with a 90-day IELTS daily target & vocabulary tracker notebook.',
                'image' => asset('assets/images/product_academy_flask_combo_1794553909652.jpg'),
                'pdf_thumbnail_path' => null,
                'download_url' => null,
                'stock_count' => 80,
            ],
        ];
    }

    private function catalogEbooks(): Collection
    {
        if (Schema::hasTable('storefront_items')) {
            $rows = DB::table('storefront_items')
                ->where('type', 'ebook')
                ->where('is_active', true)
                ->orderByDesc('id')
                ->get();

            if ($rows->isNotEmpty()) {
                $gradients = [
                    'from-[#0B5CD5] via-[#0284C7] to-[#032D42]',
                    'from-[#065F46] via-[#0D9488] to-[#022C22]',
                    'from-[#8B5CF6] via-[#C026D3] to-[#581C87]',
                    'from-[#1E3A8A] via-[#4338CA] to-[#172554]',
                ];

                return $rows->values()->map(function ($r, $i) use ($gradients) {
                    return [
                        'slug' => $r->slug,
                        'title' => $r->title,
                        'category' => $r->category ?: 'Digital E-Book',
                        'summary' => $r->summary ?: "Verified ShortNote PDF study guide by Alom's English Center.",
                        'price' => (float) $r->price,
                        'original_price' => (float) $r->original_price,
                        'is_free' => (bool) $r->is_free || (float) $r->price <= 0,
                        'pages' => (int) ($r->pages ?: 64),
                        'gradient' => $gradients[$i % count($gradients)],
                        'thumbnail_path' => $r->thumbnail_path,
                        'pdf_thumbnail_path' => $r->pdf_thumbnail_path,
                        'download_url' => $r->download_url,
                        'badge' => $r->badge ?: ((bool) $r->is_free ? 'FREE' : 'ShortNote 2.0'),
                        'topics' => [
                            'Structured Chapter Breakdown & Examples',
                            'Printable / Offline PDF Mobile Reader Support',
                            "Curated by Alom's English Center Faculty",
                        ],
                    ];
                });
            }
        }

        return collect([
            [
                'slug' => 'ielts-writing-task-2-band-8-blueprint',
                'title' => 'IELTS Writing Task 2 Band 8.0 Blueprint',
                'category' => 'IELTS Writing',
                'summary' => 'Complete essay structures, linking frameworks, and 15 model Band 8+ essays with examiner commentary.',
                'price' => 0,
                'original_price' => 0,
                'is_free' => true,
                'pages' => 64,
                'gradient' => 'from-[#0B5CD5] via-[#0284C7] to-[#032D42]',
                'thumbnail_path' => null,
                'pdf_thumbnail_path' => null,
                'download_url' => null,
                'badge' => 'FREE',
                'topics' => [
                    'Opinion & Discussion Essay Templates',
                    'Cohesion & Lexical Resource Booster',
                    'Common Band-Lowering Mistakes',
                ],
            ],
            [
                'slug' => 'spoken-english-daily-fluency-collocations',
                'title' => 'Spoken English Daily Fluency & Collocations',
                'category' => 'Spoken English',
                'summary' => '400+ practical conversation dialogues, native idioms, and workplace speaking scripts for instant fluency.',
                'price' => 0,
                'original_price' => 0,
                'is_free' => true,
                'pages' => 52,
                'gradient' => 'from-[#065F46] via-[#0D9488] to-[#022C22]',
                'thumbnail_path' => null,
                'pdf_thumbnail_path' => null,
                'download_url' => null,
                'badge' => 'FREE',
                'topics' => [
                    'Natural Greetings & Small Talk',
                    'Interview & Presentation Phrases',
                    'Pronunciation Stress Rules',
                ],
            ],
            [
                'slug' => 'zero-to-pro-english-grammar-handbook',
                'title' => 'Zero-to-Pro English Grammar Handbook',
                'category' => 'Grammar Lab',
                'summary' => 'Visual charts for all 12 tenses, conditional clauses, modifiers, and free-hand translation mastery.',
                'price' => 99,
                'original_price' => 199,
                'is_free' => false,
                'pages' => 112,
                'gradient' => 'from-[#8B5CF6] via-[#C026D3] to-[#581C87]',
                'thumbnail_path' => null,
                'pdf_thumbnail_path' => null,
                'download_url' => null,
                'badge' => 'ShortNote 2.0',
                'topics' => [
                    'Tense Timeline Matrix',
                    'Active-Passive Transformation',
                    '100+ Error Correction Exercises',
                ],
            ],
            [
                'slug' => 'academic-reading-vocabulary-vault',
                'title' => '1000+ Academic Reading & Vocabulary Vault',
                'category' => 'Vocabulary',
                'summary' => 'High-frequency IELTS reading synonyms, paraphrasing tables, and contextual flashcards.',
                'price' => 129,
                'original_price' => 249,
                'is_free' => false,
                'pages' => 96,
                'gradient' => 'from-[#1E3A8A] via-[#4338CA] to-[#172554]',
                'thumbnail_path' => null,
                'pdf_thumbnail_path' => null,
                'download_url' => null,
                'badge' => 'ShortNote 2.0',
                'topics' => [
                    'True/False/Not Given Strategy',
                    'Heading Match Shortcuts',
                    '800 Academic Word Families',
                ],
            ],
        ]);
    }

    private function loadDigitalCarouselItems(): Collection
    {
        if (Schema::hasTable('storefront_items')) {
            $rows = DB::table('storefront_items')
                ->where('is_active', true)
                ->orderByDesc('is_carousel_featured')
                ->orderByDesc('id')
                ->limit(10)
                ->get();

            if ($rows->isNotEmpty()) {
                return $rows->map(fn ($r) => [
                    'type' => $r->type,
                    'slug' => $r->slug,
                    'title' => $r->title,
                    'category' => $r->category ?: ($r->type === 'ebook' ? 'PDF E-Book' : 'Study Kit'),
                    'badge' => $r->badge ?: ((bool) $r->is_free ? 'FREE PDF' : 'DIGITAL'),
                    'price' => (float) $r->price,
                    'original_price' => (float) $r->original_price,
                    'is_free' => (bool) $r->is_free || (float) $r->price <= 0,
                    'image' => $r->pdf_thumbnail_path ?: ($r->thumbnail_path ?: asset('assets/images/product_ielts_books_kit_1794553908137.jpg')),
                    'url' => $r->type === 'ebook' ? route('public.ebooks.show', $r->slug) : route('public.products.show', $r->slug),
                ]);
            }
        }

        return $this->catalogEbooks()->map(fn ($eb) => [
            'type' => 'ebook',
            'slug' => $eb['slug'],
            'title' => $eb['title'],
            'category' => $eb['category'],
            'badge' => $eb['badge'],
            'price' => $eb['price'],
            'original_price' => $eb['original_price'],
            'is_free' => $eb['is_free'],
            'image' => $eb['pdf_thumbnail_path'] ?: ($eb['thumbnail_path'] ?: asset('assets/images/course_grammar_bundle_1794552235221.jpg')),
            'url' => route('public.ebooks.show', $eb['slug']),
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
            'carousel_subtitle' => "Explore ShortNote 2.0 E-Books, printed handbooks, and IELTS practice kits crafted by Alom's English Center.",
            'carousel_cta_text' => 'Explore Catalog',
            'reviews_title' => 'What Our Students Say',
            'reviews_subtitle' => "Feedbacks from students who have transformed their learning journey with Alom's English Center.",
            'app_banner_eyebrow' => 'iOS & Android Mobile Learning App',
            'app_banner_title' => "Take Alom's English Center Anywhere on Apple iOS & Android",
            'app_banner_subtitle' => 'Stream HD lectures, read downloaded PDF E-Books offline, join live Zoom classes, and track mock exam scores directly from your iPhone, iPad, or Android device.',
            'app_ios_url' => 'https://apps.apple.com/app/aloms-english-center',
            'app_android_url' => 'https://play.google.com/store/apps/details?id=com.alomsenglishcenter.app',
            'footer_description' => "Alom's English Center is a structured online and campus learning platform providing IELTS, Spoken English, and Grammar programs delivered by certified educators nationwide.",
            'footer_license_no' => 'TRAD/DNCC/044200/2026',
            'footer_address' => 'Plot 14, Sector 6, Mirpur 10 Roundabout, Dhaka-1216',
            'footer_phone' => '+880 1712 345678',
            'footer_email' => 'info@alomsenglishcenter.com',
            'footer_youtube_url' => 'https://www.youtube.com/@alomsenglishcenter',
            'footer_facebook_url' => 'https://www.facebook.com/alomsenglishcenter',
            'footer_payment_strip' => true,
            'show_slider' => true,
            'show_batches' => true,
            'show_stats' => true,
            'show_learning_paths' => true,
            'show_digital_carousel' => true,
            'show_reviews' => true,
            'show_app_banner' => true,
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
