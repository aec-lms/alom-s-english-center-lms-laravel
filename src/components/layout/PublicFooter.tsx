import React, { useState } from 'react';
import { useLms } from '../../context/LmsContext';
import { Phone, Mail, MapPin, ShieldCheck, ArrowRight, FileText, Globe, X } from 'lucide-react';
import { BrandLogo } from '../common/BrandLogo';

interface PublicFooterProps {
  onOpenVerifyModal?: () => void;
  onOpenAdmission?: () => void;
}

export const PublicFooter: React.FC<PublicFooterProps> = ({ onOpenVerifyModal, onOpenAdmission }) => {
  const { language, setLanguage, setActiveView, websiteSettings } = useLms();
  const isBn = language === 'bn';
  const [legalModal, setLegalModal] = useState<'terms' | 'privacy' | 'refund' | null>(null);

  const footerCfg = websiteSettings.footerSettings || {
    missionEn:
      "Alom's English Center is a structured online and campus learning platform providing IELTS, Spoken English, and Grammar programs delivered by certified educators nationwide.",
    missionBn:
      'দেশের প্রত্যন্ত অঞ্চল থেকে শুরু করে প্রতিটি শিক্ষার্থীর কাছে মানসম্মত IELTS ও ইংরেজি শিক্ষাকে সহজলভ্য করতে আমাদের আধুনিক লার্নিং প্ল্যাটফর্ম।',
    licenseNo: '',
    copyrightText: `© ${new Date().getFullYear()} Alom's English Center. All Rights Reserved.`,
    showPaymentStrip: true
  };

  const brandName = isBn
    ? (websiteSettings.siteNameBn || "আলম'স ইংলিশ সেন্টার")
    : (websiteSettings.siteName || "Alom's English Center");

  const displayLicense = footerCfg.licenseNo?.trim() || 'TRAD/DNCC/044200/2026';
  const displayAddress = isBn
    ? (websiteSettings.addressBn || 'প্লট ১৪, সেক্টর ৬, মিরপুর ১০ গোলচত্বর, ঢাকা-১২১৬')
    : (websiteSettings.address || 'Plot 14, Sector 6, Mirpur 10 Roundabout, Dhaka-1216');
  const displayHotline = websiteSettings.hotline?.trim() || '+880 1712 345678';
  const displayEmail = websiteSettings.email?.trim() || 'info@alomsenglishcenter.com';

  const paymentGateways = [
    { name: 'VISA', accent: 'text-[#1A1F71] border-indigo-200 bg-indigo-50/70' },
    { name: 'Mastercard', accent: 'text-[#EB001B] border-red-200 bg-red-50/70' },
    { name: 'AMEX', accent: 'text-[#006FCF] border-sky-200 bg-sky-50/70' },
    { name: 'DBBL Nexus', accent: 'text-[#005B9F] border-blue-200 bg-blue-50/70' },
    { name: 'bKash', accent: 'text-[#E2136E] border-pink-200 bg-pink-50/70' },
    { name: 'Nagad', accent: 'text-[#F6921E] border-orange-200 bg-orange-50/70' },
    { name: 'Rocket', accent: 'text-[#8C3494] border-purple-200 bg-purple-50/70' },
    { name: 'Upay', accent: 'text-[#0066B3] border-sky-200 bg-sky-50/70' },
    { name: 'City Bank', accent: 'text-[#B91C1C] border-rose-200 bg-rose-50/70' },
    { name: 'Islami Bank', accent: 'text-[#047857] border-emerald-200 bg-emerald-50/70' },
    { name: 'UCB', accent: 'text-[#B91C1C] border-red-200 bg-red-50/70' },
    { name: 'MTB', accent: 'text-[#0369A1] border-sky-200 bg-sky-50/70' },
    { name: 'BRAC Bank', accent: 'text-[#0F766E] border-teal-200 bg-teal-50/70' },
    { name: 'EBL', accent: 'text-[#1D4ED8] border-blue-200 bg-blue-50/70' }
  ];

  const legalCopy = {
    terms: {
      title: isBn ? 'শর্তাবলী (Terms & Conditions)' : 'Terms & Conditions',
      body: isBn
        ? "আলম'স ইংলিশ সেন্টারের সকল অনলাইন ও অফলাইন কোর্স, রেকর্ডেড ভিডিও এবং লেকচার শিট কেবলমাত্র নিবন্ধিত শিক্ষার্থীদের ব্যক্তিগত ব্যবহারের জন্য। অনুমতি ব্যতীত কোনো কন্টেন্ট শেয়ার বা পুনঃবিতরণ সম্পূর্ণ নিষিদ্ধ।"
        : "All live classes, recorded lectures, and PDF study handbooks on Alom's English Center are licensed exclusively to enrolled students for personal study. Unauthorized sharing or redistribution is prohibited."
    },
    privacy: {
      title: isBn ? 'গোপনীয়তা নীতি (Privacy Policy)' : 'Privacy Policy',
      body: isBn
        ? 'ভর্তি ও শিক্ষার্থী অ্যাকাউন্ট পরিচালনার জন্য সংগৃহীত আপনার নাম, ফোন নম্বর ও একাডেমিক তথ্য সর্বোচ্চ নিরাপত্তার সাথে সংরক্ষিত থাকে এবং কখনোই তৃতীয় পক্ষের নিকট বিক্রয় বা শেয়ার করা হয় না।'
        : 'Student profile details, attendance records, and mock exam performance data are securely stored strictly for academic administration and certification verification.'
    },
    refund: {
      title: isBn ? 'রিফান্ড নীতিমালা (Refund Policy)' : 'Refund Policy',
      body: isBn
        ? 'অনলাইন পেমেন্টের ক্ষেত্রে ভর্তির ৭২ ঘণ্টার মধ্যে কোনো টেকনিক্যাল সমস্যার কারণে ব্যাচে যুক্ত হতে না পারলে অ্যাডমিশন ডেস্কে যোগাযোগ সাপেক্ষে ফি সমন্বয় বা রিফান্ডের আবেদন করা যাবে।'
        : 'Students encountering technical access issues within 72 hours of online enrollment may request a fee transfer or refund via the Admission Support Desk.'
    }
  };

  const footerTheme = websiteSettings.themeSettings;

  return (
    <footer
      className="pt-14 pb-8 border-t border-slate-200/90"
      style={{
        backgroundColor: footerTheme?.footerBg || '#F3F5F8',
        color: footerTheme?.footerTextColor || '#475569'
      }}
    >
      <div className="max-w-[1200px] mx-auto px-4 sm:px-6">
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-10 pb-10">
          {/* Column 1: Brand Overview + 4 Circular Social / Action Buttons */}
          <div className="space-y-4">
            <div className="flex items-center">
              <BrandLogo
                title={brandName}
                tagline=""
                variant="inline"
                size="sm"
                logoStyle={websiteSettings.logoStyle}
                logoUrl={websiteSettings.logoUrl}
              />
            </div>

            <p className="text-xs leading-relaxed text-slate-500">
              {isBn ? footerCfg.missionBn : footerCfg.missionEn}
            </p>

            {/* 4 Circular Community / Channel / Language Icon Buttons matching layout */}
            <div className="flex items-center gap-2.5 pt-1">
              <a
                href={websiteSettings.youtubeChannel || 'https://youtube.com'}
                target="_blank"
                rel="noopener noreferrer"
                aria-label="YouTube Channel"
                className="w-9 h-9 rounded-full bg-[#E9ECEF] hover:bg-[#0284C7] text-slate-700 hover:text-white flex items-center justify-center transition-colors"
              >
                <svg className="w-4 h-4 fill-current" viewBox="0 0 24 24" aria-hidden="true">
                  <path d="M21.6 7.2a2.7 2.7 0 0 0-1.9-1.9C18 4.8 12 4.8 12 4.8s-6 0-7.7.5a2.7 2.7 0 0 0-1.9 1.9C2 8.9 2 12 2 12s0 3.1.4 4.8a2.7 2.7 0 0 0 1.9 1.9c1.7.5 7.7.5 7.7.5s6 0 7.7-.5a2.7 2.7 0 0 0 1.9-1.9c.4-1.7.4-4.8.4-4.8s0-3.1-.4-4.8zM10 15.2V8.8L15.4 12 10 15.2z" />
                </svg>
              </a>
              <button
                type="button"
                onClick={() => setActiveView('learn')}
                aria-label="Free Video Hub"
                className="w-9 h-9 rounded-full bg-[#E9ECEF] hover:bg-[#0284C7] text-slate-700 hover:text-white flex items-center justify-center transition-colors cursor-pointer"
              >
                <svg className="w-4 h-4 fill-current" viewBox="0 0 24 24" aria-hidden="true">
                  <path d="M8 5v14l11-7z" />
                </svg>
              </button>
              <a
                href={websiteSettings.facebookPage || 'https://facebook.com'}
                target="_blank"
                rel="noopener noreferrer"
                aria-label="Facebook Page"
                className="w-9 h-9 rounded-full bg-[#E9ECEF] hover:bg-[#0284C7] text-slate-700 hover:text-white flex items-center justify-center transition-colors"
              >
                <svg className="w-4 h-4 fill-current" viewBox="0 0 24 24" aria-hidden="true">
                  <path d="M13.5 9H16V6h-2.5C11.6 6 10 7.6 10 9.5V11H8v3h2v7h3v-7h2.3l.7-3H13V9.5c0-.3.2-.5.5-.5z" />
                </svg>
              </a>
              <button
                type="button"
                onClick={() => setLanguage(language === 'bn' ? 'en' : 'bn')}
                title={language === 'bn' ? 'Switch to English' : 'বাংলায় দেখুন'}
                aria-label="Switch Language"
                className="w-9 h-9 rounded-full bg-[#E9ECEF] hover:bg-[#0284C7] text-slate-700 hover:text-white flex items-center justify-center transition-colors cursor-pointer"
              >
                <Globe className="w-4 h-4" />
              </button>
            </div>
          </div>

          {/* Column 2: Links */}
          <div>
            <h4 className="text-sm font-bold text-slate-900 mb-4">
              {isBn ? 'লিংকসমূহ' : 'Links'}
            </h4>
            <ul className="space-y-2.5 text-xs text-slate-600">
              <li>
                <button
                  type="button"
                  onClick={() => {
                    setActiveView('home');
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                  }}
                  className="inline-flex items-center gap-2 hover:text-[#0284C7] transition-colors cursor-pointer"
                >
                  <ArrowRight className="w-3 h-3 text-slate-400" />
                  <span>{isBn ? 'হোম' : 'Home'}</span>
                </button>
              </li>
              <li>
                <button
                  type="button"
                  onClick={() => {
                    setActiveView('about');
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                  }}
                  className="inline-flex items-center gap-2 hover:text-[#0284C7] transition-colors cursor-pointer"
                >
                  <ArrowRight className="w-3 h-3 text-slate-400" />
                  <span>{isBn ? 'আমাদের সম্পর্কে' : 'About Us'}</span>
                </button>
              </li>
              <li>
                <button
                  type="button"
                  onClick={() => {
                    setActiveView('contact');
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                  }}
                  className="inline-flex items-center gap-2 hover:text-[#0284C7] transition-colors cursor-pointer"
                >
                  <ArrowRight className="w-3 h-3 text-slate-400" />
                  <span>{isBn ? 'যোগাযোগ' : 'Contact Us'}</span>
                </button>
              </li>
              <li>
                <button
                  type="button"
                  onClick={() => setLegalModal('terms')}
                  className="inline-flex items-center gap-2 hover:text-[#0284C7] transition-colors cursor-pointer"
                >
                  <ArrowRight className="w-3 h-3 text-slate-400" />
                  <span>{isBn ? 'শর্তাবলী' : 'Terms & Conditions'}</span>
                </button>
              </li>
              <li>
                <button
                  type="button"
                  onClick={() => setLegalModal('privacy')}
                  className="inline-flex items-center gap-2 hover:text-[#0284C7] transition-colors cursor-pointer"
                >
                  <ArrowRight className="w-3 h-3 text-slate-400" />
                  <span>{isBn ? 'গোপনীয়তা নীতি' : 'Privacy Policy'}</span>
                </button>
              </li>
              <li>
                <button
                  type="button"
                  onClick={() => setLegalModal('refund')}
                  className="inline-flex items-center gap-2 hover:text-[#0284C7] transition-colors cursor-pointer"
                >
                  <ArrowRight className="w-3 h-3 text-slate-400" />
                  <span>{isBn ? 'রিফান্ড নীতিমালা' : 'Refund Policy'}</span>
                </button>
              </li>
              <li>
                <button
                  type="button"
                  onClick={() => {
                    if (onOpenVerifyModal) onOpenVerifyModal();
                    else setActiveView('exams');
                  }}
                  className="inline-flex items-center gap-2 hover:text-[#0284C7] transition-colors cursor-pointer"
                >
                  <ArrowRight className="w-3 h-3 text-slate-400" />
                  <span>{isBn ? 'বোর্ড রেজাল্ট ও সনদ যাচাই' : 'Board Result'}</span>
                </button>
              </li>
            </ul>
          </div>

          {/* Column 3: Company Details */}
          <div>
            <h4 className="text-sm font-bold text-slate-900 mb-4">
              {isBn ? 'প্রতিষ্ঠানের তথ্য' : 'Company Details'}
            </h4>
            <div className="space-y-3 text-xs text-slate-600">
              <div className="flex items-start gap-2.5">
                <FileText className="w-4 h-4 text-[#0284C7] shrink-0 mt-0.5" />
                <span>
                  {isBn ? 'লাইসেন্স নং:' : 'License No:'}{' '}
                  <strong className="font-mono text-slate-800">{displayLicense}</strong>
                </span>
              </div>
              <div className="flex items-start gap-2.5">
                <MapPin className="w-4 h-4 text-[#0284C7] shrink-0 mt-0.5" />
                <span>{displayAddress}</span>
              </div>
              <div className="flex items-start gap-2.5">
                <ShieldCheck className="w-4 h-4 text-emerald-600 shrink-0 mt-0.5" />
                <span>{isBn ? 'IELTS ও স্পোকেন ইংলিশ শেখার প্ল্যাটফর্ম' : 'IELTS & Spoken English Learning Platform'}</span>
              </div>
            </div>
          </div>

          {/* Column 4: Contact */}
          <div>
            <h4 className="text-sm font-bold text-slate-900 mb-4">
              {isBn ? 'যোগাযোগ' : 'Contact'}
            </h4>
            <div className="space-y-3 text-xs text-slate-600">
              <div className="flex items-center gap-2.5">
                <Phone className="w-4 h-4 text-[#0284C7] shrink-0" />
                <a href={`tel:${displayHotline.replace(/\s+/g, '')}`} className="font-mono tabular-nums font-semibold text-slate-800 hover:text-[#0284C7]">
                  {displayHotline}
                </a>
              </div>
              <div className="flex items-center gap-2.5">
                <Mail className="w-4 h-4 text-[#0284C7] shrink-0" />
                <a href={`mailto:${displayEmail}`} className="hover:text-[#0284C7]">
                  {displayEmail}
                </a>
              </div>
              {onOpenAdmission && (
                <div className="pt-1">
                  <button
                    type="button"
                    onClick={onOpenAdmission}
                    className="inline-flex items-center gap-1.5 text-xs font-semibold text-[#0284C7] hover:underline cursor-pointer"
                  >
                    <span>{isBn ? 'অনলাইন ভর্তি আবেদন' : 'Apply for Admission'}</span>
                    <ArrowRight className="w-3 h-3" />
                  </button>
                </div>
              )}
            </div>
          </div>
        </div>

        {/* Centered White Payment Gateway Strip (Admin Controllable) */}
        {footerCfg.showPaymentStrip !== false && (
          <div className="max-w-4xl mx-auto my-4 bg-white border border-slate-200/80 rounded-xl px-5 py-3.5 shadow-2xs flex flex-col sm:flex-row items-center justify-between gap-3">
            <div className="flex flex-wrap items-center justify-center gap-1.5">
              <span className="text-xs font-bold text-[#0284C7] mr-2 whitespace-nowrap">
                {isBn ? 'পেমেন্ট মাধ্যম:' : 'Pay With'}
              </span>
              {paymentGateways.map(gw => (
                <span
                  key={gw.name}
                  className={`px-2 py-0.5 rounded border text-[10px] font-bold tracking-tight whitespace-nowrap ${gw.accent}`}
                >
                  {gw.name}
                </span>
              ))}
            </div>
            <div className="sm:pl-3 sm:border-l border-slate-200 text-center sm:text-right shrink-0">
              <div className="text-[9px] text-slate-400 leading-none">Verified by</div>
              <div className="text-[11px] font-black text-[#1E3A8A] tracking-tight whitespace-nowrap">SSLCOMMERZ</div>
            </div>
          </div>
        )}

        {/* Bottom Copyright & Policy Row */}
        <div className="mt-8 pt-5 border-t border-slate-300/70 flex flex-col sm:flex-row items-center justify-between gap-4 text-xs text-slate-500">
          <div>{footerCfg.copyrightText || `© ${new Date().getFullYear()} Alom's English Center. All Rights Reserved.`}</div>
          <div className="flex flex-wrap items-center gap-5">
            <button type="button" onClick={() => setLegalModal('terms')} className="hover:text-slate-900 transition-colors cursor-pointer">
              Terms & Conditions
            </button>
            <button type="button" onClick={() => setLegalModal('privacy')} className="hover:text-slate-900 transition-colors cursor-pointer">
              Privacy Policy
            </button>
            <button type="button" onClick={() => setLegalModal('refund')} className="hover:text-slate-900 transition-colors cursor-pointer">
              Refund Policy
            </button>
            <button
              type="button"
              onClick={() => window.dispatchEvent(new CustomEvent('aec:open-cookie-preferences'))}
              className="hover:text-slate-900 transition-colors cursor-pointer"
            >
              Cookie Preferences
            </button>
          </div>
        </div>
      </div>

      {/* Legal Policy Modal */}
      {legalModal && (
        <div className="fixed inset-0 z-50 bg-slate-950/60 backdrop-blur-xs flex items-center justify-center p-4">
          <div className="bg-white rounded-2xl max-w-md w-full p-6 border border-slate-200 shadow-xl space-y-4">
            <div className="flex items-center justify-between border-b border-slate-100 pb-3">
              <h3 className="font-bold text-slate-900 text-base">{legalCopy[legalModal].title}</h3>
              <button
                type="button"
                onClick={() => setLegalModal(null)}
                className="p-1 text-slate-400 hover:text-slate-700 rounded-lg cursor-pointer"
                aria-label="Close modal"
              >
                <X className="w-4 h-4" />
              </button>
            </div>
            <p className="text-xs sm:text-sm text-slate-600 leading-relaxed">{legalCopy[legalModal].body}</p>
            <div className="text-right">
              <button
                type="button"
                onClick={() => setLegalModal(null)}
                className="px-4 py-2 rounded-lg bg-[#0284C7] hover:bg-[#0369A1] text-white text-xs font-semibold cursor-pointer"
              >
                {isBn ? 'বুঝেছি' : 'Close'}
              </button>
            </div>
          </div>
        </div>
      )}
    </footer>
  );
};
