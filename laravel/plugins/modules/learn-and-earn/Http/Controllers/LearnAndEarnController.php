<?php

declare(strict_types=1);

namespace Plugins\Modules\LearnAndEarn\Http\Controllers;

use App\Core\Plugins\PluginManager;
use App\Core\Security\AuditLogger;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Plugins\Modules\ExamManagement\Http\Controllers\ExamController;
use Plugins\Modules\ExamManagement\Models\Exam;
use Plugins\Modules\ExamManagement\Models\ExamQuestion;
use Plugins\Modules\LearnAndEarn\Models\LearnEarnQuestion;
use Plugins\Modules\UniversalPayment\Models\UniversalPaymentTransaction;
use Plugins\Modules\UniversalPayment\Services\StorefrontPaymentGatewayService;

class LearnAndEarnController extends Controller
{
    public function index(Request $request): View
    {
        $examIntegrationEnabled = $this->examIntegrationEnabled();
        $courseIntegrationEnabled = $this->courseIntegrationEnabled();
        $actor = $request->user();

        $exams = $examIntegrationEnabled
            ? Exam::withCount('questions')
                ->when($actor?->hasRole('branch_manager'), fn ($query) => $query->where('branch_id', $actor->branch_id))
                ->latest()
                ->paginate(20)
            : collect();

        $courses = $courseIntegrationEnabled
            ? DB::table('courses')
                ->where('status', 'published')
                ->when($actor?->hasRole('branch_manager'), fn ($query) => $query->where('branch_id', $actor->branch_id))
                ->orderBy('title')
                ->get(['id', 'title', 'branch_id'])
            : collect();

        return view('learn-and-earn::index', [
            'categories' => DB::table('learn_earn_categories')->orderBy('name')->get(),
            'questions' => LearnEarnQuestion::with('category')->latest()->paginate(50),
            'exams' => $exams,
            'courses' => $courses,
            'examIntegrationEnabled' => $examIntegrationEnabled,
            'courseIntegrationEnabled' => $courseIntegrationEnabled,
        ]);
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        $values = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string'],
        ]);

        $base = Str::slug($values['name']);
        $slug = $base;
        $suffix = 1;
        while (DB::table('learn_earn_categories')->where('slug', $slug)->exists()) {
            $slug = $base . '-' . (++$suffix);
        }

        DB::table('learn_earn_categories')->insert([
            'name' => $values['name'],
            'slug' => $slug,
            'description' => $values['description'] ?? null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return back()->with('status', 'Category created.');
    }

    public function storeQuestion(Request $request): RedirectResponse
    {
        $values = $request->validate([
            'category_id' => ['nullable', 'integer', 'exists:learn_earn_categories,id'],
            'question' => ['required', 'string'],
            'options' => ['required', 'array', 'size:4'],
            'options.*' => ['required', 'string', 'max:500'],
            'correct_option_index' => ['required', 'integer', 'between:0,3'],
            'positive_marks' => ['required', 'numeric', 'gt:0', 'max:1000'],
            'negative_marks' => ['required', 'numeric', 'min:0', 'max:1000'],
        ]);

        LearnEarnQuestion::create($values + ['is_active' => true]);

        return back()->with('status', 'MCQ saved.');
    }

    public function storeExam(Request $request): RedirectResponse
    {
        $this->ensureExamIntegration();

        $values = $request->validate([
            'course_id' => ['nullable', 'integer'],
            'title' => ['required', 'string', 'max:180'],
            'question_ids' => ['required', 'array', 'min:1'],
            'question_ids.*' => ['required', 'integer', 'distinct', 'exists:learn_earn_questions,id'],
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:360'],
            'pass_marks' => ['required', 'numeric', 'min:0'],
            'type' => ['required', 'in:free,paid'],
            'access_price' => ['required_if:type,paid', 'numeric', 'gt:0'],
            'is_published' => ['nullable', 'boolean'],
        ]);

        $course = null;
        if (!empty($values['course_id'])) {
            $this->ensureCourseIntegration();
            $course = DB::table('courses')->where('id', $values['course_id'])->first();
            abort_unless($course, 422, 'Selected course unavailable.');
        }

        $actor = $request->user();
        $branchId = $course->branch_id ?? null;
        if ($actor?->hasRole('branch_manager')) {
            $branchId = (int) ($actor->branch_id ?? 0);
            abort_unless($branchId > 0, 403, 'Your account is not assigned to an active branch.');
            if ($course) {
                abort_unless((int) ($course->branch_id ?? 0) === $branchId, 403, 'Course is outside your assigned branch.');
            }
        }

        DB::transaction(function () use ($request, $values, $course, $branchId): void {
            $questions = LearnEarnQuestion::query()
                ->whereIn('id', $values['question_ids'])
                ->where('is_active', true)
                ->get();

            abort_if($questions->count() !== count($values['question_ids']), 422, 'Selected question unavailable.');

            $exam = Exam::query()->create([
                'course_id' => $course?->id,
                'branch_id' => $branchId,
                'title' => $values['title'],
                'duration_minutes' => $values['duration_minutes'],
                'pass_marks' => $values['pass_marks'],
                'is_published' => $request->boolean('is_published'),
                'learn_earn_type' => $values['type'],
                'access_price' => $values['type'] === 'paid' ? $values['access_price'] : 0,
            ]);

            foreach ($questions as $question) {
                ExamQuestion::query()->create([
                    'exam_id' => $exam->id,
                    'prompt' => $question->question,
                    'options' => $question->options,
                    'correct_option_index' => $question->correct_option_index,
                    'marks' => $question->positive_marks,
                    'negative_marks' => $question->negative_marks,
                ]);
            }
        });

        return back()->with('status', 'Exam created. Paid exams remain locked until verified payment access is granted.');
    }

    public function publicExams(Request $request): View
    {
        abort_unless($request->user() && $request->user()->hasRole('student'), 403, 'Student account required.');
        $this->ensureExamIntegration();

        $exams = Exam::query()
            ->where('is_published', true)
            ->where(function ($query) use ($request): void {
                $query->whereNull('branch_id')->orWhere('branch_id', $request->user()->branch_id);
            })
            ->latest()
            ->paginate(20);

        $accessibleExamIds = DB::table('learn_earn_exam_access')
            ->where('user_id', $request->user()->id)
            ->where('status', 'granted')
            ->pluck('exam_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $canPurchase = app(PluginManager::class)->isEnabled('universal-payment')
            && app(PluginManager::class)->isEnabled('gateway-sslcommerz')
            && Schema::hasTable('universal_payment_transactions')
            && Schema::hasColumn('universal_payment_transactions', 'learn_earn_exam_id');

        return view('learn-and-earn::exams', [
            'exams' => $exams,
            'canPurchase' => $canPurchase,
            'accessibleExamIds' => $accessibleExamIds,
            'currency' => strtoupper((string) config('lms.default_currency', 'BDT')),
        ]);
    }

    public function showExam(Request $request, int $examId): View
    {
        abort_unless($request->user() && $request->user()->hasRole('student'), 403, 'Student account required.');
        $this->ensureExamIntegration();

        $exam = Exam::query()->findOrFail($examId);
        abort_unless($exam->is_published, 404);
        $this->assertStudentExamBranch($request, $exam);

        if (($exam->learn_earn_type ?? 'free') === 'paid') {
            abort_unless(
                DB::table('learn_earn_exam_access')
                    ->where('exam_id', $exam->id)
                    ->where('user_id', $request->user()->id)
                    ->where('status', 'granted')
                    ->exists(),
                403,
                'Verified payment access is required for this exam.'
            );
        }

        $exam->load('questions');

        return view('learn-and-earn::take-exam', ['exam' => $exam]);
    }

    public function purchaseExam(Request $request, int $examId, StorefrontPaymentGatewayService $gateway): RedirectResponse
    {
        abort_unless($request->user() && $request->user()->hasRole('student'), 403, 'Student account required.');
        $this->ensureExamIntegration();

        $exam = Exam::query()->findOrFail($examId);
        abort_unless($exam->is_published && ($exam->learn_earn_type ?? 'free') === 'paid' && (float) $exam->access_price > 0, 404);
        $this->assertStudentExamBranch($request, $exam);

        abort_unless(
            app(PluginManager::class)->isEnabled('universal-payment')
                && app(PluginManager::class)->isEnabled('gateway-sslcommerz')
                && Schema::hasTable('universal_payment_transactions')
                && Schema::hasColumn('universal_payment_transactions', 'learn_earn_exam_id'),
            503,
            'Enable and configure Universal Payment and SSLCOMMERZ before purchasing paid exams.'
        );

        if (DB::table('learn_earn_exam_access')
            ->where('exam_id', $exam->id)
            ->where('user_id', $request->user()->id)
            ->where('status', 'granted')
            ->exists()) {
            return redirect('/learn-and-earn/exams/' . $exam->id)->with('status', 'You already have access to this exam.');
        }

        $pendingCheckout = UniversalPaymentTransaction::query()
            ->where('user_id', $request->user()->id)
            ->where('learn_earn_exam_id', $exam->id)
            ->where('status', 'pending')
            ->where('created_at', '>=', now()->subMinutes(30))
            ->exists();
        abort_if($pendingCheckout, 409, 'A checkout for this exam is already pending. Wait for its result or contact the administrator before retrying.');

        $values = $request->validate(['payer_contact' => ['required', 'string', 'max:120']]);
        abort_unless(filter_var((string) $request->user()->email, FILTER_VALIDATE_EMAIL), 422, 'A valid account email is required for checkout.');

        $transaction = UniversalPaymentTransaction::query()->create([
            'reference' => 'UPAY-' . strtoupper(Str::random(10)),
            'payer_name' => $request->user()->name,
            'payer_contact' => $values['payer_contact'],
            'item_title' => $exam->title,
            'gateway' => 'sslcommerz',
            'currency' => app(PluginManager::class)->getSetting('universal-payment', 'default_currency', (string) config('lms.default_currency', 'BDT')) ?: 'BDT',
            'amount' => $exam->access_price,
            'status' => 'pending',
            'user_id' => $request->user()->id,
            'learn_earn_exam_id' => $exam->id,
        ]);

        try {
            return redirect()->away($gateway->initiateSslcommerz($transaction, $exam, $request->user(), $values['payer_contact']));
        } catch (\Throwable $exception) {
            report($exception);
            return back()->withErrors(['payment' => 'Unable to start secure checkout. No exam access was granted.']);
        }
    }

    public function submitAttempt(Request $request, int $examId): RedirectResponse
    {
        $this->ensureExamIntegration();
        $exam = Exam::query()->findOrFail($examId);

        return app(ExamController::class)->submitAttempt($request, $exam);
    }

    public function grantAccess(Request $request, int $examId): RedirectResponse
    {
        $this->ensureExamIntegration();
        $exam = Exam::query()->findOrFail($examId);
        $values = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'payment_reference' => ['required', 'string', 'max:190'],
        ]);

        abort_unless(($exam->learn_earn_type ?? 'free') === 'paid', 422, 'This is not a paid exam.');
        $reference = trim($values['payment_reference']);
        abort_if($reference === '', 422, 'A verified payment or bank/cash receipt reference is required.');

        $target = DB::table('users')->where('id', $values['user_id'])->first();
        $actor = $request->user();
        if ($actor?->hasRole('branch_manager')) {
            $branchId = (int) ($actor->branch_id ?? 0);
            abort_unless($branchId > 0
                && (int) ($exam->branch_id ?? 0) === $branchId
                && (int) ($target->branch_id ?? 0) === $branchId,
                403,
                'Access can only be granted inside your assigned branch.'
            );
        }

        DB::table('learn_earn_exam_access')->updateOrInsert(
            ['exam_id' => $exam->id, 'user_id' => $values['user_id']],
            [
                'status' => 'granted',
                'payment_reference' => $reference,
                'granted_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        app(AuditLogger::class)->log('learn_earn.exam_access_manually_granted', 'learn_earn_exam_access', (string) $exam->id, [
            'user_id' => (int) $values['user_id'],
            'payment_reference' => $reference,
        ]);

        return back()->with('status', 'Manual exam access granted and recorded for audit.');
    }

    private function examIntegrationEnabled(): bool
    {
        return app(PluginManager::class)->isEnabled('exam-management')
            && Schema::hasTable('exams')
            && Schema::hasTable('exam_questions');
    }

    private function courseIntegrationEnabled(): bool
    {
        return app(PluginManager::class)->isEnabled('course-management')
            && Schema::hasTable('courses');
    }

    private function ensureExamIntegration(): void
    {
        abort_unless($this->examIntegrationEnabled(), 503, 'Enable the optional Exam Management plugin to deliver Learn & Earn exams.');
    }

    private function ensureCourseIntegration(): void
    {
        abort_unless($this->courseIntegrationEnabled(), 503, 'Enable the optional Course Management plugin to attach exams to courses.');
    }

    private function assertStudentExamBranch(Request $request, Exam $exam): void
    {
        if ($exam->branch_id !== null) {
            abort_unless((int) $request->user()->branch_id === (int) $exam->branch_id, 403, 'Exam is outside your assigned branch.');
        }
    }
}
