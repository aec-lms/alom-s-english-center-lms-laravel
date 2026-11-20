<?php

declare(strict_types=1);

namespace Plugins\Modules\ReferralIncome\Http\Controllers;

use App\Core\Security\AuditLogger;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;
use Plugins\Modules\ReferralIncome\Services\ReferralIncomeService;

class ReferralIncomeController extends Controller
{
    public function __construct(private readonly ReferralIncomeService $referrals, private readonly AuditLogger $audit) {}

    public function track(string $code): RedirectResponse
    {
        $valid = DB::table('referral_codes')->where('code', strtoupper($code))->where('is_active', true)->exists();
        if (!$valid) return redirect('/register')->withErrors(['referral_code' => 'This referral link is invalid or inactive.']);
        session(['referral_income_code' => strtoupper($code)]);
        return redirect('/register')->with('status', 'Referral link saved. Complete registration, then sign in to apply it.');
    }

    public function dashboard(Request $request): View
    {
        $user = $request->user();
        abort_unless($user->hasRole('student') || $user->hasRole('teacher'), 403);
        $code = $this->referrals->createOrGetCode($user);
        $pendingReferralCode = $request->session()->pull('referral_income_code');
        if ($pendingReferralCode) {
            try {
                $this->referrals->applyCode($user, $pendingReferralCode);
            } catch (\Illuminate\Validation\ValidationException $exception) {
                // Existing attribution, self-referral or an ineligible account: keep the account safe.
            }
        }
        $referralUrl = url('/r/' . $code);
        $commissions = DB::table('referral_commissions')->where('referrer_user_id', $user->id)->latest('id')->paginate(20);
        $statsByCurrency = DB::table('referral_commissions')->where('referrer_user_id', $user->id)
            ->select('currency')
            ->selectRaw("SUM(CASE WHEN status = 'pending' THEN commission_amount ELSE 0 END) as pending")
            ->selectRaw("SUM(CASE WHEN status IN ('approved','payout_requested','payout_approved','paid') THEN commission_amount ELSE 0 END) as earned")
            ->selectRaw("SUM(CASE WHEN status = 'paid' THEN commission_amount ELSE 0 END) as paid")
            ->selectRaw("SUM(CASE WHEN status = 'approved' THEN commission_amount ELSE 0 END) as available")
            ->groupBy('currency')->orderBy('currency')->get();
        $referralCount = DB::table('referral_attributions')->where('referrer_user_id', $user->id)->where('status', 'active')->count();
        $payouts = DB::table('referral_payout_requests')->where('user_id', $user->id)->latest('id')->limit(10)->get();
        return view('referral-income::dashboard', compact('code', 'referralUrl', 'commissions', 'statsByCurrency', 'referralCount', 'payouts'));
    }

    public function apply(Request $request): RedirectResponse
    {
        $data = $request->validate(['referral_code' => ['required', 'string', 'max:32']]);
        $this->referrals->applyCode($request->user(), $data['referral_code']);
        return back()->with('status', 'Referral code applied successfully.');
    }

    public function requestPayout(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'method' => ['required', 'in:bkash,nagad,bank'],
            'account_details' => ['required', 'string', 'min:6', 'max:500'],
        ]);
        $this->referrals->requestPayout($request->user(), $data['method'], $data['account_details']);
        return back()->with('status', 'Payout request submitted for admin review.');
    }

    public function adminIndex(): View
    {
        $commissions = DB::table('referral_commissions')->join('users', 'users.id', '=', 'referral_commissions.referrer_user_id')
            ->select('referral_commissions.*', 'users.name as referrer_name')->latest('referral_commissions.id')->paginate(30, ['*'], 'commissions_page');
        $payouts = DB::table('referral_payout_requests')->join('users', 'users.id', '=', 'referral_payout_requests.user_id')
            ->select('referral_payout_requests.*', 'users.name as owner_name')->latest('referral_payout_requests.id')->paginate(30, ['*'], 'payouts_page');
        $payouts->getCollection()->transform(function ($payout) {
            try {
                $payout->account_details = Crypt::decryptString($payout->account_details_encrypted);
            } catch (\Throwable $exception) {
                $payout->account_details = '[Unable to decrypt payout destination]';
            }
            return $payout;
        });
        $rules = DB::table('referral_income_rules')->orderBy('referrer_type')->get();
        $recoveries = Schema::hasTable('referral_commission_reversals')
            ? DB::table('referral_commission_reversals as reversals')
                ->join('referral_commissions as commissions', 'commissions.id', '=', 'reversals.referral_commission_id')
                ->join('users', 'users.id', '=', 'commissions.referrer_user_id')
                ->select(
                    'reversals.*',
                    'commissions.source_id',
                    'users.name as referrer_name',
                    'reversals.refunded_amount as refund_amount'
                )
                ->latest('reversals.id')
                ->paginate(30, ['*'], 'recoveries_page')
            : new \Illuminate\Pagination\LengthAwarePaginator([], 0, 30);
        return view('referral-income::admin', compact('commissions', 'payouts', 'rules', 'recoveries'));
    }

    private function assertAuditAvailable(): void
    {
        abort_unless(Schema::hasTable('audit_logs'), 503, 'Audit logging is unavailable; no referral finance changes were applied.');
    }

    public function updateRules(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'student_commission_percent' => ['required', 'numeric', 'min:0', 'max:50'],
            'teacher_commission_percent' => ['required', 'numeric', 'min:0', 'max:50'],
            'student_minimum_payout' => ['required', 'numeric', 'min:1', 'max:10000000'],
            'teacher_minimum_payout' => ['required', 'numeric', 'min:1', 'max:10000000'],
        ]);

        DB::transaction(function () use ($request, $data): void {
            $this->assertAuditAvailable();

            foreach (['student', 'teacher'] as $type) {
                $rule = DB::table('referral_income_rules')
                    ->where('referrer_type', $type)
                    ->lockForUpdate()
                    ->first();
                abort_unless($rule, 503, 'Referral commission rules are unavailable; no changes were applied.');

                $before = [
                    'commission_percent' => (string) $rule->commission_percent,
                    'minimum_payout' => (string) $rule->minimum_payout,
                ];
                $after = [
                    'commission_percent' => (string) $data[$type . '_commission_percent'],
                    'minimum_payout' => (string) $data[$type . '_minimum_payout'],
                ];

                DB::table('referral_income_rules')->where('referrer_type', $type)->update([
                    'commission_percent' => $data[$type . '_commission_percent'],
                    'minimum_payout' => $data[$type . '_minimum_payout'],
                    'updated_at' => now(),
                ]);

                $this->audit->log('referral_income.rules_updated', 'referral_income_rule', $type, [
                    'before' => $before,
                    'after' => $after,
                ], (int) $request->user()->id);
            }
        });

        return back()->with('status', 'Referral commission rules updated.');
    }

    public function reviewCommission(Request $request, int $commission): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:approved,rejected'],
            'admin_note' => ['required_if:status,rejected', 'nullable', 'string', 'max:1000'],
        ]);

        DB::transaction(function () use ($request, $commission, $data): void {
            $this->assertAuditAvailable();
            $record = DB::table('referral_commissions')->where('id', $commission)->lockForUpdate()->first();
            abort_unless($record !== null && $record->status === 'pending', 422, 'Only pending commissions can be reviewed.');
            abort_unless(
                $data['status'] !== 'approved' || $record->source_type !== 'course_cart_order',
                409,
                'Course-cart commissions cannot be approved until a verified Universal Payment refund adapter is available.'
            );

            DB::table('referral_commissions')->where('id', $commission)->update([
                'status' => $data['status'],
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'updated_at' => now(),
            ]);

            $this->audit->log('referral_income.commission_reviewed', 'referral_commission', (string) $commission, [
                'from' => 'pending',
                'to' => $data['status'],
                'reason' => isset($data['admin_note']) ? trim($data['admin_note']) : null,
            ], (int) $request->user()->id);
        });

        return back()->with('status', 'Commission review saved.');
    }

    public function reviewRecovery(Request $request, int $reversal): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:recovered,waived'],
            'admin_note' => ['required', 'string', 'max:1000'],
        ]);

        DB::transaction(function () use ($request, $reversal, $data): void {
            $this->assertAuditAvailable();
            $record = DB::table('referral_commission_reversals')
                ->where('id', $reversal)
                ->lockForUpdate()
                ->first();

            abort_unless($record !== null && $record->status === 'recovery_due', 422, 'Only an outstanding recovery can be reconciled.');
            abort_unless($data['status'] !== 'waived' || trim((string) ($data['admin_note'] ?? '')) !== '', 422, 'A reason is required to waive a recovery.');

            DB::table('referral_commission_reversals')->where('id', $reversal)->update([
                'status' => $data['status'],
                'admin_note' => isset($data['admin_note']) ? trim($data['admin_note']) : null,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'updated_at' => now(),
            ]);

            $this->audit->log('referral_income.recovery_reviewed', 'referral_commission_reversal', (string) $reversal, [
                'from' => 'recovery_due',
                'to' => $data['status'],
                'reason' => isset($data['admin_note']) ? trim($data['admin_note']) : null,
            ], (int) $request->user()->id);
        });

        return back()->with('status', 'Referral recovery reconciliation saved.');
    }

    public function reviewPayout(Request $request, int $payout): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', 'in:approved,rejected,paid'], 'admin_note' => ['required_if:status,rejected,paid', 'nullable', 'string', 'max:1000']]);
        DB::transaction(function () use ($request, $payout, $data): void {
            $this->assertAuditAvailable();
            $record = DB::table('referral_payout_requests')->where('id', $payout)->lockForUpdate()->first();
            $validTransition = $record !== null
                && (($record->status === 'pending' && in_array($data['status'], ['approved', 'rejected'], true))
                    || ($record->status === 'approved' && in_array($data['status'], ['paid', 'rejected'], true)));
            abort_unless($validTransition, 422, 'Invalid payout status transition.');

            $commissions = DB::table('referral_commissions')
                ->where('payout_request_id', $payout)
                ->lockForUpdate()->get(['id', 'source_type']);
            if (in_array($data['status'], ['approved', 'paid'], true)
                && $commissions->contains(fn ($commission): bool => $commission->source_type === 'course_cart_order')) {
                abort(422, 'This payout contains course-cart commissions awaiting a verified Universal Payment refund adapter.');
            }
            if (in_array($data['status'], ['approved', 'paid'], true)
                && Schema::hasTable('referral_commission_reversals')
                && DB::table('referral_commission_reversals')
                    ->whereIn('referral_commission_id', $commissions->pluck('id'))
                    ->where('status', 'recovery_due')->exists()) {
                abort(422, 'This payout contains refunded commissions. Reconcile the recovery before approving or paying it.');
            }

            DB::table('referral_payout_requests')->where('id', $payout)->update([
                'status' => $data['status'], 'admin_note' => $data['admin_note'] ?? null,
                'reviewed_by' => $request->user()->id, 'reviewed_at' => now(),
                'paid_at' => $data['status'] === 'paid' ? now() : null, 'updated_at' => now(),
            ]);
            if ($data['status'] === 'paid') {
                DB::table('referral_commissions')->where('payout_request_id', $payout)->whereIn('status', ['payout_requested', 'payout_approved'])
                    ->update(['status' => 'paid', 'paid_at' => now(), 'updated_at' => now()]);
            } elseif ($data['status'] === 'rejected') {
                DB::table('referral_commissions')->where('payout_request_id', $payout)->whereIn('status', ['payout_requested', 'payout_approved'])
                    ->update(['status' => 'approved', 'payout_request_id' => null, 'updated_at' => now()]);
            } elseif ($data['status'] === 'approved') {
                DB::table('referral_commissions')->where('payout_request_id', $payout)->where('status', 'payout_requested')
                    ->update(['status' => 'payout_approved', 'updated_at' => now()]);
            }

            $this->audit->log('referral_income.payout_reviewed', 'referral_payout_request', (string) $payout, [
                'from' => $record->status,
                'to' => $data['status'],
                'amount' => (string) $record->amount,
                'currency' => (string) $record->currency,
                'reason' => isset($data['admin_note']) ? trim($data['admin_note']) : null,
            ], (int) $request->user()->id);
        });
        return back()->with('status', 'Payout review saved.');
    }
}