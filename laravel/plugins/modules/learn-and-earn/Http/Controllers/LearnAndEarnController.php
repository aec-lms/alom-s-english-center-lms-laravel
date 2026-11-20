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
        $this->ensureAssignedBranch($request->user());
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
        $this->ensureGlobalContentAccess($request->user());
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
        $this->ensureGlobalContentAccess($request->user());
        $values = $request->validate([
            'category_id' => ['nullable', 'integer', 'exists:learn_earn_categories,id'],
            'subject' => ['nullable', 'string', 'max:120'],
            'difficulty' => ['nullable', 'in:easy,medium,hard'],
            'question' => ['required', 'string', 'max:5000'],
            'options' => ['required', 'array', 'size:4'],
            'options.*' => ['required', 'string', 'max:500', 'distinct'],
            'correct_option_index' => ['required', 'integer', 'between:0,3'],
            'positive_marks' => ['required', 'numeric', 'gt:0', 'max:1000'],
            'negative_marks' => ['required', 'numeric', 'min:0', 'max:1000'],
            'explanation' => ['nullable', 'string', 'max:5000'],
        ]);

        $values['options'] = array_map(static fn ($option): string => trim((string) $option), $values['options']);
        abort_if(count(array_unique($values['options'])) !== 4, 422, 'Each MCQ must have four distinct options.');

        LearnEarnQuestion::create($values + [
            'created_by' => $request->user()->id,
            'subject' => $values['subject'] ?? null,
            'difficulty' => $values['difficulty'] ?? 'medium',
            'explanation' => $values['explanation'] ?? null,
            'is_active' => true,
        ]);

        return back()->with('status', 'MCQ saved.');
    }

    public function updateQuestion(Request $request, int $questionId): RedirectResponse
    {
        $this->ensureGlobalContentAccess($request->user());
        $question = LearnEarnQuestion::query()->findOrFail($questionId);
        $values = $request->validate([
            'category_id' => ['nullable', 'integer', 'exists:learn_earn_categories,id'],
            'subject' => ['nullable', 'string', 'max:120'],
            'difficulty' => ['required', 'in:easy,medium,hard'],
            'question' => ['required', 'string', 'max:5000'],
            'options' => ['required', 'array', 'size:4'],
            'options.*' => ['required', 'string', 'max:500', 'distinct'],
            'correct_option_index' => ['required', 'integer', 'between:0,3'],
            'positive_marks' => ['required', 'numeric', 'gt:0', 'max:1000'],
            'negative_marks' => ['required', 'numeric', 'min:0', 'max:1000'],
            'explanation' => ['nullable', 'string', 'max:5000'],
        ]);
        $values['options'] = array_map(static fn ($option): string => trim((string) $option), $values['options']);
        abort_if(count(array_unique($values['options'])) !== 4, 422, 'Each MCQ must have four distinct options.');

        $question->update($values);
        app(AuditLogger::class)->log('learn_earn.question_updated', 'learn_earn_question', (string) $question->id, [
            'snapshot_policy' => 'existing_exam_questions_unchanged',
        ], $request->user()->id);

        return back()->with('status', 'MCQ updated. Existing exam snapshots remain unchanged.');
    }

    public function toggleQuestion(Request $request, int $questionId): RedirectResponse
    {
        $this->ensureGlobalContentAccess($request->user());
        $values = $request->validate(['is_active' => ['required', 'boolean']]);
        $question = LearnEarnQuestion::query()->findOrFail($questionId);
        $question->update(['is_active' => (bool) $values['is_active']]);

        app(AuditLogger::class)->log('learn_earn.question_status_changed', 'learn_earn_question', (string) $question->id, [
            'is_active' => (bool) $question->is_active,
        ], $request->user()->id);

        return back()->with('status', $question->is_active ? 'MCQ restored to the active bank.' : 'MCQ archived from new exam selection.');
    }

    public function storeExam(Request $request): RedirectResponse
    {
        $this->ensureAssignedBranch($request->user());
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

        $branchId = $this->assignedBranchId($request->user());
        $exams = Exam::query()
            ->where('is_published', true)
            ->where(function ($query) use ($branchId): void {
                $query->whereNull('branch_id')->orWhere('branch_id', $branchId);
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
        $student = $request->user();
        abort_unless($student && $student->hasRole('student'), 403, 'Student account required.');
        $this->ensureExamIntegration();

        $values = $request->validate(['payer_contact' => ['required', 'string', 'max:120']]);
        abort_unless(filter_var((string) $student->email, FILTER_VALIDATE_EMAIL), 422, 'A valid account email is required for checkout.');

        abort_unless(
            app(PluginManager::class)->isEnabled('universal-payment')
                && app(PluginManager::class)->isEnabled('gateway-sslcommerz')
                && Schema::hasTable('universal_payment_transactions')
                && Schema::hasColumn('universal_payment_transactions', 'learn_earn_exam_id'),
            503,
            'Enable and configure Universal Payment and SSLCOMMERZ before purchasing paid exams.'
        );

        // Lock the exam row while checking access/pending sessions and creating
        // the transaction. This serializes concurrent checkout attempts for the
        // same exam and prevents duplicate provider charges.
        $transaction = DB::transaction(function () use ($request, $student, $examId, $values): UniversalPaymentTransaction {
            $exam = Exam::query()->whereKey($examId)->lockForUpdate()->firstOrFail();
            abort_unless($exam->is_published && ($exam->learn_earn_type ?? 'free') === 'paid' && (float) $exam->access_price > 0, 404);
            $this->assertStudentExamBranch($request, $exam);

            if (DB::table('learn_earn_exam_access')
                ->where('exam_id', $exam->id)
                ->where('user_id', $student->id)
                ->where('status', 'granted')
                ->exists()) {
                abort(409, 'You already have access to this exam.');
            }

            $pendingCheckout = UniversalPaymentTransaction::query()
                ->where('user_id', $student->id)
                ->where('learn_earn_exam_id', $exam->id)
                ->whereIn('status', ['pending', 'risk_review'])
                ->exists();
            abort_if($pendingCheckout, 409, 'A checkout for this exam is already pending or requires payment reconciliation. Contact support before retrying.');

            return UniversalPaymentTransaction::query()->create([
                'reference' => 'UPAY-' . strtoupper(Str::random(10)),
                'payer_name' => $student->name,
                'payer_contact' => $values['payer_contact'],
                'item_title' => $exam->title,
                'gateway' => 'sslcommerz',
                'currency' => app(PluginManager::class)->getSetting('universal-payment', 'default_currency', (string) config('lms.default_currency', 'BDT')) ?: 'BDT',
                'amount' => $exam->access_price,
                'status' => 'pending',
                'user_id' => $student->id,
                'learn_earn_exam_id' => $exam->id,
            ]);
        });

        try {
            $exam = Exam::query()->findOrFail($examId);
            return redirect()->away($gateway->initiateSslcommerz($transaction, $exam, $student, $values['payer_contact']));
        } catch (\Throwable $exception) {
            report($exception);
            $ambiguous = $exception instanceof \Illuminate\Http\Client\ConnectionException;
            DB::transaction(function () use ($transaction, $ambiguous): void {
                $locked = UniversalPaymentTransaction::query()->whereKey($transaction->id)->lockForUpdate()->first();
                if ($locked && $locked->status === 'pending') {
                    $locked->update([
                        'status' => $ambiguous ? 'risk_review' : 'failed',
                        'notes' => $ambiguous
                            ? 'SSLCOMMERZ session initialization outcome is unknown. Reconcile with the provider before allowing a retry.'
                            : 'SSLCOMMERZ checkout could not be initialized before a provider session was confirmed.',
                    ]);
                }
            });

            $message = $ambiguous
                ? 'The payment session outcome is unknown. Contact support before retrying; do not submit another payment.'
                : 'Unable to start the payment session. Check gateway configuration and try again.';
            return back()->withErrors(['payment' => $message]);
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
        $this->ensureAssignedBranch($request->user());
        $this->ensureExamIntegration();
        $exam = Exam::query()->findOrFail($examId);
        $values = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'payment_reference' => ['required', 'string', 'max:190'],
        ]);

        $reference = trim($values['payment_reference']);
        abort_if($reference === '', 422, 'A verified payment or bank/cash receipt reference is required.');
        $actor = $request->user();
        $targetUserId = (int) $values['user_id'];

        DB::transaction(function () use ($examId, $targetUserId, $reference, $actor): void {
            // Exam is the serialization mutex shared with attempt start/save/submit.
            $lockedExam = Exam::query()->whereKey($examId)->lockForUpdate()->firstOrFail();
            abort_unless(($lockedExam->learn_earn_type ?? 'free') === 'paid', 422, 'This is not a paid exam.');
            abort_unless(Schema::hasTable('learn_earn_exam_access'), 503, 'Paid exam access storage is unavailable.');

            $userIds = [$targetUserId];
            if ($actor && $actor->hasRole('branch_manager')) {
                $userIds[] = (int) $actor->id;
            }
            $userIds = array_values(array_unique($userIds));
            sort($userIds, SORT_NUMERIC);

            // Match StudentController's user-before-profile lock order.
            $lockedUsers = DB::table('users')
                ->whereIn('id', $userIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $target = $lockedUsers->get($targetUserId);
            abort_unless($target !== null, 404, 'The target student account no longer exists.');

            $profiles = collect();
            if (app(PluginManager::class)->isEnabled('student-management')
                && Schema::hasTable('students')
                && Schema::hasColumn('students', 'branch_id')) {
                $profileQuery = DB::table('students')->whereIn('user_id', $userIds);
                if (Schema::hasColumn('students', 'deleted_at')) {
                    $profileQuery->whereNull('deleted_at');
                }
                $profiles = $profileQuery->orderBy('user_id')->lockForUpdate()->get()->keyBy('user_id');
            }

            $targetProfile = $profiles->get($targetUserId);
            $targetBranchId = $targetProfile && $targetProfile->branch_id !== null
                ? (int) $targetProfile->branch_id
                : ($target->branch_id !== null ? (int) $target->branch_id : null);

            if ($actor && $actor->hasRole('branch_manager')) {
                $lockedActor = $lockedUsers->get((int) $actor->id);
                $branchId = $lockedActor && $lockedActor->branch_id !== null
                    ? (int) $lockedActor->branch_id
                    : 0;
                $branch = $branchId > 0 && Schema::hasTable('branches')
                    ? DB::table('branches')->where('id', $branchId)->lockForUpdate()->first()
                    : null;

                abort_unless($branchId > 0
                    && $branch !== null
                    && (bool) $branch->is_active
                    && (int) ($lockedExam->branch_id ?? 0) === $branchId
                    && $targetBranchId === $branchId,
                    403,
                    'Access can only be granted inside your assigned branch.'
                );
            }

            $existing = DB::table('learn_earn_exam_access')
                ->where('exam_id', $lockedExam->id)
                ->where('user_id', $targetUserId)
                ->lockForUpdate()
                ->first();

            $payload = [
                'status' => 'granted',
                'payment_reference' => $reference,
                'granted_at' => now(),
                'updated_at' => now(),
            ];

            if ($existing) {
                DB::table('learn_earn_exam_access')->where('id', $existing->id)->update($payload);
            } else {
                DB::table('learn_earn_exam_access')->insert($payload + [
                    'exam_id' => $lockedExam->id,
                    'user_id' => $targetUserId,
                    'created_at' => now(),
                ]);
            }
        });

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
            $branchId = $this->assignedBranchId($request->user());
            abort_unless($branchId !== null && $branchId === (int) $exam->branch_id, 403, 'Exam is outside your assigned branch.');
        }
    }

    private function assignedBranchId(object $user): ?int
    {
        if (app(PluginManager::class)->isEnabled('student-management')
            && Schema::hasTable('students')
            && Schema::hasColumn('students', 'branch_id')) {
            $profileBranchId = DB::table('students')->where('user_id', $user->id)->value('branch_id');
            if ($profileBranchId !== null) {
                return (int) $profileBranchId;
            }
        }

        return Schema::hasColumn('users', 'branch_id') && $user->branch_id !== null
            ? (int) $user->branch_id
            : null;
    }
    private function ensureGlobalContentAccess($actor): void
    {
        abort_unless(
            $actor && $actor->hasRole('admin'),
            403,
            'Only a central administrator can modify the shared Learn & Earn question bank.'
        );
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
