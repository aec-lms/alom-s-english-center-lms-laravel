import React, { useEffect, useState } from 'react';
import { apiClient } from '../../services/apiClient';
import { useLms } from '../../context/LmsContext';

type Loan = { id:number; book_title:string; author:string; isbn:string|null; issued_at:string; due_date:string; returned_at:string|null; fine_amount:string|number; fine_paid_amount:string|number; fine_outstanding_amount:string|number; status:'issued'|'returned'|'lost' };

export const StudentLibraryLoans: React.FC = () => {
  const { language } = useLms();
  const [loans,setLoans] = useState<Loan[]>([]);
  const [loading,setLoading] = useState(true);
  const [error,setError] = useState<string|null>(null);
  useEffect(()=>{
    let active=true;
    apiClient.library.getMyLoans().then(result=>{
      if(!active) return;
      if(result.success && Array.isArray(result.data)) setLoans(result.data as Loan[]);
      else setError(result.error||'Your library loan history could not be loaded.');
      setLoading(false);
    });
    return ()=>{active=false;};
  },[]);
  const isBn=language==='bn';
  return <section className="space-y-4">
    <header><h2 className="text-lg font-bold text-slate-900">{isBn?'আমার লাইব্রেরি বই':'My Library Books'}</h2><p className="mt-1 text-xs text-slate-500">{isBn?'আপনার নামে ইস্যু করা বই, ফেরতের তারিখ ও বিলম্ব ফি দেখুন।':'View your issued books, due dates, return history and any late fees.'}</p></header>
    {error&&<div role="alert" className="rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-700">{error}</div>}
    <div className="overflow-hidden rounded-2xl border bg-white"><div className="overflow-x-auto"><table className="w-full min-w-[680px] text-left text-xs"><thead className="bg-slate-50 text-slate-500"><tr><th className="p-3">Book</th><th className="p-3">Issued</th><th className="p-3">Due date</th><th className="p-3">Returned</th><th className="p-3">Status</th><th className="p-3">Assessed fine</th><th className="p-3">Paid</th><th className="p-3">Outstanding</th></tr></thead><tbody>{loans.map(loan=><tr key={loan.id} className="border-t"><td className="p-3 font-semibold">{loan.book_title}<div className="font-normal text-slate-500">{loan.author} · {loan.isbn||'No ISBN'}</div></td><td className="p-3">{String(loan.issued_at).slice(0,10)}</td><td className="p-3">{loan.due_date}</td><td className="p-3">{loan.returned_at?String(loan.returned_at).slice(0,10):'—'}</td><td className="p-3 capitalize">{loan.status}</td><td className="p-3">৳{Number(loan.fine_amount||0).toFixed(2)}</td><td className="p-3">৳{Number(loan.fine_paid_amount||0).toFixed(2)}</td><td className="p-3 font-semibold">৳{Number(loan.fine_outstanding_amount||0).toFixed(2)}</td></tr>)}{!loading&&loans.length===0&&<tr><td colSpan={8} className="p-4 text-slate-500">{isBn?'এখনো কোনো বই ইস্যু করা হয়নি।':'No library books have been issued to your account.'}</td></tr>}</tbody></table></div>{loading&&<p className="p-4 text-xs text-slate-500">Loading your library history…</p>}</div>
  </section>;
};
