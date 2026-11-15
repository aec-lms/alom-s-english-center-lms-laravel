import React, { useState } from 'react';
import { useLms } from '../../context/LmsContext';
import { BookOpen, Bus, Home } from 'lucide-react';
import { LibraryManagementPanel } from './LibraryManagementPanel';
import { TransportManagementPanel } from './TransportManagementPanel';
import { HostelManagementPanel } from './HostelManagementPanel';

export const CampusFacilitiesManager: React.FC = () => {
  const { language } = useLms();
  const isBn = language === 'bn';

  const [activeTab, setActiveTab] = useState<'library' | 'transport' | 'hostel'>('library');



  return (
    <div className="space-y-6">
      {/* Header Banner */}
      <div className="bg-white p-5 rounded-2xl border border-slate-200/90 shadow-2xs flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <div className="flex items-center gap-2">
            <span className="px-2.5 py-0.5 rounded-full bg-blue-100 text-blue-700 text-[10px] font-bold uppercase tracking-wider">
              Campus Facilities & Logistics
            </span>
            <span className="text-xs text-slate-400">·</span>
            <span className="text-xs font-semibold text-slate-600">
              {isBn ? 'লাইব্রেরি, পরিবহন ও হোস্টেল ব্যবস্থাপনা' : 'Library, Transport & Hostel Operations'}
            </span>
          </div>
          <h2 className="text-lg font-bold text-slate-900 leading-tight mt-1">
            {isBn ? 'ক্যাম্পাস ফ্যাসিলিটি কন্ট্রোল হাব' : 'Campus Facilities & Student Services'}
          </h2>
          <p className="text-xs text-slate-500 mt-0.5">
            {isBn 
              ? 'ক্যামব্রিজ রেফারেন্স বুক ইস্যু, সেন্ট্রাল ক্যাম্পাস শাটল বাস ও রেসিডেনশিয়াল হোস্টেল নিয়ন্ত্রণ করুন' 
              : 'Administer academic book lending, inter-branch shuttle logistics, and executive residency accommodations'}
          </p>
        </div>
      </div>

      {/* Navigation Sub-Tabs */}
      <div className="flex items-center gap-2 border-b border-slate-200/80 pb-2 overflow-x-auto">
        <button
          onClick={() => setActiveTab('library')}
          className={`flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-bold transition-all shrink-0 cursor-pointer ${
            activeTab === 'library'
              ? 'bg-blue-600 text-white shadow-sm shadow-blue-600/20'
              : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900'
          }`}
        >
          <BookOpen className="w-4 h-4" />
          <span>{isBn ? '১. লাইব্রেরি ক্যাটালগ' : '1. Library Catalog'}</span>
        </button>

        <button
          onClick={() => setActiveTab('transport')}
          className={`flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-bold transition-all shrink-0 cursor-pointer ${
            activeTab === 'transport'
              ? 'bg-blue-600 text-white shadow-sm shadow-blue-600/20'
              : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900'
          }`}
        >
          <Bus className="w-4 h-4" />
          <span>{isBn ? '২. ক্যাম্পাস পরিবহন' : '2. Transport Fleet'}</span>
        </button>

        <button
          onClick={() => setActiveTab('hostel')}
          className={`flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-bold transition-all shrink-0 cursor-pointer ${
            activeTab === 'hostel'
              ? 'bg-blue-600 text-white shadow-sm shadow-blue-600/20'
              : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900'
          }`}
        >
          <Home className="w-4 h-4" />
          <span>{isBn ? '৩. হোস্টেল ব্যবস্থাপনা' : '3. Hostel Management'}</span>
        </button>
      </div>

      {/* ================= TAB 1: LIBRARY ================= */}
      {activeTab === 'library' && <LibraryManagementPanel />}

      {/* ================= TAB 2: TRANSPORT ================= */}
      {activeTab === 'transport' && <TransportManagementPanel />}

      {/* ================= TAB 3: HOSTEL ================= */}
      {activeTab === 'hostel' && <HostelManagementPanel />}
    </div>
  );
};
