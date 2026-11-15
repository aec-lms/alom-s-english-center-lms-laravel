import React, { useCallback, useEffect, useState } from 'react';
import { useLms } from '../../context/LmsContext';
import { apiClient } from '../../services/apiClient';

type Book = { id:number; branch_id:number; isbn:string|null; title:string; author:string; category:string; shelf_location:string; total_copies:number; available_copies:number; issued_copies:number; daily_fine:string|number };
type Loan = { id:number; book_id:number; student_user_id:number; student_name:string; book_title:string; issued_at:string; due_date:string; returned_at:string|null; fine_amount:string|number; status:'issued'|'returned'|'lost' };
type Option = { id:number; name:string; email?:string };
const today = new Date().toISOString().slice(0,10);
const dueDefault = new Date(Date.now()+14*86400000).toISOString().slice(0,10);

export const LibraryManagementPanel: React.FC = () => {
  const { currentUser, language } = useLms();
  const isBn = language === 'bn';
  const [branches,setBranches] = useState<Option[]>([]);
  const [students,setStudents] = useState<Option[]>([]);
  const [books,setBooks] = useState<Book[]>([]);
  const [loans,setLoans] = useState<Loan[]>([]);
  const [branchId,setBranchId] = useState(['branch_manager','support_staff'].includes(currentUser.role) && currentUser.branchId ? String(currentUser.branchId) : '');
  const [query,setQuery] = useState('');
  const [studentId,setStudentId] = useState('');
  const [bookId,setBookId] = useState('');
  const [dueDate,setDueDate] = useState(dueDefault);
  const [title,setTitle] = useState('');
  const [author,setAuthor] = useState('');
  const [isbn,setIsbn] = useState('');
  const [category,setCategory] = useState('');
  const [shelf,setShelf] = useState('');
  const [copies,setCopies] = useState('1');
  const [fine,setFine] = useState('0');
  const [loading,setLoading] = useState(false);
  const [saving,setSaving] = useState(false);
  const [error,setError] = useState<string|null>(null);
  const [notice,setNotice] = useState<string|null>(null);

  const load = useCallback(async () => {
    setLoading(true); setError(null);
    const [branchResult,bookResult,loanResult,studentResult] = await Promise.all([
      apiClient.library.getBranches(),
      apiClient.library.getBooks(branchId || undefined,query),
      apiClient.library.getLoans(branchId || undefined),
      apiClient.library.getStudents(branchId || undefined),
    ]);
    if(branchResult.success && Array.isArray(branchResult.data)) {
      const rows=branchResult.data as Option[]; setBranches(rows);
      if(!branchId && rows.length===1) setBranchId(String(rows[0].id));
    } else setError(branchResult.error || 'Library branches could not be loaded.');
    if(bookResult.success && Array.isArray(bookResult.data)) setBooks(bookResult.data as Book[]);
    else { setBooks([]); setError(current=>current||bookResult.error||'Library catalog could not be loaded.'); }
    if(loanResult.success && Array.isArray(loanResult.data)) setLoans(loanResult.data as Loan[]);
    else { setLoans([]); setError(current=>current||loanResult.error||'Library loan records could not be loaded.'); }
    if(studentResult.success && Array.isArray(studentResult.data)) setStudents(studentResult.data as Option[]);
    else { setStudents([]); setError(current=>current||studentResult.error||'Student list could not be loaded.'); }
    setLoading(false);
  },[branchId,query]);
  useEffect(()=>{void load();},[load]);

  const act = async (action:()=>Promise<{success:boolean;error?:string;data?:unknown}>, message:string) => {
    setSaving(true); setError(null); setNotice(null);
    const result=await action();
    if(!result.success) setError(result.error||'The library operation could not be completed.');
    else { setNotice(message); await load(); }
    setSaving(false);
  };
  const addBook = async (event:React.FormEvent) => {
    event.preventDefault();
    if(!branchId) { setError('Select a branch first.'); return; }
    await act(()=>apiClient.library.createBook({branch_id:Number(branchId),title:title.trim(),author:author.trim(),isbn:isbn.trim(),category:category.trim(),shelf_location:shelf.trim(),total_copies:Number(copies),daily_fine:Number(fine)}),'Book saved to the library database.');
    setTitle(''); setAuthor(''); setIsbn(''); setCategory(''); setShelf(''); setCopies('1'); setFine('0');
  };
  const issue = async (event:React.FormEvent) => {
    event.preventDefault();
    if(!bookId||!studentId) return;
    await act(()=>apiClient.library.issueBook({book_id:Number(bookId),student_user_id:Number(studentId),due_date:dueDate}),'Book issued successfully.');
    setBookId(''); setStudentId('');
  };
  const returnLoan = async (loan:Loan) => {
    const result=await apiClient.library.returnBook(loan.id);
    if(!result.success) { setError(result.error||'Book return failed.'); return; }
    setNotice('Book returned. Late fine: ৳' + Number(result.data?.fine_amount||0).toFixed(2));
    await load();
  };

  return <div className="space-y-5">
    <header className="flex flex-wrap items-center justify-between gap-3"><div><h3 className="text-base font-bold text-slate-900">{isBn?'লাইব্রেরি ক্যাটালগ ও বই ধার':'Library Catalog & Book Lending'}</h3><p className="mt-1 text-xs text-slate-500">{isBn?'ডাটাবেজে বই, কপি, শিক্ষার্থী ইস্যু ও ফেরতের হিসাব সংরক্ষিত হয়।':'Inventory and every issue/return are persisted in MySQL with branch-level access controls.'}</p></div><button onClick={()=>void load()} disabled={loading} className="rounded-xl border px-3 py-2 text-xs font-semibold disabled:opacity-50">{loading?'Loading…':'Refresh'}</button></header>
    {error&&<div role="alert" className="rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-700">{error}</div>}
    {notice&&<div role="status" className="rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-700">{notice}</div>}
    <div className="flex flex-wrap items-center gap-3 rounded-xl border bg-white p-3">
      <label className="text-xs font-semibold text-slate-600">Branch<select value={branchId} onChange={e=>setBranchId(e.target.value)} disabled={['branch_manager','support_staff'].includes(currentUser.role)} className="ml-2 rounded-lg border p-2 text-xs"><option value="">All branches</option>{branches.map(b=><option key={b.id} value={b.id}>{b.name}</option>)}</select></label>
      <input value={query} onChange={e=>setQuery(e.target.value)} placeholder="Search title, author, ISBN…" className="min-w-56 flex-1 rounded-lg border p-2 text-xs"/>
      <span className="text-xs text-slate-500">{books.reduce((sum,b)=>sum+b.total_copies,0)} total copies · {books.reduce((sum,b)=>sum+b.available_copies,0)} available</span>
    </div>
    <div className="grid gap-4 xl:grid-cols-2">
      <section className="rounded-2xl border bg-white p-4"><h4 className="mb-3 font-bold text-slate-900">Add book to catalog</h4><form onSubmit={addBook} className="grid gap-2 sm:grid-cols-2">
        <input required value={title} onChange={e=>setTitle(e.target.value)} maxLength={180} placeholder="Book title" className="rounded-lg border p-2 text-sm sm:col-span-2"/>
        <input value={author} onChange={e=>setAuthor(e.target.value)} maxLength={160} placeholder="Author" className="rounded-lg border p-2 text-sm"/>
        <input value={isbn} onChange={e=>setIsbn(e.target.value)} maxLength={32} placeholder="ISBN (optional)" className="rounded-lg border p-2 text-sm"/>
        <input value={category} onChange={e=>setCategory(e.target.value)} maxLength={120} placeholder="Category" className="rounded-lg border p-2 text-sm"/>
        <input value={shelf} onChange={e=>setShelf(e.target.value)} maxLength={80} placeholder="Shelf location" className="rounded-lg border p-2 text-sm"/>
        <label className="text-xs text-slate-600">Total copies<input required type="number" min="1" max="5000" value={copies} onChange={e=>setCopies(e.target.value)} className="mt-1 w-full rounded-lg border p-2 text-sm"/></label>
        <label className="text-xs text-slate-600">Late fine per day (BDT)<input type="number" min="0" step="0.01" value={fine} onChange={e=>setFine(e.target.value)} className="mt-1 w-full rounded-lg border p-2 text-sm"/></label>
        <button disabled={saving||!branchId} className="rounded-lg bg-blue-600 px-3 py-2 text-sm font-semibold text-white disabled:opacity-50 sm:col-span-2">Save book</button>
      </form></section>
      <section className="rounded-2xl border bg-white p-4"><h4 className="mb-3 font-bold text-slate-900">Issue a book to a student</h4><form onSubmit={issue} className="grid gap-2">
        <select required value={bookId} onChange={e=>setBookId(e.target.value)} className="rounded-lg border p-2 text-sm"><option value="">Select available book</option>{books.filter(b=>b.available_copies>0).map(b=><option key={b.id} value={b.id}>{b.title} ({b.available_copies} available)</option>)}</select>
        <select required value={studentId} onChange={e=>setStudentId(e.target.value)} className="rounded-lg border p-2 text-sm"><option value="">Select active student</option>{students.map(s=><option key={s.id} value={s.id}>{s.name}{s.email ? ' — '+s.email : ''}</option>)}</select>
        <label className="text-xs text-slate-600">Due date<input required type="date" min={today} value={dueDate} onChange={e=>setDueDate(e.target.value)} className="mt-1 w-full rounded-lg border p-2 text-sm"/></label>
        <button disabled={saving||!bookId||!studentId} className="rounded-lg bg-emerald-600 px-3 py-2 text-sm font-semibold text-white disabled:opacity-50">Issue book</button>
      </form></section>
    </div>
    <section className="overflow-hidden rounded-2xl border bg-white"><div className="border-b p-4"><h4 className="font-bold text-slate-900">Book inventory</h4></div><div className="overflow-x-auto"><table className="w-full min-w-[680px] text-left text-xs"><thead className="bg-slate-50 text-slate-500"><tr><th className="p-3">Book</th><th className="p-3">ISBN / Shelf</th><th className="p-3">Available</th><th className="p-3">Issued</th></tr></thead><tbody>{books.map(b=><tr key={b.id} className="border-t"><td className="p-3 font-semibold">{b.title}<div className="font-normal text-slate-500">{b.author} · {b.category}</div></td><td className="p-3">{b.isbn||'—'}<div className="text-slate-500">{b.shelf_location||'—'}</div></td><td className="p-3">{b.available_copies} / {b.total_copies}</td><td className="p-3">{b.issued_copies}</td></tr>)}{books.length===0&&<tr><td colSpan={4} className="p-4 text-slate-500">No books found for this branch.</td></tr>}</tbody></table></div></section>
    <section className="overflow-hidden rounded-2xl border bg-white"><div className="border-b p-4"><h4 className="font-bold text-slate-900">Loan register & returns</h4></div><div className="overflow-x-auto"><table className="w-full min-w-[720px] text-left text-xs"><thead className="bg-slate-50 text-slate-500"><tr><th className="p-3">Book</th><th className="p-3">Student</th><th className="p-3">Issued</th><th className="p-3">Due</th><th className="p-3">Status / Fine</th><th className="p-3">Action</th></tr></thead><tbody>{loans.map(l=><tr key={l.id} className="border-t"><td className="p-3 font-semibold">{l.book_title}</td><td className="p-3">{l.student_name}</td><td className="p-3">{String(l.issued_at).slice(0,10)}</td><td className="p-3">{l.due_date}</td><td className="p-3 capitalize">{l.status}<div className="text-slate-500">৳{Number(l.fine_amount||0).toFixed(2)}</div></td><td className="p-3">{l.status==='issued'&&<button disabled={saving} onClick={()=>void returnLoan(l)} className="rounded-lg bg-slate-900 px-3 py-1.5 text-white disabled:opacity-50">Return</button>}</td></tr>)}{loans.length===0&&<tr><td colSpan={6} className="p-4 text-slate-500">No loan records found.</td></tr>}</tbody></table></div></section>
  </div>;
};
