import React, { useEffect, useState } from 'react';
import { useLms } from '../../context/LmsContext';
import { accountApi } from '../../services/accountApi';
import {
  LoaderCircle, User, Phone, Mail, Lock, Eye, EyeOff, KeyRound
} from 'lucide-react';
import { BrandLogo } from '../common/BrandLogo';

type AccountMode = 'login' | 'register' | 'forgot' | 'reset';

export const LoginPage: React.FC = () => {
  const { signIn, setActiveView, language, websiteSettings } = useLms();
  const isBn = language === 'bn';
  const params = new URLSearchParams(window.location.search);
  const initialAction = params.get('account_action');
  const token = params.get('token') || '';

  const [mode, setMode] = useState<AccountMode>(initialAction === 'reset_password' ? 'reset' : 'login');
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [phone, setPhone] = useState('');
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  const theme = websiteSettings.themeSettings;
  const canvasBg = `linear-gradient(135deg, ${theme?.lightCanvasStart || '#EAE9FF'} 0%, ${theme?.lightCanvasMid || '#F2F3FF'} 45%, ${theme?.lightCanvasEnd || '#E5F4FE'} 100%)`;
  const brandTitle = isBn
    ? (websiteSettings.siteNameBn || "আলম'স ইংলিশ সেন্টার")
    : (websiteSettings.logoText || "ALOM'S");
  const brandTagline = !isBn ? (websiteSettings.logoTagline || 'ENGLISH CENTER') : '';

  useEffect(() => {
    if (initialAction !== 'verify_email') return;
    if (!token) {
      setError('This verification link is invalid or incomplete.');
      return;
    }
    let active = true;
    setLoading(true);
    accountApi.verifyEmail(token).then(result => {
      if (!active) return;
      if (result.success) {
        setNotice(result.message || 'Email verified. You can now sign in.');
        setMode('login');
        window.history.replaceState({}, document.title, window.location.pathname);
      } else {
        setError(result.error || 'This verification link is invalid or expired.');
      }
    }).catch(() => {
      if (active) setError('Could not verify this link. Please try again.');
    }).finally(() => {
      if (active) setLoading(false);
    });
    return () => { active = false; };
  }, []);

  const submitLogin = async (event: React.FormEvent) => {
    event.preventDefault();
    setLoading(true);
    setError('');
    setNotice('');
    const result = await signIn(email.trim(), password);
    setLoading(false);
    if (!result.success) {
      setError(result.error || 'Login failed. Check your credentials and try again.');
      return;
    }
    setActiveView('dashboard');
  };

  const submitRegister = async (event: React.FormEvent) => {
    event.preventDefault();
    setLoading(true);
    setError('');
    setNotice('');
    const result = await accountApi.register({
      name: name.trim(),
      email: email.trim(),
      phone: phone.trim(),
      password
    });
    setLoading(false);
    if (!result.success) {
      setError(result.error || 'Registration failed.');
      return;
    }
    setNotice(result.message || 'Registration received. Check your email to verify your account.');
    setMode('login');
    setPassword('');
  };

  const submitForgot = async (event: React.FormEvent) => {
    event.preventDefault();
    setLoading(true);
    setError('');
    setNotice('');
    const result = await accountApi.requestPasswordReset(email.trim());
    setLoading(false);
    if (!result.success) {
      setError(result.error || 'Could not process the request.');
      return;
    }
    setNotice(result.message || 'If an eligible account exists, password reset instructions have been sent.');
    setMode('login');
  };

  const resendVerification = async () => {
    if (!email.trim()) {
      setError('Enter your registration email address first.');
      return;
    }
    setLoading(true);
    setError('');
    setNotice('');
    const result = await accountApi.resendVerification(email.trim());
    setLoading(false);
    if (!result.success) {
      setError(result.error || 'Could not process the request.');
      return;
    }
    setNotice(result.message || 'If an unverified account exists, a new verification link has been sent.');
  };

  const submitReset = async (event: React.FormEvent) => {
    event.preventDefault();
    if (!token) {
      setError('This reset link is invalid or incomplete.');
      return;
    }
    setLoading(true);
    setError('');
    setNotice('');
    const result = await accountApi.resetPassword(token, password);
    setLoading(false);
    if (!result.success) {
      setError(result.error || 'Password reset failed.');
      return;
    }
    setNotice(result.message || 'Password reset successful. Sign in with your new password.');
    setPassword('');
    setMode('login');
    window.history.replaceState({}, document.title, window.location.pathname);
  };

  const heading = mode === 'register'
    ? (isBn ? 'শিক্ষার্থী অ্যাকাউন্ট তৈরি করুন' : 'Create Student Account')
    : mode === 'forgot'
    ? (isBn ? 'পাসওয়ার্ড রিসেট করুন' : 'Reset Your Password')
    : mode === 'reset'
    ? (isBn ? 'নতুন পাসওয়ার্ড সেট করুন' : 'Choose a New Password')
    : (isBn ? 'স্বাগতম!' : 'Welcome Back!');

  const subheading = mode === 'register'
    ? (isBn ? 'নিবন্ধনের পর ইমেইল যাচাই করে লগইন করুন' : 'Register as a student to access courses and exams')
    : mode === 'forgot'
    ? (isBn ? 'আপনার নিবন্ধিত ইমেইল ঠিকানা লিখুন' : 'Enter your registered email address')
    : mode === 'reset'
    ? (isBn ? 'কমপক্ষে ১২ অক্ষরের শক্তিশালী পাসওয়ার্ড দিন' : 'Use a strong password with at least 12 characters')
    : (isBn ? 'চালিয়ে যেতে আপনার অ্যাকাউন্টে সাইন ইন করুন' : 'Please sign in to continue');

  const submitHandler =
    mode === 'register'
      ? submitRegister
      : mode === 'forgot'
      ? submitForgot
      : mode === 'reset'
      ? submitReset
      : submitLogin;

  return (
    <main
      className="flex-1 min-h-[calc(100vh-4rem)] w-full flex items-center justify-center px-4 py-12 sm:py-16"
      style={{ background: canvasBg }}
    >
      <section className="w-full max-w-[440px] rounded-2xl bg-white border border-white/90 px-7 sm:px-9 py-9 shadow-[0_20px_50px_-15px_rgba(15,23,42,0.12)]">
        {/* Centered Brand Lockup */}
        <button
          type="button"
          onClick={() => setActiveView('home')}
          className="mx-auto mb-5 flex items-center justify-center cursor-pointer focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#0057FF] rounded-lg"
        >
          <BrandLogo
            title={brandTitle}
            tagline={brandTagline}
            size="lg"
            logoStyle={websiteSettings.logoStyle}
            logoUrl={websiteSettings.logoUrl}
          />
        </button>

        {/* Heading & Subtitle */}
        <div className="text-center mb-6">
          <h1 className="font-heading text-xl sm:text-[22px] font-extrabold text-slate-900 tracking-tight">
            {heading}
          </h1>
          <p className="mt-1 text-xs text-slate-500">
            {subheading}
          </p>
        </div>

        {error && (
          <div role="alert" className="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-3.5 py-2.5 text-xs font-medium text-rose-700">
            {error}
          </div>
        )}
        {notice && (
          <div role="status" className="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-3.5 py-2.5 text-xs font-medium text-emerald-800">
            {notice}
          </div>
        )}
        {loading && initialAction === 'verify_email' && (
          <p className="mb-4 text-center text-xs text-slate-500">Verifying your email address…</p>
        )}

        {/* Icon-Prefixed Form */}
        <form onSubmit={submitHandler} className="space-y-3.5">
          {mode === 'register' && (
            <>
              <div className="relative">
                <User className="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2 pointer-events-none" />
                <input
                  required
                  maxLength={160}
                  autoComplete="name"
                  placeholder={isBn ? 'আপনার পূর্ণ নাম' : 'Full Name'}
                  value={name}
                  onChange={e => setName(e.target.value)}
                  className="w-full rounded-lg border border-slate-200 bg-white pl-10 pr-3.5 py-2.5 text-sm text-slate-900 placeholder:text-slate-400 outline-none focus:border-[#0057FF] focus:ring-2 focus:ring-[#0057FF]/15 transition-colors"
                />
              </div>

              <div className="relative">
                <Phone className="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2 pointer-events-none" />
                <input
                  type="tel"
                  maxLength={32}
                  autoComplete="tel"
                  placeholder={isBn ? 'মোবাইল নম্বর' : 'Phone Number'}
                  value={phone}
                  onChange={e => setPhone(e.target.value)}
                  className="w-full rounded-lg border border-slate-200 bg-white pl-10 pr-3.5 py-2.5 text-sm text-slate-900 placeholder:text-slate-400 outline-none focus:border-[#0057FF] focus:ring-2 focus:ring-[#0057FF]/15 transition-colors"
                />
              </div>
            </>
          )}

          {mode !== 'reset' && (
            <div className="relative">
              <Mail className="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2 pointer-events-none" />
              <input
                required
                type="email"
                autoComplete="email"
                placeholder={isBn ? 'ইমেইল ঠিকানা' : 'Email Address'}
                value={email}
                onChange={e => setEmail(e.target.value)}
                className="w-full rounded-lg border border-slate-200 bg-white pl-10 pr-3.5 py-2.5 text-sm text-slate-900 placeholder:text-slate-400 outline-none focus:border-[#0057FF] focus:ring-2 focus:ring-[#0057FF]/15 transition-colors"
              />
            </div>
          )}

          {(mode === 'login' || mode === 'register' || mode === 'reset') && (
            <div className="relative">
              {mode === 'reset' ? (
                <KeyRound className="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2 pointer-events-none" />
              ) : (
                <Lock className="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2 pointer-events-none" />
              )}
              <input
                required
                minLength={mode === 'login' ? 1 : 12}
                type={showPassword ? 'text' : 'password'}
                autoComplete={mode === 'login' ? 'current-password' : 'new-password'}
                placeholder={mode === 'login' ? (isBn ? 'পাসওয়ার্ড' : 'Password') : (isBn ? 'নতুন পাসওয়ার্ড' : 'New Password')}
                value={password}
                onChange={e => setPassword(e.target.value)}
                className="w-full rounded-lg border border-slate-200 bg-white pl-10 pr-10 py-2.5 text-sm text-slate-900 placeholder:text-slate-400 outline-none focus:border-[#0057FF] focus:ring-2 focus:ring-[#0057FF]/15 transition-colors"
              />
              <button
                type="button"
                onClick={() => setShowPassword(prev => !prev)}
                aria-label={showPassword ? 'Hide password' : 'Show password'}
                className="absolute right-3 top-1/2 -translate-y-1/2 p-1 text-slate-400 hover:text-slate-600 cursor-pointer"
              >
                {showPassword ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
              </button>
            </div>
          )}

          <button
            type="submit"
            disabled={loading}
            className="w-full flex items-center justify-center gap-2 rounded-lg bg-[#0057FF] hover:bg-[#0046CC] px-4 py-2.5 text-sm font-bold text-white shadow-sm transition-colors disabled:opacity-60 cursor-pointer"
          >
            {loading && <LoaderCircle className="h-4 w-4 animate-spin" />}
            <span>
              {loading
                ? (isBn ? 'অপেক্ষা করুন…' : 'Please wait…')
                : mode === 'register'
                ? (isBn ? 'অ্যাকাউন্ট তৈরি করুন' : 'Sign Up')
                : mode === 'forgot'
                ? (isBn ? 'রিসেট লিংক পাঠান' : 'Send Reset Instructions')
                : mode === 'reset'
                ? (isBn ? 'পাসওয়ার্ড সংরক্ষণ করুন' : 'Save New Password')
                : (isBn ? 'সাইন ইন' : 'Sign In')}
            </span>
          </button>
        </form>

        {/* Recovery & Verification Helper Links */}
        {mode === 'login' && (
          <div className="mt-4 flex flex-col items-center gap-2 text-xs font-medium text-[#2563EB]">
            <button
              type="button"
              onClick={() => { setMode('forgot'); setError(''); setNotice(''); }}
              className="hover:underline cursor-pointer"
            >
              {isBn ? 'পাসওয়ার্ড ভুলে গেছেন?' : 'Forgot your password?'}
            </button>
            <button
              type="button"
              onClick={() => void resendVerification()}
              disabled={loading}
              className="hover:underline cursor-pointer disabled:opacity-60"
            >
              {isBn ? 'ভেরিফিকেশন ইমেইল পুনরায় পাঠান' : 'Resend verification email'}
            </button>
          </div>
        )}

        {/* OR Divider */}
        <div className="my-5 flex items-center gap-3">
          <div className="h-px flex-1 bg-slate-200" />
          <span className="text-[10px] font-bold uppercase tracking-wider text-slate-400">
            {isBn ? 'অথবা' : 'OR'}
          </span>
          <div className="h-px flex-1 bg-slate-200" />
        </div>

        {/* Account Mode Toggle */}
        <div className="text-center text-xs text-slate-600">
          {mode === 'login' ? (
            <>
              <span>{isBn ? 'অ্যাকাউন্ট নেই? ' : "Don't have an account? "}</span>
              <button
                type="button"
                onClick={() => { setMode('register'); setError(''); setNotice(''); }}
                className="font-bold text-[#2563EB] hover:underline cursor-pointer"
              >
                {isBn ? 'সাইন আপ করুন' : 'Sign Up'}
              </button>
            </>
          ) : (
            <>
              <span>{isBn ? 'ইতিমধ্যে অ্যাকাউন্ট আছে? ' : 'Already have an account? '}</span>
              <button
                type="button"
                onClick={() => { setMode('login'); setError(''); setNotice(''); }}
                className="font-bold text-[#2563EB] hover:underline cursor-pointer"
              >
                {isBn ? 'সাইন ইন করুন' : 'Sign In'}
              </button>
            </>
          )}
        </div>

        {/* Terms & Privacy Caption */}
        <p className="mt-5 text-center text-[10px] leading-relaxed text-slate-400">
          By continuing, you agree to our{' '}
          <button type="button" onClick={() => setActiveView('about')} className="text-[#2563EB] hover:underline cursor-pointer">
            Terms of Service
          </button>{' '}
          and{' '}
          <button type="button" onClick={() => setActiveView('contact')} className="text-[#2563EB] hover:underline cursor-pointer">
            Privacy Policy
          </button>
        </p>
      </section>
    </main>
  );
};
