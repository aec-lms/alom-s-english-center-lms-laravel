import React, { useEffect, useState } from 'react';
import { UserRound, Save, LoaderCircle, ShieldCheck } from 'lucide-react';
import { accountApi } from '../../services/accountApi';
import { apiClient } from '../../services/apiClient';

export const ProfileSettings: React.FC = () => {
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [phone, setPhone] = useState('');
  const [role, setRole] = useState('');
  const [verified, setVerified] = useState(false);
  const [phoneVerified, setPhoneVerified] = useState(false);
  const [otp, setOtp] = useState('');
  const [otpSent, setOtpSent] = useState(false);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  useEffect(() => {
    let active = true;
    accountApi.getProfile().then(result => {
      if (!active) return;
      if (!result.success || !result.data) { setError(result.error || 'Could not load your profile.'); return; }
      setName(result.data.name || '');
      setEmail(result.data.email || '');
      setPhone(result.data.phone || '');
      setRole(result.data.role || '');
      setVerified(Boolean(result.data.email_verified_at));
      setPhoneVerified(Boolean(result.data.phone_verified_at));
    }).catch(() => { if (active) setError('Could not load your profile.'); })
      .finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, []);

  const save = async (event: React.FormEvent) => {
    event.preventDefault();
    setSaving(true); setError(''); setNotice('');
    const session = await apiClient.auth.me();
    const csrf = session.data?.csrf_token;
    if (!session.success || !csrf) {
      setSaving(false); setError(session.error || 'Your session expired. Sign in again.'); return;
    }
    const result = await accountApi.updateProfile(name.trim(), phone.trim(), csrf);
    setSaving(false);
    if (!result.success) { setError(result.error || 'Profile update failed.'); return; }
    const refreshed = await accountApi.getProfile();
    if (refreshed.success && refreshed.data) {
      setVerified(Boolean(refreshed.data.email_verified_at));
      setPhoneVerified(Boolean(refreshed.data.phone_verified_at));
    }
    setNotice('Profile updated successfully.');
  };

  return <section className="mx-auto max-w-2xl space-y-5">
    <div className="flex items-center gap-3"><div className="rounded-xl bg-blue-50 p-3 text-blue-700"><UserRound className="h-6 w-6"/></div><div><h2 className="text-lg font-bold text-slate-900">My Profile</h2><p className="text-xs text-slate-500">Manage your personal account information.</p></div></div>
    {error && <div role="alert" className="rounded-lg border border-rose-200 bg-rose-50 p-3 text-xs text-rose-700">{error}</div>}
    {notice && <div role="status" className="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-xs text-emerald-800">{notice}</div>}
    {loading ? <div className="rounded-xl border bg-white p-6 text-sm text-slate-500">Loading profile…</div> : <form onSubmit={save} className="space-y-4 rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
      <label className="block space-y-1 text-xs font-semibold text-slate-700">Full name<input required maxLength={160} value={name} onChange={e => setName(e.target.value)} className="w-full rounded-lg border border-slate-300 px-3 py-2.5 font-normal"/></label>
      <label className="block space-y-1 text-xs font-semibold text-slate-700">Email address<input disabled value={email} className="w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 font-normal text-slate-500"/></label>
      <label className="block space-y-1 text-xs font-semibold text-slate-700">Phone number<input type="tel" maxLength={32} value={phone} onChange={e => setPhone(e.target.value)} className="w-full rounded-lg border border-slate-300 px-3 py-2.5 font-normal"/></label>
      <div className="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-slate-50 px-3 py-2 text-xs"><span className="capitalize text-slate-600">Account role: {role.replace('_', ' ')}</span><span className={verified ? 'text-emerald-700' : 'text-amber-700'}>{verified ? 'Email verified' : 'Email not verified'}</span></div>
      {!verified && <button type="button" onClick={async () => { setError(''); setNotice(''); const result = await apiClient.accountVerification.requestEmail(); if (result.success) setNotice('Verification email sent. Check your inbox and spam folder.'); else setError(result.error || 'Could not send verification email.'); }} className="rounded-lg border border-blue-200 px-3 py-2 text-xs font-semibold text-blue-700">Send email verification link</button>}
      <div className="space-y-2 rounded-lg border border-slate-200 p-3">
        <div className="flex items-center justify-between text-xs"><span className="font-semibold text-slate-700">Mobile verification</span><span className={phoneVerified ? 'text-emerald-700' : 'text-amber-700'}>{phoneVerified ? 'Phone verified' : 'Phone not verified'}</span></div>
        {!phoneVerified && <div className="flex flex-wrap gap-2">
          <button type="button" disabled={!phone.trim()} onClick={async () => { setError(''); const result = await apiClient.accountVerification.requestPhone(phone.trim()); if (result.success) { setOtpSent(true); setNotice('SMS verification code sent.'); } else setError(result.error || 'Could not send SMS code.'); }} className="rounded-lg border border-blue-200 px-3 py-2 text-xs font-semibold text-blue-700">Send SMS OTP</button>
          {otpSent && <><input aria-label="Six digit OTP" inputMode="numeric" maxLength={6} value={otp} onChange={e => setOtp(e.target.value.replace(/\\D/g, '').slice(0, 6))} placeholder="6-digit code" className="w-32 rounded-lg border border-slate-300 px-3 py-2 text-xs"/><button type="button" disabled={otp.length !== 6} onClick={async () => { setError(''); const result = await apiClient.accountVerification.verifyPhone(otp); if (result.success) { setPhoneVerified(true); setOtpSent(false); setOtp(''); setNotice('Phone number verified successfully.'); } else setError(result.error || 'OTP verification failed.'); }} className="rounded-lg bg-emerald-600 px-3 py-2 text-xs font-semibold text-white disabled:opacity-50">Verify code</button></>}
        </div>}
        <p className="text-[10px] text-slate-500">Use international format, e.g. +8801XXXXXXXXX. OTP expires in 5 minutes; maximum 5 attempts and 3 requests per 30 minutes.</p>
      </div>
      <button disabled={saving} className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2.5 text-xs font-bold text-white disabled:opacity-60">{saving ? <LoaderCircle className="h-4 w-4 animate-spin"/> : <Save className="h-4 w-4"/>}Save profile</button>
    </form>}
    <p className="flex items-start gap-2 text-[11px] leading-relaxed text-slate-500"><ShieldCheck className="h-4 w-4 shrink-0"/> Your email and account role cannot be changed from this page. Contact an administrator if those details need correction.</p>
  </section>;
};
