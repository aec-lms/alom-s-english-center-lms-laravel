import React, { useEffect, useRef, useState } from 'react';
import { apiClient } from '../../services/apiClient';

type PublicMarketing = {
  title?: string; description?: string; canonical?: string; googleVerification?: string;
  ga4Id?: string; gtmId?: string; metaPixelId?: string;
};

const CONSENT_KEY = 'aec_analytics_marketing_consent';

export const MarketingTracking: React.FC<{ pageKey?: string }> = ({ pageKey = '/' }) => {
  const [settings, setSettings] = useState<PublicMarketing>({});
  const [settingsLoaded, setSettingsLoaded] = useState(false);
  const lastTrackedPage = useRef('');
  const isPublicPage = !['login', 'dashboard', 'classroom', 'take_exam', 'certificate_view', 'id_card_view'].includes(pageKey);
  const [consent, setConsent] = useState(() => {
    try { return localStorage.getItem(CONSENT_KEY) || 'unknown'; } catch { return 'unknown'; }
  });

  useEffect(() => {
    let active = true;
    const routePath = pageKey === 'home' ? '/' : ({ live_classes: '/live-classes', take_exam: '/take-exam', certificate_view: '/certificate', id_card_view: '/id-card' } as Record<string, string>)[pageKey] || `/${pageKey}`;
    void apiClient.seo.getPublic(routePath).then(result => {
      if (active && result.success && result.data) {
        const data = result.data as PublicMarketing;
        setSettings(data);
        setSettingsLoaded(true);
        if (data.title) document.title = data.title;
        if (data.description) {
          let meta = document.querySelector<HTMLMetaElement>('meta[name="description"]');
          if (!meta) { meta = document.createElement('meta'); meta.name = 'description'; document.head.appendChild(meta); }
          meta.content = data.description;
        }
        if (data.canonical) {
          let link = document.querySelector<HTMLLinkElement>('link[rel="canonical"]');
          if (!link) { link = document.createElement('link'); link.rel = 'canonical'; document.head.appendChild(link); }
          link.href = data.canonical;
        }
        if (data.googleVerification) {
          let verify = document.querySelector<HTMLMetaElement>('meta[name="google-site-verification"]');
          if (!verify) { verify = document.createElement('meta'); verify.name = 'google-site-verification'; document.head.appendChild(verify); }
          verify.content = data.googleVerification;
        }
        const setPropertyMeta = (property: string, content: string) => {
          let meta = document.querySelector<HTMLMetaElement>(`meta[property="${property}"]`);
          if (!meta) { meta = document.createElement('meta'); meta.setAttribute('property', property); document.head.appendChild(meta); }
          meta.content = content;
        };
        const setNamedMeta = (name: string, content: string) => {
          let meta = document.querySelector<HTMLMetaElement>(`meta[name="${name}"]`);
          if (!meta) { meta = document.createElement('meta'); meta.name = name; document.head.appendChild(meta); }
          meta.content = content;
        };
        if (data.title) {
          setPropertyMeta('og:title', data.title);
          setNamedMeta('twitter:title', data.title);
        }
        if (data.description) {
          setPropertyMeta('og:description', data.description);
          setNamedMeta('twitter:description', data.description);
        }
        if (data.canonical) {
          setPropertyMeta('og:type', 'website');
          setPropertyMeta('og:url', data.canonical);
        }
        setNamedMeta('twitter:card', 'summary');
      }
    });
    return () => { active = false; };
  }, [pageKey]);

  useEffect(() => {
    if (!isPublicPage) { lastTrackedPage.current = ''; return; }
    if (consent !== 'granted' || !settingsLoaded) return;
    if (settings.ga4Id && !document.querySelector('[data-aec-ga4]')) {
      const script = document.createElement('script');
      script.async = true;
      script.src = `https://www.googletagmanager.com/gtag/js?id=${encodeURIComponent(settings.ga4Id)}`;
      script.dataset.aecGa4 = 'true';
      document.head.appendChild(script);
      const inline = document.createElement('script');
      inline.dataset.aecGa4 = 'true';
      inline.text = `window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config',${JSON.stringify(settings.ga4Id)},{send_page_view:false});`;
      document.head.appendChild(inline);
    }
    if (settings.gtmId && !document.querySelector('[data-aec-gtm]')) {
      const inline = document.createElement('script');
      inline.dataset.aecGtm = 'true';
      inline.text = `window.dataLayer=window.dataLayer||[];window.dataLayer.push({'gtm.start':new Date().getTime(),event:'gtm.js'});`;
      document.head.appendChild(inline);
      const script = document.createElement('script');
      script.async = true;
      script.dataset.aecGtm = 'true';
      script.src = `https://www.googletagmanager.com/gtm.js?id=${encodeURIComponent(settings.gtmId)}`;
      document.head.appendChild(script);
    }
    if (settings.metaPixelId && !document.querySelector('[data-aec-meta-pixel]')) {
      const bootstrap = document.createElement('script');
      bootstrap.dataset.aecMetaPixel = 'true';
      bootstrap.text = `!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');fbq('init',${JSON.stringify(settings.metaPixelId)});`;
      document.head.appendChild(bootstrap);
    }
    const browser = window as Window & { fbq?: (...args: unknown[]) => void; gtag?: (...args: unknown[]) => void; dataLayer?: unknown[] };
    if (lastTrackedPage.current !== pageKey) {
      browser.fbq?.('track', 'PageView');
      browser.gtag?.('event', 'page_view', { page_path: pageKey });
      browser.dataLayer?.push({ event: 'aec_page_view', page_path: pageKey });
      lastTrackedPage.current = pageKey;
    }
  }, [consent, settings, settingsLoaded, pageKey, isPublicPage]);

  const [openPrefs, setOpenPrefs] = useState(false);

  useEffect(() => {
    const handler = () => setOpenPrefs(true);
    window.addEventListener('aec:open-cookie-preferences', handler);
    return () => window.removeEventListener('aec:open-cookie-preferences', handler);
  }, []);

  const choose = (value: 'granted' | 'denied') => {
    try { localStorage.setItem(CONSENT_KEY, value); } catch { /* consent remains session-only */ }
    if (value === 'denied') {
      lastTrackedPage.current = '';
      const browser = window as Window & { fbq?: (...args: unknown[]) => void; gtag?: (...args: unknown[]) => void };
      browser.fbq?.('consent', 'revoke');
      browser.gtag?.('consent', 'update', { analytics_storage: 'denied', ad_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied' });
    }
    setConsent(value);
    setOpenPrefs(false);
  };

  if (!isPublicPage || !openPrefs) return null;

  return (
    <div className="fixed bottom-4 left-4 right-4 z-[100] mx-auto flex max-w-3xl flex-col gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-2xl sm:flex-row sm:items-center sm:justify-between">
      <p className="text-xs leading-relaxed text-slate-600">We use optional analytics and marketing cookies to understand site usage and measure campaigns. You can accept or reject them; essential LMS functions work either way.</p>
      <div className="flex shrink-0 gap-2">
        <button type="button" onClick={() => choose('denied')} className="rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 cursor-pointer">Reject optional</button>
        <button type="button" onClick={() => choose('granted')} className="rounded-lg bg-blue-600 px-3 py-2 text-xs font-semibold text-white cursor-pointer">Accept optional</button>
      </div>
    </div>
  );
};
