import React, { useEffect, useState } from 'react';
import { ArrowUpRight, Sparkles } from 'lucide-react';
import { apiClient } from '../../services/apiClient';

type Promotion = { id: number; campaign_name: string; entity_type: string; entity_id: number | null; destination_url: string | null; placement: string; };

export const FeaturedPromotions: React.FC<{ placement?: 'homepage_featured' | 'course_listing' | 'branch_directory' | 'notice_banner' }> = ({ placement = 'homepage_featured' }) => {
  const [items, setItems] = useState<Promotion[]>([]);
  useEffect(() => {
    let active = true;
    void apiClient.promotions.getActive(placement).then(result => {
      if (!active || !result.success) return;
      const campaigns = (result.data || []) as Promotion[];
      setItems(campaigns);
      campaigns.forEach(campaign => { void apiClient.promotions.track(campaign.id, 'impression'); });
    });
    return () => { active = false; };
  }, [placement]);
  if (!items.length) return null;
  return <section aria-label="Featured promotions" className="mx-auto my-6 grid w-full max-w-7xl gap-3 px-4 sm:px-8 md:grid-cols-2">
    {items.map(item => {
      const href = item.destination_url || (item.entity_type === 'course' ? '/courses' : item.entity_type === 'branch' ? '/contact' : '/');
      return <a key={item.id} href={href} onClick={() => { void apiClient.promotions.track(item.id, 'click'); }} className="group flex items-center justify-between gap-4 rounded-2xl border border-indigo-100 bg-gradient-to-r from-indigo-50 to-white p-4 shadow-sm transition hover:border-indigo-300 hover:shadow-md">
        <span className="flex min-w-0 items-center gap-3"><span className="rounded-xl bg-indigo-100 p-2 text-indigo-700"><Sparkles className="h-5 w-5"/></span><span className="min-w-0"><span className="block text-[10px] font-bold uppercase tracking-wider text-indigo-600">Featured boost</span><span className="block truncate text-sm font-extrabold text-slate-900">{item.campaign_name}</span></span></span>
        <ArrowUpRight className="h-5 w-5 shrink-0 text-indigo-500 transition group-hover:-translate-y-0.5 group-hover:translate-x-0.5"/>
      </a>;
    })}
  </section>;
};
