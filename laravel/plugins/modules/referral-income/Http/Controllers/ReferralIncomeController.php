<?php

declare(strict_types=1);

namespace Plugins\Modules\ReferralIncome\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;
use Plugins\Modules\ReferralIncome\Services\ReferralIncomeService;

class ReferralIncomeController extends Controller
{
    public function __construct(private readonly ReferralIncomeService $referrals) {}

    public function dashboard(Request $request): View
    {
        $user = $request->user();
        abort_unless($user->hasRole('student') || $user->hasRole('teacher'), 403);
        $code = $this->referrals->createOrGetCode($user);
        $commissions = DB::table('referral_commissions')->where('referrer_user_id', $user->id)->latest('id')->paginate(20);
        $stats = DB::table('referral_commissions')->where('referrer_user_id', $user->id)
            ->selectRaw("SUM(CASE WHEN status IN ('approved','payout_requested','paid') THEN commission_amount ELSE 0 END) as earned")
            ->selectRaw("SUM(CASE WHEN status = 'pending' THEN commission_amount ELSE 0 END) as pending")
            ->selectRaw("SUM(CASE WHEN status = 'paid' THEN commission_amount ELSE 0 END) as paid")->first();
        $referralCount = DB::table('referral_attributions')->where('referrer_user_id', $user->id)->where('status', 'active')->count();
        $payouts = DB::table('referral_payout_requests')->where('user_id', $user->id)->latest('id')->limit(10)->get();
        return view('referral-income::dashboard', compact('code', 'commissions', 'stats', 'referralCount', 'payouts'));
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
        $rules = DB::table('referral_income_rules')->orderBy('referrer_type')->get();
        return view('referral-income::admin', compact('commissions', 'payouts', 'rules'));
    }

    public function reviewCommission(Request $request, int $commission): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', 'in:approved,rejected,reversed']]);
        DB::table('referral_commissions')->where('id', $commission)->where('status', 'pending')->update([
            'status' => $data['status'], 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'updated_at' => now(),
        ]);
        return back()->with('status', 'Commission review saved.');
    }

    public function reviewPayout(Request $request, int $payout): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', 'in:approved,rejected,paid'], 'admin_note' => ['nullable', 'string', 'max:1000']]);
        DB::transaction(function () use ($request, $payout, $data): void {
            $record = DB::table('referral_payout_requests')->where('id', $payout)->lockForUpdate()->first();
            abort_unless($record && $record->status === 'pending' || ($record && $record->status === 'approved' && $data['status'] === 'paid'), 404);
            DB::table('referral_payout_requests')->where('id', $payout)->update([
                'status' => $data['status'], 'admin_note' => $data['admin_note'] ?? null,
                'reviewed_by' => $request->user()->id, 'reviewed_at' => now(),
                'paid_at' => $data['status'] === 'paid' ? now() : null, 'updated_at' => now(),
            ]);
            if ($data['status'] === 'paid') {
                DB::table('referral_commissions')->where('payout_request_id', $payout)->where('status', 'payout_requested')
                    ->update(['status' => 'paid', 'paid_at' => now(), 'updated_at' => now()]);
            } elseif ($data['status'] === 'rejected') {
                DB::table('referral_commissions')->where('payout_request_id', $payout)->where('status', 'payout_requested')
                    ->update(['status' => 'approved', 'payout_request_id' => null, 'updated_at' => now()]);
            } elseif ($data['status'] === 'approved') {
                DB::table('referral_commissions')->where('payout_request_id', $payout)->where('status', 'payout_requested')
                    ->update(['status' => 'payout_approved', 'updated_at' => now()]);
            }
        });
        return back()->with('status', 'Payout review saved.');
    }
}