import React, { useEffect, useMemo, useState } from 'react';
import { useLms } from '../../context/LmsContext';
import { apiClient } from '../../services/apiClient';
import { Download, Printer, RefreshCw, AlertCircle } from 'lucide-react';

type ReportType = 'revenue' | 'admission' | 'attendance' | 'exams' | 'expenses' | 'facility_fees' | 'trial_balance' | 'income_statement';
type Period = 'current_month' | 'last_month' | 'last_30_days' | 'current_year';
type ReportRow = Record<string, unknown>;

const columns: Record<ReportType, Array<{ key: string; label: string }>> = {
  revenue: [
    { key: 'report_date', label: 'Date' }, { key: 'payment_count', label: 'Payments' },
    { key: 'gross_amount', label: 'Gross (BDT)' }, { key: 'refunded_amount', label: 'Refunded (BDT)' },
    { key: 'net_amount', label: 'Net (BDT)' },
  ],
  admission: [{ key: 'status', label: 'Application status' }, { key: 'application_count', label: 'Applications' }],
  attendance: [
    { key: 'report_date', label: 'Date' }, { key: 'marked_count', label: 'Marked' },
    { key: 'present_count', label: 'Present' }, { key: 'absent_count', label: 'Absent' },
    { key: 'late_count', label: 'Late' }, { key: 'excused_count', label: 'Excused' },
  ],
  exams: [
    { key: 'exam_title', label: 'Exam' }, { key: 'attempt_count', label: 'Attempts' },
    { key: 'submitted_count', label: 'Submitted' }, { key: 'passed_count', label: 'Passed' },
    { key: 'average_percentage', label: 'Average (%)' },
  ],
  expenses: [
    { key: 'report_date', label: 'Date' }, { key: 'category', label: 'Category' },
    { key: 'status', label: 'Status' }, { key: 'record_count', label: 'Records' },
    { key: 'total_amount', label: 'Amount (BDT)' },
  ],
  facility_fees: [
    { key: 'report_date', label: 'Date' }, { key: 'service_type', label: 'Service' },
    { key: 'payment_count', label: 'Receipts' }, { key: 'collected_amount', label: 'Collected (BDT)' },
  ],
  trial_balance: [
    { key: 'account_code', label: 'Account code' }, { key: 'account_name', label: 'Account' },
    { key: 'account_type', label: 'Type' }, { key: 'total_debit', label: 'Total debit' },
    { key: 'total_credit', label: 'Total credit' }, { key: 'debit_balance', label: 'Debit balance' },
    { key: 'credit_balance', label: 'Credit balance' },
  ],
  income_statement: [
    { key: 'account_code', label: 'Account code' }, { key: 'account_name', label: 'Account' },
    { key: 'account_type', label: 'Type' }, { key: 'period_amount', label: 'Period amount (BDT)' },
  ],
};

export const ReportAnalyticsGenerator: React.FC = () => {
  const { branches, language, currentUser } = useLms();
  const isBn = language === 'bn';
  const [reportType, setReportType] = useState<ReportType>(currentUser.role === 'admin' ? 'revenue' : 'expenses');
  const [selectedBranch, setSelectedBranch] = useState('all');
  const [period, setPeriod] = useState<Period>('current_month');
  const [rows, setRows] = useState<ReportRow[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const branchOptions = useMemo(() => currentUser.role === 'branch_manager'
    ? branches.filter(branch => String(branch.id) === String(currentUser.branchId))
    : branches, [branches, currentUser.role, currentUser.branchId]);

  const loadReport = async () => {
    setLoading(true);
    setError(null);
    const result = await apiClient.reports.get(reportType, period, selectedBranch);
    if (result.success && Array.isArray(result.data?.rows)) setRows(result.data.rows);
    else { setRows([]); setError(result.error || 'Report could not be loaded.'); }
    setLoading(false);
  };

  useEffect(() => { void loadReport(); }, [reportType, period, selectedBranch]);

  const downloadCsv = () => {
    const fields = columns[reportType];
    const escapeCsv = (value: unknown) => '"' + String(value ?? '').replace(/"/g, '""') + '"';
    const csv = [fields.map(field => escapeCsv(field.label)).join(','), ...rows.map(row => fields.map(field => escapeCsv(row[field.key])).join(','))].join('\r\n');
    const blob = new Blob(['\uFEFF', csv], { type: 'text/csv;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = `AEC_${reportType}_${period}.csv`;
    link.click();
    URL.revokeObjectURL(url);
  };

  return (
    <div className="space-y-6">
      <section className="rounded-2xl border border-slate-200 bg-white p-5 flex flex-wrap items-center justify-between gap-4">
        <div>
          <h2 className="text-lg font-bold text-slate-900">{isBn ? 'রিপোর্ট ও বিশ্লেষণ' : 'Reports & Analytics'}</h2>
          <p className="mt-1 text-xs text-slate-500">{isBn ? 'ডাটাবেস থেকে সরাসরি রিপোর্ট; শাখাভিত্তিক অনুমতি সার্ভার যাচাই করে' : 'Live database reports with server-enforced branch access.'}</p>
        </div>
        <div className="flex flex-wrap gap-2">
          <button onClick={downloadCsv} disabled={loading || rows.length === 0} className="rounded-xl bg-emerald-600 px-4 py-2 text-xs font-bold text-white disabled:opacity-50"><Download className="mr-1 inline h-4 w-4" />CSV</button>
          <button onClick={() => window.print()} className="rounded-xl bg-slate-900 px-4 py-2 text-xs font-bold text-white"><Printer className="mr-1 inline h-4 w-4" />Print</button>
        </div>
      </section>

      <section className="rounded-2xl border border-slate-200 bg-white p-4 flex flex-wrap gap-3">
        <select aria-label="Report type" value={reportType} onChange={event => setReportType(event.target.value as ReportType)} className="rounded-xl border border-slate-300 px-3 py-2 text-xs">
          {currentUser.role === 'admin' ? <>
            <option value="revenue">Revenue & refunds</option>
            <option value="admission">Admission applications</option>
            <option value="attendance">Attendance</option>
            <option value="exams">Exam performance</option>
            <option value="expenses">Expenses</option>
            <option value="facility_fees">Hostel & transport collections</option>
            <option value="trial_balance">Trial balance</option>
            <option value="income_statement">Income statement</option>
          </> : <>
            <option value="expenses">Expenses</option>
            <option value="facility_fees">Hostel & transport collections</option>
            <option value="trial_balance">Trial balance</option>
            <option value="income_statement">Income statement</option>
          </>}
        </select>
        <select aria-label="Reporting period" value={period} onChange={event => setPeriod(event.target.value as Period)} className="rounded-xl border border-slate-300 px-3 py-2 text-xs">
          <option value="current_month">Current month</option>
          <option value="last_month">Last month</option>
          <option value="last_30_days">Last 30 days</option>
          <option value="current_year">Current year</option>
        </select>
        {currentUser.role !== 'branch_manager' && <select aria-label="Branch" value={selectedBranch} onChange={event => setSelectedBranch(event.target.value)} className="rounded-xl border border-slate-300 px-3 py-2 text-xs">
          <option value="all">All branches</option>
          {branchOptions.map(branch => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
        </select>}
        <button onClick={() => void loadReport()} disabled={loading} className="rounded-xl border border-slate-300 px-3 py-2 text-xs font-bold disabled:opacity-50"><RefreshCw className="mr-1 inline h-4 w-4" />Refresh</button>
      </section>

      <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white">
        <div className="border-b border-slate-100 px-5 py-4 font-bold text-sm">{reportType.charAt(0).toUpperCase() + reportType.slice(1)} report</div>
        {loading ? <p className="p-8 text-center text-sm text-slate-500">Loading report…</p>
          : error ? <div role="alert" className="p-6 text-sm text-rose-700"><AlertCircle className="mr-2 inline h-4 w-4" />{error}</div>
          : rows.length === 0 ? <p className="p-8 text-center text-sm text-slate-500">No records found for this period.</p>
          : <div className="overflow-x-auto"><table className="w-full text-left text-xs">
            <thead className="bg-slate-50"><tr>{columns[reportType].map(column => <th key={column.key} className="px-4 py-3 font-bold text-slate-600">{column.label}</th>)}</tr></thead>
            <tbody className="divide-y divide-slate-100">{rows.map((row, index) => <tr key={String(row.exam_id ?? row.report_date ?? row.status ?? index)}>{columns[reportType].map(column => <td key={column.key} className="px-4 py-3 text-slate-700">{row[column.key] == null ? '—' : String(row[column.key])}</td>)}</tr>)}</tbody>
          </table></div>}
      </section>
      <p className="text-[11px] text-slate-500">{reportType === 'expenses' ? (isBn ? 'দ্রষ্টব্য: এই রিপোর্টে তারিখ, ক্যাটাগরি ও স্ট্যাটাস অনুযায়ী খরচ দেখানো হয়; পূর্ণ লাভ-ক্ষতির বিবরণীর জন্য সব আয়, রিফান্ড ও ব্যয়ের সমন্বয় প্রয়োজন।' : 'Note: expense reporting shows recorded expense requests by date, category and status; a complete net-profit statement still requires full income, refund, payable and expense reconciliation.') : reportType === 'facility_fees' ? 'Facility fee report shows received hostel and transport payments, not outstanding invoice totals.' : ['trial_balance','income_statement'].includes(reportType) ? 'Accounting statements include posted course-payment/refund, expense, library-fine and facility-fee journals. Teacher commission accrual/payout and any manually recorded transactions outside these journals are not included yet.' : ''}</p>
    </div>
  );
};
