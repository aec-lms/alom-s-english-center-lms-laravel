import React, { useCallback, useEffect, useState } from 'react';
import { Megaphone, RefreshCw, Plus, Pause, Play, XCircle, AlertCircle } from 'lucide-react';
import { apiClient } from '../../services/apiClient';
import { useLms } from '../../context/LmsContext';

type Campaign = {
  id: number; campaign_name: string; entity_type: string; entity_id: number | null; placement: string;
  status: string; starts_at: string; ends_at: string; priority: number; impressions: number; clicks: number; conversions: number;
};

export const PromotionManager: React.FC = () => {
  const { language } = useLms();
  const isBn = language === 'bn';
  const [rows, setRows] = useState<Campaign[]>([]);
  const [name, setName] = useState('');
  const [entityType, setEntityType] = useState<'course' | 'institution' | 'branch'>('course');
  const [entityId, setEntityId] = useState('');
  const [placement, setPlacement] = useState<'homepage_featured' | 'course_listing' | 'branch_directory' | 'notice_banner'>('homepage_featured');
  const [startsAt, setStartsAt] = useState('');
  const [endsAt, setEndsAt] = useState('');
  const [priority, setPriority] = useState('5');
  const [destination, setDestination] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  const load = useCallback(async () => {
    setBusy(true); setError('');
    const result = await apiClient.promotions.list();
    if (result.success) setRows((result.data || []) as Campaign[]);
    else setError(result.error || 'Could not load boost campaigns.');
    setBusy(false);
  }, []);
  useEffect(() => { void load(); }, [load]);

  const create = async (event: React.FormEvent) => {
    event.preventDefault(); setBusy(true); setError(''); setNotice('');
    const result = await apiClient.promotions.create({
      campaign_name: name.trim(), entity_type: entityType,
      entity_id: entityType === 'institution' ? null : Number(entityId),
      placement, starts_at: startsAt.replace('T', ' ') + ':00',
      ends_at: endsAt.replace('T', ' ') + ':00',
      priority: Number(priority), destination_url: destination.trim() || undefined,
    });
    if (result.success) {
      setName(''); setEntityId(''); setDestination('');
      setNotice(isBn ? 'প্রমোশন ক্যাম্পেইন তৈরি হয়েছে।' : 'Boost campaign created.');
      await load();
    } else setError(result.error || 'Could not create campaign.');
    setBusy(false);
  };

  const update = async (id: number, status: 'active' | 'paused' | 'cancelled') => {
    setBusy(true); setError('');
    const result = await apiClient.promotions.updateStatus(id, status);
    if (result.success) await load(); else setError(result.error || 'Could not update campaign.');
    setBusy(false);
  };

  return <div className="space-y-5">
    <div className="flex items-center justify-between rounded-2xl border border-slate-200 bg-white p-5">
      <div className="flex items-center gap-3"><div className="rounded-xl bg-indigo-50 p-3 text-indigo-700"><Megaphone className="h-5 w-5"/></div><div><h2 className="text-lg font-bold text-slate-900">{isBn ? 'কোর্স ও প্রতিষ্ঠান Boost' : 'Course & Institution Boost'}</h2><p className="text-xs text-slate-500">{isBn ? 'ক্যাম্পেইন, সময়সূচি এবং ফলাফল' : 'Promote courses, institution and branches with scheduling and analytics'}</p></div></div>
      <button onClick={() => void load()} disabled={busy} className="rounded-lg border border-slate-200 p-2"><RefreshCw className={`h-4 w-4 ${busy ? 'animate-spin' : ''}`}/></button>
    </div>
    {error && <div role="alert" className="rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700"><AlertCircle className="mr-2 inline h-4 w-4"/>{error}</div>}
    {notice && <div role="status" className="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-700">{notice}</div>}
    <form onSubmit={create} className="space-y-3 rounded-2xl border border-slate-200 bg-white p-5">
      <h3 className="flex items-center gap-2 font-bold text-slate-900"><Plus className="h-4 w-4"/>{isBn ? 'নতুন Boost ক্যাম্পেইন' : 'Create Boost campaign'}</h3>
      <input required maxLength={180} value={name} onChange={e => setName(e.target.value)} placeholder={isBn ? 'ক্যাম্পেইনের নাম' : 'Campaign name'} className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"/>
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <label className="text-xs font-semibold text-slate-600">Boost target<select value={entityType} onChange={e => setEntityType(e.target.value as typeof entityType)} className="mt-1 w-full rounded-lg border border-slate-300 bg-white p-2 text-sm"><option value="course">Course</option><option value="institution">Institution</option><option value="branch">Branch</option></select></label>
        <label className="text-xs font-semibold text-slate-600">Target ID {entityType === 'institution' ? '(not required)' : '(required)'}<input disabled={entityType === 'institution'} required={entityType !== 'institution'} value={entityId} onChange={e => setEntityId(e.target.value)} inputMode="numeric" placeholder="Database ID" className="mt-1 w-full rounded-lg border border-slate-300 p-2 text-sm disabled:bg-slate-100"/></label>
        <label className="text-xs font-semibold text-slate-600">Placement<select value={placement} onChange={e => setPlacement(e.target.value as typeof placement)} className="mt-1 w-full rounded-lg border border-slate-300 bg-white p-2 text-sm"><option value="homepage_featured">Homepage featured</option><option value="course_listing">Course listing</option><option value="branch_directory">Branch directory</option><option value="notice_banner">Notice banner</option></select></label>
        <label className="text-xs font-semibold text-slate-600">Priority (1–10)<input type="number" min="1" max="10" value={priority} onChange={e => setPriority(e.target.value)} className="mt-1 w-full rounded-lg border border-slate-300 p-2 text-sm"/></label>
        <label className="text-xs font-semibold text-slate-600">Starts at<input required type="datetime-local" value={startsAt} onChange={e => setStartsAt(e.target.value)} className="mt-1 w-full rounded-lg border border-slate-300 p-2 text-sm"/></label>
        <label className="text-xs font-semibold text-slate-600">Ends at<input required type="datetime-local" value={endsAt} onChange={e => setEndsAt(e.target.value)} className="mt-1 w-full rounded-lg border border-slate-300 p-2 text-sm"/></label>
        <label className="text-xs font-semibold text-slate-600 sm:col-span-2">Destination URL (optional)<input type="url" value={destination} onChange={e => setDestination(e.target.value)} placeholder="https://…" className="mt-1 w-full rounded-lg border border-slate-300 p-2 text-sm"/></label>
      </div>
      <button disabled={busy || !name.trim() || !startsAt || !endsAt} className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-bold text-white disabled:opacity-50">{isBn ? 'Boost তৈরি করুন' : 'Create campaign'}</button>
    </form>
    <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white">
      <div className="border-b border-slate-100 px-5 py-3 font-bold text-slate-900">{isBn ? 'ক্যাম্পেইন রিপোর্ট' : 'Campaign report'}</div>
      {rows.length === 0 ? <p className="p-6 text-center text-sm text-slate-500">{busy ? 'Loading…' : 'No campaigns yet.'}</p> : <div className="divide-y divide-slate-100">
        {rows.map(row => <div key={row.id} className="space-y-3 p-4">
          <div className="flex flex-wrap items-start justify-between gap-2"><div><h3 className="font-bold text-slate-900">{row.campaign_name}</h3><p className="text-xs text-slate-500">{row.entity_type}{row.entity_id ? ` #${row.entity_id}` : ''} · {row.placement} · Priority {row.priority}</p></div><span className="rounded-full bg-slate-100 px-2 py-1 text-[10px] font-bold uppercase text-slate-600">{row.status}</span></div>
          <div className="grid grid-cols-3 gap-2 text-center text-xs"><div className="rounded-lg bg-slate-50 p-2"><b className="block text-base text-slate-900">{row.impressions || 0}</b>Impressions</div><div className="rounded-lg bg-slate-50 p-2"><b className="block text-base text-slate-900">{row.clicks || 0}</b>Clicks</div><div className="rounded-lg bg-slate-50 p-2"><b className="block text-base text-slate-900">{row.conversions || 0}</b>Conversions</div></div>
          <div className="flex flex-wrap gap-2">{row.status === 'active' && <button onClick={() => void update(row.id,'paused')} className="inline-flex items-center gap-1 rounded-md border border-amber-200 px-2 py-1 text-xs text-amber-700"><Pause className="h-3 w-3"/>Pause</button>}{row.status === 'paused' && <button onClick={() => void update(row.id,'active')} className="inline-flex items-center gap-1 rounded-md border border-emerald-200 px-2 py-1 text-xs text-emerald-700"><Play className="h-3 w-3"/>Resume</button>}{!['cancelled','completed'].includes(row.status) && <button onClick={() => void update(row.id,'cancelled')} className="inline-flex items-center gap-1 rounded-md border border-rose-200 px-2 py-1 text-xs text-rose-700"><XCircle className="h-3 w-3"/>Cancel</button>}</div>
        </div>)}
      </div>}
    </div>
  </div>;
};
