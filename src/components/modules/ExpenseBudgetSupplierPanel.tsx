import React, { useCallback, useEffect, useState } from 'react';
import { useLms } from '../../context/LmsContext';
import { apiClient } from '../../services/apiClient';

type Branch = { id: number; name: string };
type Supplier = { id: number; branch_id: number; supplier_name: string; phone?: string | null; email?: string | null };
type Budget = { id: number; branch_id: number; branch_name: string; category: string; budget_amount: number | string; committed_amount: number | string; remaining_amount: number | string };
type Recurring = {id:number;branch_id:number;branch_name:string;category:string;description:string;vendor:string|null;supplier_name?:string|null;amount:number|string;day_of_month:number;is_active:number;generated_count:number};
const categories = [['rent','Rent'],['utilities','Utilities'],['salary','Salary'],['marketing','Marketing'],['transport','Transport'],['hostel','Hostel'],['library','Library'],['supplies','Office Supplies'],['other','Other']];
const money = (v: unknown) => '৳' + Number(v || 0).toLocaleString('en-BD', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

export const ExpenseBudgetSupplierPanel: React.FC = () => {
  const { currentUser } = useLms();
  const canBudget = ['super_admin','admin','accountant'].includes(currentUser.role);
  const canSupplier = canBudget || currentUser.role === 'branch_manager';
  const [branches,setBranches] = useState<Branch[]>([]);
  const [branchId,setBranchId] = useState(currentUser.role === 'branch_manager' && currentUser.branchId ? String(currentUser.branchId) : '');
  const [month,setMonth] = useState(new Date().toISOString().slice(0,7));
  const [budgets,setBudgets] = useState<Budget[]>([]);
  const [suppliers,setSuppliers] = useState<Supplier[]>([]);
  const [recurring,setRecurring] = useState<Recurring[]>([]);
  const [recDescription,setRecDescription] = useState('');
  const [recAmount,setRecAmount] = useState('');
  const [recDay,setRecDay] = useState('1');
  const [recSupplierId,setRecSupplierId] = useState('');
  const [category,setCategory] = useState('supplies');
  const [recCategory,setRecCategory] = useState('utilities');
  const [amount,setAmount] = useState('');
  const [notes,setNotes] = useState('');
  const [supplierName,setSupplierName] = useState('');
  const [phone,setPhone] = useState('');
  const [email,setEmail] = useState('');
  const [busy,setBusy] = useState(false);
  const [error,setError] = useState<string|null>(null);
  const [notice,setNotice] = useState<string|null>(null);

  const load = useCallback(async () => {
    setBusy(true); setError(null);
    const [b,s,r] = await Promise.all([apiClient.expenses.getBudgets(month,branchId || undefined),apiClient.expenses.getSuppliers(branchId || undefined),apiClient.expenses.getRecurring(branchId || undefined)]);
    if (b.success && Array.isArray(b.data)) setBudgets(b.data as Budget[]); else setError(b.error || 'Budgets could not be loaded.');
    if (s.success && Array.isArray(s.data)) setSuppliers(s.data as Supplier[]); else setError(v => v || s.error || 'Suppliers could not be loaded.');
    if (r.success && Array.isArray(r.data)) setRecurring(r.data as Recurring[]); else setError(v => v || r.error || 'Recurring templates could not be loaded.');
    setBusy(false);
  },[month,branchId]);
  useEffect(() => {
    void apiClient.expenses.getBranches().then(r => {
      if (r.success && Array.isArray(r.data)) {
        const rows = r.data as Branch[]; setBranches(rows);
        if (!branchId && rows.length === 1) setBranchId(String(rows[0].id));
      }
    });
  },[]);
  useEffect(() => { void load(); },[load]);

  const saveBudget = async (e: React.FormEvent) => {
    e.preventDefault(); if (!branchId) { setError('Select one branch before saving a budget.'); return; }
    setBusy(true); setError(null); setNotice(null);
    const r = await apiClient.expenses.saveBudget({branch_id:Number(branchId),category,budget_month:month,budget_amount:amount,notes});
    if (!r.success) setError(r.error || 'Budget could not be saved.');
    else { setNotice('Monthly budget saved.'); setAmount(''); setNotes(''); await load(); }
    setBusy(false);
  };
  const addSupplier = async (e: React.FormEvent) => {
    e.preventDefault(); if (!branchId) { setError('Select one branch before adding a supplier.'); return; }
    setBusy(true); setError(null); setNotice(null);
    const r = await apiClient.expenses.createSupplier({branch_id:Number(branchId),supplier_name:supplierName.trim(),phone:phone.trim(),email:email.trim()});
    if (!r.success) setError(r.error || 'Supplier could not be added.');
    else { setNotice('Supplier added.'); setSupplierName(''); setPhone(''); setEmail(''); await load(); }
    setBusy(false);
  };
  const createRecurring = async (e:React.FormEvent) => {
    e.preventDefault(); if (!branchId) { setError('Select one branch before adding a recurring expense.'); return; }
    setBusy(true); setError(null); setNotice(null);
    const r = await apiClient.expenses.createRecurring({branch_id:Number(branchId),category:recCategory,description:recDescription.trim(),amount:recAmount,day_of_month:Number(recDay),...(recSupplierId?{supplier_id:Number(recSupplierId)}:{})});
    if (!r.success) setError(r.error || 'Recurring template could not be saved.');
    else { setNotice('Recurring expense template saved.'); setRecDescription(''); setRecAmount(''); setRecSupplierId(''); await load(); }
    setBusy(false);
  };
  const generateRecurring = async () => {
    setBusy(true); setError(null); setNotice(null);
    const r = await apiClient.expenses.generateRecurring({expense_month:month,...(branchId?{branch_id:Number(branchId)}:{})});
    if (!r.success) setError(r.error || 'Recurring expense requests could not be generated.');
    else { setNotice(String(r.data?.created_count || 0) + ' requests generated; ' + String(r.data?.skipped_count || 0) + ' already existed or were skipped.'); await load(); }
    setBusy(false);
  };
  const archiveRecurring = async (id:number) => {
    setBusy(true); setError(null); setNotice(null);
    const r = await apiClient.expenses.archiveRecurring(id);
    if (!r.success) setError(r.error || 'Recurring template could not be archived.');
    else { setNotice('Recurring template archived.'); await load(); }
    setBusy(false);
  };
  const archive = async (id:number) => {
    setBusy(true); setError(null); setNotice(null);
    const r = await apiClient.expenses.archiveSupplier(id);
    if (!r.success) setError(r.error || 'Supplier could not be archived.');
    else { setNotice('Supplier archived.'); await load(); }
    setBusy(false);
  };

  return <section className="grid gap-4 xl:grid-cols-2">
    <div className="rounded-2xl border bg-white p-4 space-y-3">
      <div className="flex flex-wrap items-center justify-between gap-2"><h3 className="font-bold">Monthly branch budgets</h3><input type="month" value={month} onChange={e=>setMonth(e.target.value)} className="rounded-lg border p-2 text-sm"/></div>
      <label className="block text-xs font-semibold">Branch<select value={branchId} onChange={e=>{setBranchId(e.target.value);setRecSupplierId('');}} disabled={currentUser.role==='branch_manager'} className="mt-1 w-full rounded-lg border p-2 text-sm"><option value="">All branches</option>{branches.map(b=><option key={b.id} value={b.id}>{b.name}</option>)}</select></label>
      {canBudget && <form onSubmit={saveBudget} className="grid gap-2 sm:grid-cols-2">
        <select value={recCategory} onChange={e=>setRecCategory(e.target.value)} className="rounded-lg border p-2 text-sm">{categories.map(([v,l])=><option key={v} value={v}>{l}</option>)}</select>
        <input required type="number" min="0.01" step="0.01" value={amount} onChange={e=>setAmount(e.target.value)} placeholder="Budget amount (BDT)" className="rounded-lg border p-2 text-sm"/>
        <input value={notes} onChange={e=>setNotes(e.target.value)} maxLength={500} placeholder="Notes (optional)" className="rounded-lg border p-2 text-sm"/>
        <button disabled={busy||!branchId} className="rounded-lg bg-slate-900 px-3 py-2 text-sm font-semibold text-white disabled:opacity-50">Save / update budget</button>
      </form>}
      <div className="space-y-3">{budgets.map(b=>{const pct=Math.min(100,Number(b.committed_amount)/Math.max(1,Number(b.budget_amount))*100);const over=Number(b.committed_amount)>Number(b.budget_amount);return <div key={b.id} className="border-t pt-3"><div className="flex justify-between gap-2 text-sm"><span className="font-semibold capitalize">{b.category} · {b.branch_name}</span><span className={over?'font-bold text-rose-600':'font-semibold'}>{money(b.committed_amount)} / {money(b.budget_amount)}</span></div><div className="mt-2 h-2 overflow-hidden rounded-full bg-slate-100"><div className={over?'h-full bg-rose-500':'h-full bg-emerald-500'} style={{width:pct+'%'}}/></div><div className="mt-1 text-xs text-slate-500">{over?'Over budget by '+money(Number(b.committed_amount)-Number(b.budget_amount)):'Remaining '+money(b.remaining_amount)} · approved + paid expenses</div></div>;})}{budgets.length===0&&<p className="text-xs text-slate-500">No budget set for this month and branch filter.</p>}</div>
    </div>
    <div className="rounded-2xl border bg-white p-4 space-y-3">
      <h3 className="font-bold">Supplier directory</h3>
      {canSupplier && <form onSubmit={addSupplier} className="grid gap-2 sm:grid-cols-2">
        <input required maxLength={160} value={supplierName} onChange={e=>setSupplierName(e.target.value)} placeholder="Supplier name" className="rounded-lg border p-2 text-sm"/>
        <input maxLength={40} value={phone} onChange={e=>setPhone(e.target.value)} placeholder="Phone" className="rounded-lg border p-2 text-sm"/>
        <input type="email" maxLength={190} value={email} onChange={e=>setEmail(e.target.value)} placeholder="Email (optional)" className="rounded-lg border p-2 text-sm"/>
        <button disabled={busy||!branchId} className="rounded-lg bg-slate-900 px-3 py-2 text-sm font-semibold text-white disabled:opacity-50">Add supplier</button>
      </form>}
      <div className="divide-y">{suppliers.map(s=><div key={s.id} className="flex items-center justify-between gap-2 py-2 text-sm"><div><div className="font-semibold">{s.supplier_name}</div><div className="text-xs text-slate-500">{s.phone||'No phone'}{s.email?' · '+s.email:''}</div></div>{canSupplier && <button disabled={busy} onClick={()=>void archive(s.id)} className="rounded border px-2 py-1 text-xs">Archive</button>}</div>)}{suppliers.length===0&&<p className="py-2 text-xs text-slate-500">No suppliers registered.</p>}</div>
    </div>
    <div className="rounded-2xl border bg-white p-4 space-y-3 xl:col-span-2">
      <div className="flex flex-wrap items-center justify-between gap-2"><div><h3 className="font-bold">Recurring expense requests</h3><p className="text-xs text-slate-500">Generate this month's pending requests once; repeated runs skip templates already generated for that month.</p></div>{canBudget && <button type="button" disabled={busy} onClick={()=>void generateRecurring()} className="rounded-lg bg-blue-700 px-3 py-2 text-sm font-semibold text-white disabled:opacity-50">Generate for {month}</button>}</div>
      {canBudget && <form onSubmit={createRecurring} className="grid gap-2 md:grid-cols-3">
        <select value={category} onChange={e=>setCategory(e.target.value)} className="rounded-lg border p-2 text-sm">{categories.map(([v,l])=><option key={v} value={v}>{l}</option>)}</select>
        <input required maxLength={255} value={recDescription} onChange={e=>setRecDescription(e.target.value)} placeholder="Recurring expense description" className="rounded-lg border p-2 text-sm"/>
        <input required type="number" min="0.01" step="0.01" value={recAmount} onChange={e=>setRecAmount(e.target.value)} placeholder="Amount (BDT)" className="rounded-lg border p-2 text-sm"/>
        <input required type="number" min="1" max="28" value={recDay} onChange={e=>setRecDay(e.target.value)} placeholder="Day of month (1–28)" className="rounded-lg border p-2 text-sm"/>
        <select value={recSupplierId} onChange={e=>setRecSupplierId(e.target.value)} className="rounded-lg border p-2 text-sm"><option value="">No linked supplier</option>{suppliers.map(s=><option key={s.id} value={s.id}>{s.supplier_name}</option>)}</select>
        <button disabled={busy||!branchId} className="rounded-lg bg-slate-900 px-3 py-2 text-sm font-semibold text-white disabled:opacity-50">Save recurring template</button>
      </form>}
      <div className="divide-y">{recurring.map(t=><div key={t.id} className="flex flex-wrap items-center justify-between gap-3 py-2 text-sm"><div><div className="font-semibold">{t.description} · {money(t.amount)}</div><div className="text-xs text-slate-500">{t.branch_name} · {t.category} · day {t.day_of_month} · generated {t.generated_count} time(s)</div></div>{canBudget&&Number(t.is_active)===1&&<button disabled={busy} onClick={()=>void archiveRecurring(t.id)} className="rounded border px-2 py-1 text-xs">Archive</button>}</div>)}{recurring.length===0&&<p className="py-2 text-xs text-slate-500">No recurring expense templates.</p>}</div>
    </div>
    {error&&<div role="alert" className="rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700 xl:col-span-2">{error}</div>}
    {notice&&<div role="status" className="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-700 xl:col-span-2">{notice}</div>}
  </section>;
};
