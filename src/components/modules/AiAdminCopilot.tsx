import React, { useState } from 'react';
import { Bot, Send, ShieldCheck, Sparkles, Loader2, RotateCcw, CheckCircle2, AlertCircle, Eye, Undo2 } from 'lucide-react';
import { apiClient } from '../../services/apiClient';

type Proposal = { action_type: string; payload: Record<string, unknown> };
type ActionPreview = { proposal_id: number; expires_in_seconds: number; preview: Record<string, unknown> };
type Message = {
  role: 'user' | 'assistant';
  content: string;
  proposal?: Proposal;
  preview?: ActionPreview;
  actionStatus?: 'pending' | 'applied' | 'rolled_back';
  actionError?: string;
  applyPhrase?: string;
  rollbackPhrase?: string;
};

export const AiAdminCopilot: React.FC = () => {
  const [messages, setMessages] = useState<Message[]>([{
    role: 'assistant',
    content: 'আসসালামু আলাইকুম! আমি AEC LMS AI Copilot। আমি অনুমোদিত কিছু সেটিংস, নোটিশ ও Boost campaign পরিবর্তনের প্রস্তাব দিতে পারি। কোনো পরিবর্তন কার্যকর হওয়ার আগে আপনি preview দেখে APPLY লিখে অনুমোদন দেবেন।',
  }]);
  const [prompt, setPrompt] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');

  const updateMessage = (index: number, patch: Partial<Message>) => {
    setMessages(current => current.map((message, i) => i === index ? { ...message, ...patch } : message));
  };

  const send = async (event: React.FormEvent) => {
    event.preventDefault();
    const message = prompt.trim();
    if (!message || busy) return;
    setMessages(current => [...current, { role: 'user', content: message }]);
    setPrompt('');
    setError('');
    setBusy(true);
    const result = await apiClient.aiAssistant.chat(message);
    if (!result.success || !result.data?.reply) {
      setBusy(false);
      setError(result.error || 'AI request failed. Please try again.');
      return;
    }
    const reply = result.data.reply;
    const proposal = result.data.proposal;
    if (proposal) {
      const preview = await apiClient.aiActions.preview(proposal.action_type, proposal.payload);
      if (preview.success && preview.data) {
        setMessages(current => [...current, {
          role: 'assistant', content: reply, proposal, preview: preview.data, actionStatus: 'pending',
        }]);
      } else {
        setMessages(current => [...current, {
          role: 'assistant', content: result.data!.reply, proposal,
          actionError: preview.error || 'The server rejected this action proposal.',
        }]);
      }
    } else {
      setMessages(current => [...current, { role: 'assistant', content: reply }]);
    }
    setBusy(false);
  };

  const approve = async (index: number, message: Message) => {
    if (!message.preview || message.applyPhrase !== 'APPLY' || busy) return;
    setBusy(true);
    const result = await apiClient.aiActions.approve(message.preview.proposal_id, 'APPLY');
    if (result.success) updateMessage(index, { actionStatus: 'applied', actionError: undefined, content: message.content + '\n\nপরিবর্তনটি Admin approval-এর পর প্রয়োগ হয়েছে।' });
    else updateMessage(index, { actionError: result.error || 'Could not apply the approved action.' });
    setBusy(false);
  };

  const rollback = async (index: number, message: Message) => {
    if (!message.preview || message.rollbackPhrase !== 'ROLLBACK' || busy) return;
    setBusy(true);
    const result = await apiClient.aiActions.rollback(message.preview.proposal_id, 'ROLLBACK');
    if (result.success) updateMessage(index, { actionStatus: 'rolled_back', actionError: undefined, content: message.content + '\n\nপরিবর্তনটি backup থেকে rollback করা হয়েছে।' });
    else updateMessage(index, { actionError: result.error || 'Rollback was refused or failed.' });
    setBusy(false);
  };

  return <div className="mx-auto max-w-5xl space-y-5">
    <div className="rounded-2xl bg-gradient-to-r from-slate-950 via-blue-950 to-indigo-950 p-6 text-white shadow-lg">
      <div className="flex items-start gap-4">
        <div className="rounded-xl bg-white/10 p-3"><Bot className="h-7 w-7"/></div>
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-2">
            <h2 className="text-xl font-bold">AI Admin Copilot</h2>
            <span className="rounded-full border border-emerald-300/30 bg-emerald-300/10 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider text-emerald-200">Approval required</span>
          </div>
          <p className="mt-2 max-w-2xl text-sm leading-6 text-blue-100">নিরাপদভাবে marketing/SEO settings, Notice Board ও Boost campaign পরিবর্তনের প্রস্তাব তৈরি করুন। Preview, backup ও আপনার অনুমোদন ছাড়া কোনো পরিবর্তন কার্যকর হবে না।</p>
        </div>
      </div>
    </div>

    <div className="rounded-2xl border border-slate-200 bg-white shadow-sm">
      <div className="flex items-center justify-between border-b border-slate-100 px-5 py-4">
        <div className="flex items-center gap-2 text-sm font-bold text-slate-800"><Sparkles className="h-4 w-4 text-blue-600"/> Assistant conversation</div>
        <button type="button" onClick={() => { setMessages([{ role: 'assistant', content: 'নতুন আলোচনা শুরু হয়েছে। কী পরিবর্তন করতে চান?' }]); setError(''); }} className="inline-flex items-center gap-1.5 rounded-lg px-3 py-2 text-xs font-semibold text-slate-500 hover:bg-slate-100"><RotateCcw className="h-3.5 w-3.5"/> Clear</button>
      </div>

      <div className="max-h-[620px] min-h-[260px] space-y-4 overflow-y-auto p-5" aria-live="polite">
        {messages.map((item, index) => <div key={index} className={`flex ${item.role === 'user' ? 'justify-end' : 'justify-start'}`}>
          <div className={`w-full max-w-[94%] rounded-2xl px-4 py-3 text-sm leading-6 ${item.role === 'user' ? 'bg-blue-600 text-white sm:max-w-[82%]' : 'border border-slate-200 bg-slate-50 text-slate-800'}`}>
            <div className="whitespace-pre-wrap">{item.content}</div>

            {item.proposal && <div className="mt-3 rounded-xl border border-indigo-200 bg-white p-3 text-slate-800">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="flex items-center gap-2 text-xs font-bold"><Eye className="h-4 w-4 text-indigo-600"/> Change preview · {item.proposal.action_type}</div>
                {item.actionStatus === 'pending' && <span className="rounded-full bg-amber-50 px-2 py-1 text-[10px] font-bold text-amber-700">Awaiting approval</span>}
                {item.actionStatus === 'applied' && <span className="rounded-full bg-emerald-50 px-2 py-1 text-[10px] font-bold text-emerald-700">Applied</span>}
                {item.actionStatus === 'rolled_back' && <span className="rounded-full bg-slate-100 px-2 py-1 text-[10px] font-bold text-slate-600">Rolled back</span>}
              </div>
              {item.preview && <details className="mt-3">
                <summary className="cursor-pointer text-xs font-semibold text-indigo-700">View before / after diff ({Math.floor(item.preview.expires_in_seconds / 60)} min preview validity)</summary>
                <pre className="mt-2 max-h-72 overflow-auto rounded-lg bg-slate-950 p-3 text-[10px] leading-5 text-emerald-200">{JSON.stringify(item.preview.preview, null, 2)}</pre>
              </details>}
              {item.actionError && <p role="alert" className="mt-3 rounded-lg bg-rose-50 p-2 text-xs text-rose-700"><AlertCircle className="mr-1 inline h-3.5 w-3.5"/>{item.actionError}</p>}
              {item.actionStatus === 'pending' && item.preview && <div className="mt-3 flex flex-wrap items-center gap-2">
                <input value={item.applyPhrase || ''} onChange={e => updateMessage(index, { applyPhrase: e.target.value })} placeholder="Type APPLY to confirm" aria-label="Type APPLY to approve" className="min-w-[170px] flex-1 rounded-lg border border-slate-300 px-3 py-2 text-xs"/>
                <button type="button" disabled={busy || item.applyPhrase !== 'APPLY'} onClick={() => void approve(index, item)} className="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-3 py-2 text-xs font-bold text-white disabled:opacity-40"><CheckCircle2 className="h-3.5 w-3.5"/> Approve & apply</button>
              </div>}
              {item.actionStatus === 'applied' && <div className="mt-3 flex flex-wrap items-center gap-2">
                <input value={item.rollbackPhrase || ''} onChange={e => updateMessage(index, { rollbackPhrase: e.target.value })} placeholder="Type ROLLBACK" aria-label="Type ROLLBACK to undo" className="min-w-[170px] flex-1 rounded-lg border border-slate-300 px-3 py-2 text-xs"/>
                <button type="button" disabled={busy || item.rollbackPhrase !== 'ROLLBACK'} onClick={() => void rollback(index, item)} className="inline-flex items-center gap-1.5 rounded-lg border border-rose-200 px-3 py-2 text-xs font-bold text-rose-700 disabled:opacity-40"><Undo2 className="h-3.5 w-3.5"/> Rollback</button>
              </div>}
            </div>}
          </div>
        </div>)}
        {busy && <div className="flex items-center gap-2 text-xs text-slate-500"><Loader2 className="h-4 w-4 animate-spin"/> AI / approval operation চলছে…</div>}
      </div>

      {error && <p role="alert" className="mx-5 mb-3 rounded-lg bg-rose-50 px-3 py-2 text-xs text-rose-700">{error}</p>}
      <form onSubmit={send} className="flex gap-2 border-t border-slate-100 p-4">
        <input value={prompt} onChange={e => setPrompt(e.target.value)} maxLength={4000} disabled={busy} placeholder="যেমন: SEO title পরিবর্তন করে দিন অথবা একটি নোটিশ তৈরি করুন…" className="min-w-0 flex-1 rounded-xl border border-slate-200 px-4 py-3 text-sm outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100 disabled:bg-slate-50"/>
        <button type="submit" disabled={busy || !prompt.trim()} className="inline-flex items-center gap-2 rounded-xl bg-blue-600 px-4 py-3 text-sm font-bold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50"><Send className="h-4 w-4"/><span className="hidden sm:inline">Send</span></button>
      </form>
    </div>

    <div className="rounded-xl border border-amber-200 bg-amber-50 p-4 text-xs leading-5 text-amber-900">
      <div className="mb-1 flex items-center gap-2 font-bold"><ShieldCheck className="h-4 w-4"/> নিরাপত্তা সীমা</div>
      AI শুধু allowlisted typed action প্রস্তাব করে। Server প্রতিটি field পুনরায় যাচাই করে, ১০ মিনিটের preview তৈরি করে, fresh Admin approval ও backup ছাড়া পরিবর্তন প্রয়োগ করে না। Arbitrary SQL, shell, PHP, user role/password, payment বা financial পরিবর্তন অনুমোদিত নয়। Rollback-ও ROLLBACK লিখে আলাদাভাবে অনুমোদন করতে হবে।
    </div>
  </div>;
};
