import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { useLms } from '../../context/LmsContext';
import { apiClient } from '../../services/apiClient';

type Summary = {
  balance: { gross_commission: number; paid_commission: number; reserved_payout: number; available_balance: number };
  courses: Array<{ id: number; course_id: number; course_title: string; teacher_user_id: number; teacher_name: string; commission_rate: number; verified_payment_count: number; net_course_revenue: number; gross_commission: number }>;
};
type Payout = {
  id: number; teacher_user_id: number; teacher_name?: string; amount: number; payout_method: 'bKash' | 'Nagad' | 'Bank Transfer';
  account_number: string; bank_name?: string; bank_branch?: string; status: 'pending' | 'approved' | 'rejected' | 'completed';
  transaction_reference?: string | null; remarks?: string; created_at: string; completed_at?: string | null;
};
type Rule = { id: number; course_id: number; course_title: string; teacher_user_id: number; teacher_name: string; commission_rate: number; is_active: number };
type SelectOption = { id: number; name: string; title?: string; role?: string };

const money = (value: unknown) => new Intl.NumberFormat('en-BD', { style: 'currency', currency: 'BDT', maximumFractionDigits: 2 }).format(Number(value || 0));

export const TeacherCommissionManager: React.FC = () => {
  const { currentUser, language } = useLms();
  const isBn = language === 'bn';
  const isAdmin = ['super_admin', 'admin', 'accountant'].includes(currentUser.role);
  const [summary, setSummary] = useState<Summary | null>(null);
  const [payouts, setPayouts] = useState<Payout[]>([]);
  const [rules, setRules] = useState<Rule[]>([]);
  const [teachers, setTeachers] = useState<SelectOption[]>([]);
  const [courses, setCourses] = useState<SelectOption[]>([]);
  const [selectedTeacher, setSelectedTeacher] = useState('');
  const [selectedCourse, setSelectedCourse] = useState('');
  const [rate, setRate] = useState('60');
  const [amount, setAmount] = useState('');
  const [method, setMethod] = useState<'bKash' | 'Nagad' | 'Bank Transfer'>('bKash');
  const [account, setAccount] = useState('');
  const [bank, setBank] = useState('');
  const [bankBranch, setBankBranch] = useState('');
  const [transactionReference, setTransactionReference] = useState('');
  const [rejectReason, setRejectReason] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const load = useCallback(async () => {
    setBusy(true);
    setError(null);
    const [summaryResult, payoutResult] = await Promise.all([
      apiClient.commissions.getSummary(isAdmin && selectedTeacher ? selectedTeacher : undefined),
      apiClient.commissions.getPayouts(),
    ]);
    if (summaryResult.success && summaryResult.data) setSummary(summaryResult.data as Summary);
    else { setSummary(null); setError(summaryResult.error || 'Commission summary could not be loaded.'); }
    if (payoutResult.success && Array.isArray(payoutResult.data)) setPayouts(payoutResult.data as Payout[]);
    else { setPayouts([]); setError(current => current || payoutResult.error || 'Payout history could not be loaded.'); }
    if (isAdmin) {
      const [ruleResult, userResult, courseResult] = await Promise.all([
        apiClient.commissions.getRules(), apiClient.users.getAll('', 200), apiClient.courses.getAll(),
      ]);
      if (ruleResult.success && Array.isArray(ruleResult.data)) setRules(ruleResult.data as Rule[]);
      else setError(current => current || ruleResult.error || 'Commission rules could not be loaded.');
      if (userResult.success && Array.isArray(userResult.data)) {
        setTeachers((userResult.data as Array<Record<string, unknown>>)
          .filter(user => ['teacher', 'assistant_teacher'].includes(String(user.role)) && Boolean(user.is_active))
          .map(user => ({ id: Number(user.id), name: String(user.name || ''), role: String(user.role) })));
      }
      if (courseResult.success && Array.isArray(courseResult.data)) {
        setCourses((courseResult.data as Array<Record<string, unknown>>)
          .map(course => ({ id: Number(course.id), name: String(course.title || '') })));
      }
    }
    setBusy(false);
  }, [isAdmin, selectedTeacher]);

  useEffect(() => { void load(); }, [load]);

  const activePayouts = useMemo(() => payouts.filter(item => ['pending', 'approved'].includes(item.status)), [payouts]);

  const runAction = async (action: () => Promise<{ success: boolean; error?: string; message?: string }>, successMessage: string) => {
    setBusy(true); setError(null); setNotice(null);
    const result = await action();
    if (!result.success) setError(result.error || 'The action could not be completed.');
    else { setNotice(successMessage); await load(); }
    setBusy(false);
  };

  const saveRule = async (event: React.FormEvent) => {
    event.preventDefault();
    if (!selectedCourse || !selectedTeacher || rate.trim() === '') return;
    await runAction(() => apiClient.commissions.saveRule({
      course_id: Number(selectedCourse), teacher_user_id: Number(selectedTeacher), commission_rate: Number(rate),
    }), 'Commission rule saved.');
  };

  const requestPayout = async (event: React.FormEvent) => {
    event.preventDefault();
    await runAction(() => apiClient.commissions.requestPayout({
      amount: Number(amount), payout_method: method, account_number: account,
      ...(method === 'Bank Transfer' ? { bank_name: bank, bank_branch: bankBranch } : {}),
    }), 'Payout request submitted.');
    setAmount(''); setAccount(''); setBank(''); setBankBranch('');
  };

  const title = isBn ? 'শিক্ষক কমিশন ও পে-আউট' : 'Teacher Commission & Payouts';
  const balance = summary?.balance;

  return (
    <div className="space-y-6">
      <header className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-white p-5">
        <div>
          <h2 className="text-lg font-bold text-slate-900">{title}</h2>
          <p className="mt-1 text-xs text-slate-500">Commission is calculated from verified payments after completed refunds. Payouts require an actual transfer reference.</p>
        </div>
        <button onClick={() => void load()} disabled={busy} className="rounded-xl border px-4 py-2 text-xs font-semibold disabled:opacity-50">{busy ? 'Loading…' : 'Refresh'}</button>
      </header>

      {error && <div role="alert" className="rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-700">{error}</div>}
      {notice && <div role="status" className="rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-700">{notice}</div>}

      {isAdmin && (
        <section className="rounded-2xl border border-slate-200 bg-white p-5 space-y-3">
          <h3 className="font-bold text-slate-900">Commission rule management</h3>
          <form onSubmit={saveRule} className="grid gap-3 md:grid-cols-4">
            <select required value={selectedCourse} onChange={event => setSelectedCourse(event.target.value)} className="rounded-lg border p-2 text-sm">
              <option value="">Select course</option>{courses.map(course => <option key={course.id} value={course.id}>{course.name}</option>)}
            </select>
            <select required value={selectedTeacher} onChange={event => setSelectedTeacher(event.target.value)} className="rounded-lg border p-2 text-sm">
              <option value="">Select teacher</option>{teachers.map(teacher => <option key={teacher.id} value={teacher.id}>{teacher.name}</option>)}
            </select>
            <input required type="number" min="0" max="100" step="0.01" value={rate} onChange={event => setRate(event.target.value)} aria-label="Commission percentage" className="rounded-lg border p-2 text-sm" />
            <button disabled={busy} className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Save course rate (%)</button>
          </form>
          <div className="overflow-x-auto"><table className="w-full text-left text-xs"><thead><tr className="border-b text-slate-500"><th className="p-2">Course</th><th className="p-2">Teacher</th><th className="p-2">Rate</th><th className="p-2">Status</th></tr></thead><tbody>
            {rules.map(rule => <tr key={rule.id} className="border-b"><td className="p-2">{rule.course_title}</td><td className="p-2">{rule.teacher_name}</td><td className="p-2">{Number(rule.commission_rate).toFixed(2)}%</td><td className="p-2">{Number(rule.is_active) ? 'Active' : 'Inactive'}</td></tr>)}
            {rules.length === 0 && <tr><td colSpan={4} className="p-3 text-slate-500">No commission rules configured.</td></tr>}
          </tbody></table></div>
        </section>
      )}

      <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        {[
          ['Gross commission', balance?.gross_commission],
          ['Paid out', balance?.paid_commission],
          ['Reserved in requests', balance?.reserved_payout],
          ['Available balance', balance?.available_balance],
        ].map(([label, value]) => <div key={String(label)} className="rounded-2xl border border-slate-200 bg-white p-5">
          <div className="text-xs font-semibold text-slate-500">{label}</div><div className="mt-2 text-2xl font-bold tabular-nums text-slate-900">{money(value)}</div>
        </div>)}
      </section>

      {!isAdmin && (
        <section className="rounded-2xl border border-slate-200 bg-white p-5">
          <h3 className="mb-3 font-bold">Request a payout</h3>
          <p className="mb-4 text-xs text-slate-500">Your request cannot exceed your currently available commission. Bank/mobile transfer details are stored with restricted access.</p>
          <form onSubmit={requestPayout} className="grid gap-3 md:grid-cols-2">
            <input required type="number" min="0.01" max={balance?.available_balance ?? 0} step="0.01" value={amount} onChange={event => setAmount(event.target.value)} placeholder="Amount (BDT)" className="rounded-lg border p-2 text-sm" />
            <select value={method} onChange={event => setMethod(event.target.value as typeof method)} className="rounded-lg border p-2 text-sm"><option>bKash</option><option>Nagad</option><option>Bank Transfer</option></select>
            <input required value={account} onChange={event => setAccount(event.target.value)} maxLength={80} placeholder="bKash/Nagad/account number" className="rounded-lg border p-2 text-sm" />
            {method === 'Bank Transfer' && <><input required value={bank} onChange={event => setBank(event.target.value)} maxLength={120} placeholder="Bank name" className="rounded-lg border p-2 text-sm" /><input required value={bankBranch} onChange={event => setBankBranch(event.target.value)} maxLength={120} placeholder="Bank branch" className="rounded-lg border p-2 text-sm" /></>}
            <button disabled={busy || !balance || balance.available_balance <= 0} className="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Submit payout request</button>
          </form>
        </section>
      )}

      <section className="rounded-2xl border border-slate-200 bg-white p-5">
        <div className="mb-3 flex items-center justify-between"><h3 className="font-bold">Payout requests</h3><span className="text-xs text-slate-500">{activePayouts.length} awaiting completion/review</span></div>
        <div className="overflow-x-auto"><table className="w-full min-w-[760px] text-left text-xs"><thead><tr className="border-b text-slate-500"><th className="p-2">Date</th>{isAdmin && <th className="p-2">Teacher</th>}<th className="p-2">Amount</th><th className="p-2">Method / Account</th><th className="p-2">Status</th><th className="p-2">Transfer reference</th>{isAdmin && <th className="p-2">Actions</th>}</tr></thead><tbody>
          {payouts.map(item => <tr key={item.id} className="border-b align-top"><td className="p-2">{String(item.created_at).slice(0,10)}</td>{isAdmin && <td className="p-2">{item.teacher_name}</td>}<td className="p-2 font-semibold">{money(item.amount)}</td><td className="p-2">{item.payout_method}<div className="text-slate-500">{item.account_number}{item.bank_name ? ` · ${item.bank_name} / ${item.bank_branch}` : ''}</div></td><td className="p-2 capitalize">{item.status}</td><td className="p-2">{item.transaction_reference || '—'}</td>
            {isAdmin && <td className="p-2"><div className="flex flex-wrap gap-1">
              {item.status === 'pending' && <button disabled={busy} onClick={() => void runAction(() => apiClient.commissions.approvePayout(item.id), 'Payout approved.')} className="rounded bg-blue-600 px-2 py-1 text-white">Approve</button>}
              {item.status === 'approved' && <><input aria-label="Actual transfer reference" value={transactionReference} onChange={event => setTransactionReference(event.target.value)} placeholder="Actual Trx ID" className="w-28 rounded border p-1" /><button disabled={busy || !transactionReference.trim()} onClick={() => void runAction(() => apiClient.commissions.completePayout(item.id, transactionReference.trim()), 'Payout marked completed.')} className="rounded bg-emerald-600 px-2 py-1 text-white">Mark paid</button></>}
              {['pending','approved'].includes(item.status) && <button disabled={busy || !rejectReason.trim()} onClick={() => void runAction(() => apiClient.commissions.rejectPayout(item.id, rejectReason.trim()), 'Payout rejected.')} className="rounded bg-red-600 px-2 py-1 text-white">Reject</button>}
            </div>{['pending','approved'].includes(item.status) && <input aria-label="Rejection reason" value={rejectReason} onChange={event => setRejectReason(event.target.value)} placeholder="Rejection reason" className="mt-1 w-full rounded border p-1" />}</td>}
          </tr>)}
          {payouts.length === 0 && <tr><td colSpan={isAdmin ? 7 : 5} className="p-3 text-slate-500">No payout requests yet.</td></tr>}
        </tbody></table></div>
      </section>

      <section className="rounded-2xl border border-slate-200 bg-white p-5">
        <h3 className="mb-3 font-bold">Course commission ledger</h3>
        <div className="overflow-x-auto"><table className="w-full text-left text-xs"><thead><tr className="border-b text-slate-500"><th className="p-2">Course</th><th className="p-2">Verified payments</th><th className="p-2">Net revenue</th><th className="p-2">Rate</th><th className="p-2">Commission</th></tr></thead><tbody>
          {(summary?.courses || []).map(course => <tr key={course.id} className="border-b"><td className="p-2">{course.course_title}</td><td className="p-2">{course.verified_payment_count}</td><td className="p-2">{money(course.net_course_revenue)}</td><td className="p-2">{Number(course.commission_rate).toFixed(2)}%</td><td className="p-2 font-semibold">{money(course.gross_commission)}</td></tr>)}
          {(summary?.courses || []).length === 0 && <tr><td colSpan={5} className="p-3 text-slate-500">No active course commission rules found.</td></tr>}
        </tbody></table></div>
      </section>
    </div>
  );
};
