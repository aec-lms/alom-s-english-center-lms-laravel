import React, { useCallback, useEffect, useState } from 'react';
import { Bell, Plus, RefreshCw, Archive, CheckCircle2, AlertCircle, Pencil, X } from 'lucide-react';
import { apiClient } from '../../services/apiClient';
import { useLms } from '../../context/LmsContext';

type NoticeRow = {
  id: number; title: string; body: string; audience_role: string; branch_id: number | null;
  status: 'draft' | 'scheduled' | 'published' | 'archived'; starts_at: string | null;
  ends_at: string | null; created_at: string; read_count?: number;
};

export const NoticeBoardManager: React.FC = () => {
  const { currentUser, language } = useLms();
  const isBn = language === 'bn';
  const isAdmin = currentUser.role === 'admin';
  const [items, setItems] = useState<NoticeRow[]>([]);
  const [editingId, setEditingId] = useState<number | null>(null);
  const [title, setTitle] = useState('');
  const [body, setBody] = useState('');
  const [audience, setAudience] = useState('all');
  const [branchId, setBranchId] = useState('');
  const [startsAt, setStartsAt] = useState('');
  const [endsAt, setEndsAt] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [message, setMessage] = useState('');

  const load = useCallback(async () => {
    setBusy(true); setError('');
    const result = isAdmin ? await apiClient.notices.list() : await apiClient.notices.visible();
    if (result.success) setItems((result.data || []) as NoticeRow[]);
    else setError(result.error || 'Could not load notices.');
    setBusy(false);
  }, [isAdmin]);

  useEffect(() => { void load(); }, [load]);

  const create = async (event: React.FormEvent) => {
    event.preventDefault(); setBusy(true); setError(''); setMessage('');
    const payload = {
      title: title.trim(), body: body.trim(), audience_role: audience,
      branch_id: branchId ? Number(branchId) : null,
      starts_at: startsAt ? startsAt.replace('T', ' ') + ':00' : '',
      ends_at: endsAt ? endsAt.replace('T', ' ') + ':00' : '',
    };
    const result = editingId
      ? await apiClient.notices.update(editingId, payload)
      : await apiClient.notices.create(payload);
    if (result.success) {
      setTitle(''); setBody(''); setBranchId(''); setStartsAt(''); setEndsAt(''); setEditingId(null);
      setMessage(isBn ? 'নোটিশ সংরক্ষণ হয়েছে।' : (editingId ? 'Notice updated.' : 'Notice saved.'));
      await load();
    } else setError(result.error || 'Could not save notice.');
    setBusy(false);
  };

  const beginEdit = (item: NoticeRow) => {
    setEditingId(item.id); setTitle(item.title); setBody(item.body);
    setAudience(item.audience_role); setBranchId(item.branch_id ? String(item.branch_id) : '');
    setStartsAt(item.starts_at ? item.starts_at.replace(' ', 'T').slice(0, 16) : '');
    setEndsAt(item.ends_at ? item.ends_at.replace(' ', 'T').slice(0, 16) : '');
    setMessage('');
    setError('');
  };

  const cancelEdit = () => {
    setEditingId(null); setTitle(''); setBody(''); setAudience('all');
    setBranchId(''); setStartsAt(''); setEndsAt('');
  };

  const setStatus = async (id: number, status: 'draft' | 'published' | 'archived') => {
    setBusy(true); setError('');
    const result = await apiClient.notices.updateStatus(id, status);
    if (result.success) await load(); else setError(result.error || 'Could not update notice.');
    setBusy(false);
  };

  const markRead = async (id: number) => {
    const result = await apiClient.notices.markRead(id);
    if (result.success) setItems(current => current.map(item => item.id === id ? { ...item, is_read: 1 } as NoticeRow : item));
    else setError(result.error || 'Could not mark notice as read.');
  };

  const roles = [
    ['all', isBn ? 'সকল ব্যবহারকারী' : 'All users'],
    ['student', isBn ? 'শিক্ষার্থী' : 'Students'],
    ['guardian', isBn ? 'অভিভাবক' : 'Guardians'],
    ['teacher', isBn ? 'শিক্ষক' : 'Teachers'],
    ['assistant_teacher', isBn ? 'সহকারী শিক্ষক' : 'Assistant teachers'],
    ['branch_manager', isBn ? 'শাখা ব্যবস্থাপক' : 'Branch managers'],
    ['accountant', isBn ? 'হিসাবরক্ষক' : 'Accountants'],
    ['support_staff', isBn ? 'সাপোর্ট স্টাফ' : 'Support staff'],
    ['admin', isBn ? 'অ্যাডমিন' : 'Admins'],
  ];

  return (
    <div className="space-y-5">
      <div className="flex items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-white p-5">
        <div className="flex items-center gap-3">
          <div className="rounded-xl bg-blue-50 p-3 text-blue-700"><Bell className="h-5 w-5" /></div>
          <div><h2 className="text-lg font-bold text-slate-900">{isBn ? 'নোটিশ বোর্ড' : 'Notice Board'}</h2>
            <p className="text-xs text-slate-500">{isBn ? 'রোলভিত্তিক নোটিশ, সময়সূচি ও পঠিত অবস্থা' : 'Persistent notices, audience targeting and read receipts'}</p></div>
        </div>
        <button onClick={() => void load()} disabled={busy} className="rounded-lg border border-slate-200 p-2 text-slate-600 hover:bg-slate-50"><RefreshCw className={`h-4 w-4 ${busy ? 'animate-spin' : ''}`} /></button>
      </div>

      {error && <div role="alert" className="flex items-center gap-2 rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700"><AlertCircle className="h-4 w-4" />{error}</div>}
      {message && <div role="status" className="flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-700"><CheckCircle2 className="h-4 w-4" />{message}</div>}

      {isAdmin && <form onSubmit={create} className="space-y-3 rounded-2xl border border-slate-200 bg-white p-5">
        <h3 className="flex items-center gap-2 font-bold text-slate-900">{editingId ? <Pencil className="h-4 w-4" /> : <Plus className="h-4 w-4" />}{isBn ? (editingId ? 'নোটিশ সম্পাদনা' : 'নতুন নোটিশ প্রকাশ করুন') : (editingId ? 'Edit notice' : 'Create a notice')}</h3>
        <input required maxLength={180} value={title} onChange={e => setTitle(e.target.value)} placeholder={isBn ? 'নোটিশের শিরোনাম' : 'Notice title'} className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
        <textarea required maxLength={20000} rows={4} value={body} onChange={e => setBody(e.target.value)} placeholder={isBn ? 'নোটিশের বিস্তারিত' : 'Notice details'} className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <label className="text-xs font-semibold text-slate-600">{isBn ? 'কারা দেখবে' : 'Audience'}<select value={audience} onChange={e => setAudience(e.target.value)} className="mt-1 w-full rounded-lg border border-slate-300 bg-white p-2 text-sm">{roles.map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></label>
          <label className="text-xs font-semibold text-slate-600">{isBn ? 'শাখা ID (ঐচ্ছিক)' : 'Branch ID (optional)'}<input value={branchId} onChange={e => setBranchId(e.target.value)} inputMode="numeric" placeholder="e.g. 2" className="mt-1 w-full rounded-lg border border-slate-300 p-2 text-sm" /></label>
          <label className="text-xs font-semibold text-slate-600">{isBn ? 'প্রকাশের সময়' : 'Start time'}<input type="datetime-local" value={startsAt} onChange={e => setStartsAt(e.target.value)} className="mt-1 w-full rounded-lg border border-slate-300 p-2 text-sm" /></label>
          <label className="text-xs font-semibold text-slate-600">{isBn ? 'মেয়াদ শেষ' : 'End time'}<input type="datetime-local" value={endsAt} onChange={e => setEndsAt(e.target.value)} className="mt-1 w-full rounded-lg border border-slate-300 p-2 text-sm" /></label>
        </div>
        <div className="flex gap-2"><button disabled={busy || !title.trim() || !body.trim()} className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-bold text-white disabled:opacity-50">{isBn ? (editingId ? 'পরিবর্তন সংরক্ষণ' : 'নোটিশ সংরক্ষণ') : (editingId ? 'Save changes' : 'Save notice')}</button>{editingId && <button type="button" onClick={cancelEdit} className="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-600"><X className="h-4 w-4"/>Cancel</button>}</div>
      </form>}

      <div className="space-y-3">
        {items.length === 0 && <div className="rounded-xl border border-dashed border-slate-300 bg-white p-8 text-center text-sm text-slate-500">{busy ? 'Loading…' : (isBn ? 'কোনো নোটিশ নেই।' : 'No notices found.')}</div>}
        {items.map(item => <article key={item.id} className="rounded-xl border border-slate-200 bg-white p-4">
          <div className="flex flex-wrap items-start justify-between gap-2">
            <div><h3 className="font-bold text-slate-900">{item.title}</h3><p className="mt-1 whitespace-pre-wrap text-sm text-slate-600">{item.body}</p></div>
            <span className="rounded-full bg-slate-100 px-2 py-1 text-[10px] font-bold uppercase text-slate-600">{item.status}</span>
          </div>
          <div className="mt-3 flex flex-wrap items-center justify-between gap-2 text-xs text-slate-500">
            <span>{item.audience_role} · {item.branch_id ? `Branch #${item.branch_id}` : 'All branches'} · {item.read_count ?? 0} {isBn ? 'পড়েছেন' : 'reads'}</span>
            {isAdmin ? <div className="flex gap-2">
              <button onClick={() => beginEdit(item)} className="inline-flex items-center gap-1 rounded-md border border-blue-200 px-2 py-1 text-blue-700"><Pencil className="h-3 w-3"/>{isBn ? 'সম্পাদনা' : 'Edit'}</button>
              {item.status !== 'published' && <button onClick={() => void setStatus(item.id, 'published')} className="rounded-md border border-emerald-200 px-2 py-1 text-emerald-700">{isBn ? 'প্রকাশ' : 'Publish'}</button>}
              {item.status !== 'archived' && <button onClick={() => void setStatus(item.id, 'archived')} className="flex items-center gap-1 rounded-md border border-slate-200 px-2 py-1"><Archive className="h-3 w-3" />{isBn ? 'আর্কাইভ' : 'Archive'}</button>}
            </div> : !(item as any).is_read && <button onClick={() => void markRead(item.id)} className="rounded-md border border-blue-200 px-2 py-1 text-blue-700">{isBn ? 'পড়া হয়েছে চিহ্নিত করুন' : 'Mark as read'}</button>}
          </div>
        </article>)}
      </div>
    </div>
  );
};
