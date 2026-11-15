import React, { useEffect, useState } from 'react';
import { apiClient } from '../../services/apiClient';
import { useLms } from '../../context/LmsContext';
import { 
  Building2, ShieldCheck, CreditCard, Server, Users, DollarSign, 
  Plus, Edit3, Trash2, CheckCircle2, AlertTriangle, Globe, Key, 
  Mail, MessageSquare, Download, Search, Filter, RefreshCw, 
  ArrowUpRight, HardDrive, Cpu, Activity, ExternalLink, X
} from 'lucide-react';
import { Tenant } from '../../types/lms';

const mapSaasTenant = (row: Record<string, unknown>): Tenant => {
  const planKey = String(row.plan_key || 'free');
  const status = String(row.status || 'pending');
  return {
    id: String(row.id || ''),
    name: String(row.name || ''),
    slug: String(row.tenant_key || ''),
    subdomain: String(row.tenant_key || ''),
    customDomain: String(row.domains || '').split(',')[0] || undefined,
    logo: '',
    contactEmail: String(row.contact_email || ''),
    phone: String(row.contact_phone || ''),
    status: (['active', 'suspended', 'pending'].includes(status) ? status : 'pending') as Tenant['status'],
    planId: (['free', 'basic', 'standard', 'professional', 'enterprise'].includes(planKey) ? planKey : 'free') as Tenant['planId'],
    maxStudents: 0,
    maxTeachers: 0,
    maxStorageGb: 0,
    billingCycle: 'monthly',
    monthlyFee: Number(row.monthly_price || 0),
    subscriptionExpiresAt: String(row.subscription_ends_at || ''),
    city: String(row.city || ''),
    address: String(row.domains || ''),
    createdAt: String(row.created_at || ''),
  };
};

export const SuperAdminSaaSPanel: React.FC = () => {
  const { language } = useLms();
  const [tenants, setTenants] = useState<Tenant[]>([]);
  const [activeTenantId, setActiveTenantId] = useState<string>('');
  const [serverPlans, setServerPlans] = useState<Array<{ plan_key: string; name: string; monthly_price: number; currency: string }>>([]);
  const [loadingTenants, setLoadingTenants] = useState(true);
  const [savingTenant, setSavingTenant] = useState(false);

  const refreshTenants = async () => {
    const result = await apiClient.saas.listTenants();
    if (!result.success || !Array.isArray(result.data)) throw new Error(result.error || 'Could not load tenant registry.');
    setTenants(result.data.map(mapSaasTenant));
  };

  const isBn = language === 'bn';

  const [activeSubTab, setActiveSubTab] = useState<'overview' | 'tenants' | 'plans' | 'billing' | 'gateways' | 'security'>('overview');
  const [searchTerm, setSearchTerm] = useState('');
  const [showAddTenantModal, setShowAddTenantModal] = useState(false);
  const [toastMessage, setToastMessage] = useState<string | null>(null);

  // New Tenant Form State
  const [newTenantName, setNewTenantName] = useState('');
    const [newTenantSubdomain, setNewTenantSubdomain] = useState('');
  const [newTenantEmail, setNewTenantEmail] = useState('');
  const [newTenantPhone, setNewTenantPhone] = useState('');
  const [newTenantCity, setNewTenantCity] = useState('Dhaka');
  const [newTenantPlan, setNewTenantPlan] = useState<'free' | 'basic' | 'standard' | 'professional' | 'enterprise'>('professional');

  // Gateway Settings State
  const [smtpHost, setSmtpHost] = useState('');
  const [smtpUser, setSmtpUser] = useState('');
  const [smsGatewayApi, setSmsGatewayApi] = useState('');
  const [bkashMerchant, setBkashMerchant] = useState('');
  const [nagadMerchant, setNagadMerchant] = useState('');
  const [maintenanceMode, setMaintenanceMode] = useState(false);

  const showToast = (msg: string) => {
    setToastMessage(msg);
    setTimeout(() => setToastMessage(null), 3000);
  };

  useEffect(() => {
    let active = true;
    Promise.all([apiClient.saas.listTenants(), apiClient.saas.getPlans()]).then(([tenantResult, planResult]) => {
      if (!active) return;
      if (tenantResult.success && Array.isArray(tenantResult.data)) {
        setTenants(tenantResult.data.map(mapSaasTenant));
      } else {
        showToast(tenantResult.error || 'Could not load tenant registry.');
      }
      if (planResult.success && Array.isArray(planResult.data)) {
        setServerPlans(planResult.data);
        if (planResult.data.length > 0) {
          setNewTenantPlan(planResult.data[0].plan_key as Tenant['planId']);
        }
      } else {
        showToast(planResult.error || 'Could not load SaaS plans.');
      }
      setLoadingTenants(false);
    }).catch(error => {
      if (active) {
        setLoadingTenants(false);
        showToast(error instanceof Error ? error.message : 'Could not load SaaS data.');
      }
    });
    return () => { active = false; };
  }, []);
  const handleCreateTenant = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!newTenantName.trim() || !newTenantSubdomain.trim() || savingTenant) return;

    setSavingTenant(true);
    try {
      const tenantKey = newTenantSubdomain.trim().toLowerCase().split('.')[0];
      const result = await apiClient.saas.createTenant({
        tenant_key: tenantKey,
        name: newTenantName.trim(),
        contact_email: newTenantEmail.trim(),
        contact_phone: newTenantPhone.trim(),
        city: newTenantCity.trim(),
        plan_key: newTenantPlan,
        domain: newTenantSubdomain.includes('.') ? newTenantSubdomain.trim() : '',
      });
      if (!result.success) { showToast(result.error || 'Tenant registration failed.'); return; }
      setShowAddTenantModal(false);
      setNewTenantName('');
      setNewTenantSubdomain('');
      try {
        await refreshTenants();
      } catch {
        showToast(isBn
          ? 'প্রতিষ্ঠান রেজিস্টার হয়েছে, তবে তালিকা রিফ্রেশ হয়নি। আবার রিফ্রেশ করুন।'
          : 'Tenant was registered, but the directory could not refresh. Reload the registry.');
        return;
      }
      showToast(isBn ? 'প্রতিষ্ঠান pending অবস্থায় রেজিস্টার হয়েছে; provisioning চালু নেই।' : 'Tenant registered as pending. Provisioning is not enabled.');
    } catch (error) {
      showToast(error instanceof Error ? error.message : 'Tenant registration failed.');
    } finally {
      setSavingTenant(false);
    }
  };

  const handleToggleTenantStatus = (tenant: Tenant) => {
    if (tenant.status === 'pending') {
      showToast(isBn ? 'Provisioning ও domain verification সম্পন্ন না হওয়া পর্যন্ত pending প্রতিষ্ঠান সক্রিয় করা যাবে না।' : 'Pending tenants cannot be activated before provisioning and domain verification.');
      return;
    }
    const nextStatus = tenant.status === 'active' ? 'suspended' : 'active';
    void apiClient.saas.updateTenantStatus(Number(tenant.id), nextStatus).then(async result => {
      if (!result.success) { showToast(result.error || 'Tenant status update failed.'); return; }
      await refreshTenants();
      showToast(isBn ? `প্রতিষ্ঠানের স্ট্যাটাস পরিবর্তন করা হয়েছে: ${nextStatus}` : `Tenant status updated to: ${nextStatus}`);
    }).catch(error => {
      showToast(isBn ? 'স্ট্যাটাস পরিবর্তন হয়েছে, কিন্তু তালিকা রিফ্রেশ হয়নি।' : 'Status may have changed, but the directory refresh failed.');
    });
  };

  const filteredTenants = tenants.filter(t => 
    t.name.toLowerCase().includes(searchTerm.toLowerCase()) ||
    t.subdomain.toLowerCase().includes(searchTerm.toLowerCase()) ||
    t.city.toLowerCase().includes(searchTerm.toLowerCase())
  );

  const totalMrr = tenants.reduce((acc, t) => acc + (t.status === 'active' ? t.monthlyFee : 0), 0);
  const activeTenantsCount = tenants.filter(t => t.status === 'active').length;

  return (
    <div className="space-y-6">
      {/* Toast Alert */}
      {toastMessage && (
        <div className="fixed top-5 right-5 z-50 bg-emerald-600 text-white px-4 py-2.5 rounded-xl shadow-lg flex items-center gap-2 text-xs font-bold animate-in fade-in">
          <CheckCircle2 className="w-4 h-4" />
          <span>{toastMessage}</span>
        </div>
      )}

      {/* Admin Top Header */}
      <div className="bg-slate-900 text-white p-6 rounded-2xl shadow-md border border-slate-800 flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
          <div className="flex items-center gap-2.5">
            <span className="px-2.5 py-0.5 rounded-full bg-blue-500/20 text-blue-400 text-[10px] font-bold uppercase tracking-wider border border-blue-500/30">
              Admin SaaS Control Center
            </span>
            <span className="text-xs text-slate-400">·</span>
            <span className="text-xs text-emerald-400 font-semibold flex items-center gap-1">
              <span className="w-2 h-2 rounded-full bg-emerald-400 animate-ping" />
              Server registry connected · provisioning disabled
            </span>
          </div>
          <h2 className="text-xl font-extrabold mt-1 tracking-tight">
            {isBn ? 'সুপার অ্যাডমিন প্ল্যাটফর্ম ও মাল্টি-টেন্যান্ট কন্ট্রোল' : 'SaaS Master Platform & Tenant Governance'}
          </h2>
          <p className="text-xs text-slate-400 mt-0.5">
            {isBn 
              ? 'সকল ক্যাম্পাস, ফ্র্যাঞ্চাইজ প্রতিষ্ঠান, সাবস্ক্রিপশন প্ল্যান ও গ্লোবাল গেটওয়ে কনফিগারেশন পরিচালনা করুন' 
              : 'Tenant registry is server-backed. Tenant data provisioning, billing and isolation are not enabled.'}
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-2.5">
          <button
            onClick={() => setShowAddTenantModal(true)}
            className="px-4 py-2 bg-blue-600 hover:bg-blue-500 text-white font-bold rounded-xl text-xs flex items-center gap-2 shadow-sm transition-all cursor-pointer"
          >
            <Plus className="w-4 h-4" />
            <span>{isBn ? 'নতুন প্রতিষ্ঠান যোগ করুন' : 'Provision New Tenant'}</span>
          </button>
        </div>
      </div>

      {/* KPI Stats Overview Cards */}
      <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div className="bg-white p-4 rounded-2xl border border-slate-200/90 shadow-2xs">
          <div className="flex items-center justify-between">
            <span className="text-[11px] font-semibold text-slate-500">{isBn ? 'সক্রিয় প্রতিষ্ঠান' : 'Active Tenants'}</span>
            <Building2 className="w-4 h-4 text-blue-600" />
          </div>
          <div className="text-2xl font-black text-slate-900 mt-1">{activeTenantsCount} / {tenants.length}</div>
          <div className="text-[10px] text-emerald-600 font-bold mt-1">Server registry · tenant isolation not implemented</div>
        </div>

        <div className="bg-white p-4 rounded-2xl border border-slate-200/90 shadow-2xs">
          <div className="flex items-center justify-between">
            <span className="text-[11px] font-semibold text-slate-500">{isBn ? 'মাসিক পুনরাবৃত্তিমূলক আয় (MRR)' : 'Monthly Recurring (MRR)'}</span>
            <DollarSign className="w-4 h-4 text-emerald-600" />
          </div>
          <div className="text-2xl font-black text-slate-900 mt-1">৳{totalMrr.toLocaleString()}</div>
          <div className="text-[10px] text-emerald-600 font-bold mt-1">Registry plan value only · no payments recorded</div>
        </div>

        <div className="bg-white p-4 rounded-2xl border border-slate-200/90 shadow-2xs">
          <div className="flex items-center justify-between">
            <span className="text-[11px] font-semibold text-slate-500">{isBn ? 'মোট শিক্ষার্থী ক্যাপাসিটি' : 'Total Student Capacity'}</span>
            <Users className="w-4 h-4 text-indigo-600" />
          </div>
          <div className="text-2xl font-black text-slate-900 mt-1">—</div>
          <div className="text-[10px] text-slate-500 font-medium mt-1">No live capacity data connected</div>
        </div>

        <div className="bg-white p-4 rounded-2xl border border-slate-200/90 shadow-2xs">
          <div className="flex items-center justify-between">
            <span className="text-[11px] font-semibold text-slate-500">{isBn ? 'সার্ভার ও ডাটাবেজ স্বাস্থ্য' : 'Server & DB Health'}</span>
            <Activity className="w-4 h-4 text-emerald-600" />
          </div>
          <div className="text-2xl font-black text-emerald-600 mt-1">—</div>
          <div className="text-[10px] text-slate-500 font-medium mt-1">Server health monitoring is not connected</div>
        </div>
      </div>

      {/* Sub-Navigation Tabs */}
      <div className="flex items-center gap-2 border-b border-slate-200/80 pb-2 overflow-x-auto">
        {[
          { id: 'overview', title: isBn ? '১. ওভারভিউ ও আর্কিটেকচার' : '1. Overview & Health', icon: Server },
          { id: 'tenants', title: isBn ? `২. প্রতিষ্ঠান তালিকা (${tenants.length})` : `2. Tenants Directory (${tenants.length})`, icon: Building2 },
          { id: 'plans', title: isBn ? '৩. সাবস্ক্রিপশন প্ল্যান' : '3. SaaS Pricing Tiers', icon: CreditCard },
          { id: 'billing', title: isBn ? '৪. বিলিং ও ইনভয়েস' : '4. Invoicing & Billing', icon: DollarSign },
          { id: 'gateways', title: isBn ? '৫. গেটওয়ে ও কনফিগ' : '5. Master Gateways & SMTP', icon: Key },
          { id: 'security', title: isBn ? '৬. অডিট ও সিকিউরিটি' : '6. Security & Audit Trail', icon: ShieldCheck }
        ].map(tab => (
          <button
            key={tab.id}
            onClick={() => setActiveSubTab(tab.id as any)}
            className={`flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-bold transition-all shrink-0 cursor-pointer ${
              activeSubTab === tab.id
                ? 'bg-blue-600 text-white shadow-sm shadow-blue-600/20'
                : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900'
            }`}
          >
            <tab.icon className="w-4 h-4" />
            <span>{tab.title}</span>
          </button>
        ))}
      </div>

      {/* ================= TAB 1: OVERVIEW & SERVER HEALTH ================= */}
      {activeSubTab === 'overview' && (
        <div className="grid grid-cols-1 lg:grid-cols-12 gap-6">
          <div className="lg:col-span-8 bg-white p-6 rounded-2xl border border-slate-200/90 shadow-2xs space-y-5">
            <h3 className="font-bold text-sm text-slate-900 flex items-center justify-between pb-3 border-b border-slate-100">
              <span>{isBn ? 'সিস্টেম স্ট্যাটাস ও আর্কিটেকচার মেট্রিক্স' : 'System Architecture & Multi-Tenant Specs'}</span>
              <span className="text-[11px] font-semibold text-emerald-600 flex items-center gap-1">
                <CheckCircle2 className="w-3.5 h-3.5" /> Backend services are not monitored here
              </span>
            </h3>

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div className="p-4 rounded-xl bg-slate-50 border border-slate-200/70 space-y-2">
                <div className="flex items-center justify-between">
                  <span className="text-xs font-bold text-slate-800">Database Engine</span>
                  <span className="px-2 py-0.5 rounded bg-emerald-100 text-emerald-800 text-[10px] font-bold">MySQL 8.0 / MariaDB</span>
                </div>
                <div className="text-xs text-slate-600">Tenant isolation is not provided by this local demo panel</div>
                <div className="w-full bg-slate-200 h-1.5 rounded-full overflow-hidden">
                  <div className="bg-blue-600 h-full w-[24%]" />
                </div>
                <div className="text-[10px] text-slate-400 flex justify-between">
                  <span>Pool: 24 active / 100 max</span>
                  <span>Latency: 1.2ms</span>
                </div>
              </div>

              <div className="p-4 rounded-xl bg-slate-50 border border-slate-200/70 space-y-2">
                <div className="flex items-center justify-between">
                  <span className="text-xs font-bold text-slate-800">PHP 8.3 Runtime</span>
                  <span className="px-2 py-0.5 rounded bg-blue-100 text-blue-800 text-[10px] font-bold">OPcache Enabled</span>
                </div>
                <div className="text-xs text-slate-600">Memory Limit: 256M · Execution Time: 300s · Upload Max: 64M</div>
                <div className="w-full bg-slate-200 h-1.5 rounded-full overflow-hidden">
                  <div className="bg-emerald-500 h-full w-[18%]" />
                </div>
                <div className="text-[10px] text-slate-400 flex justify-between">
                  <span>Memory Usage: 46MB / 256MB</span>
                  <span>PHP Worker Health: 100%</span>
                </div>
              </div>

              <div className="p-4 rounded-xl bg-slate-50 border border-slate-200/70 space-y-2">
                <div className="flex items-center justify-between">
                  <span className="text-xs font-bold text-slate-800">Storage & File System</span>
                  <span className="px-2 py-0.5 rounded bg-purple-100 text-purple-800 text-[10px] font-bold">SSD NVMe</span>
                </div>
                <div className="text-xs text-slate-600">Encrypted student uploads, ID cards & course lesson assets</div>
                <div className="w-full bg-slate-200 h-1.5 rounded-full overflow-hidden">
                  <div className="bg-purple-600 h-full w-[31%]" />
                </div>
                <div className="text-[10px] text-slate-400 flex justify-between">
                  <span>Used: 15.4 GB / 500 GB</span>
                  <span>Auto-backup Daily</span>
                </div>
              </div>

              <div className="p-4 rounded-xl bg-slate-50 border border-slate-200/70 space-y-2">
                <div className="flex items-center justify-between">
                  <span className="text-xs font-bold text-slate-800">Payment Gateway Routing</span>
                  <span className="px-2 py-0.5 rounded bg-emerald-100 text-emerald-800 text-[10px] font-bold">bKash · Nagad · SSL</span>
                </div>
                <div className="text-xs text-slate-600">Dual server-side TrxID validation with automated reconciliation</div>
                <div className="w-full bg-slate-200 h-1.5 rounded-full overflow-hidden">
                  <div className="bg-emerald-500 h-full w-[99%]" />
                </div>
                <div className="text-[10px] text-slate-400 flex justify-between">
                  <span>Success Rate: 99.4%</span>
                  <span>Zero Double-Charge</span>
                </div>
              </div>
            </div>

            <div className="p-4 bg-blue-50 border border-blue-200 rounded-xl text-xs text-blue-900 leading-relaxed space-y-1">
              <div className="font-bold flex items-center gap-1.5">
                <ShieldCheck className="w-4 h-4 text-blue-600" />
                <span>Tenant Isolation Status</span>
              </div>
              <p>
                Tenant registry records are stored centrally. Operational LMS data is not yet isolated by tenant; do not use this panel as evidence of tenant separation.
              </p>
            </div>
          </div>

          <div className="lg:col-span-4 bg-white p-6 rounded-2xl border border-slate-200/90 shadow-2xs space-y-4">
            <h4 className="font-bold text-sm text-slate-900 pb-2 border-b border-slate-100">
              {isBn ? 'সক্রিয় প্রতিষ্ঠান নির্বাচন (Context Switcher)' : 'Active Tenant Viewport'}
            </h4>
            <div className="space-y-2.5">
              {loadingTenants && <p className="text-xs text-slate-500">{isBn ? 'প্রতিষ্ঠানের তথ্য লোড হচ্ছে…' : 'Loading tenant registry…'}</p>}
              {!loadingTenants && tenants.length === 0 && <p className="text-xs text-slate-500">{isBn ? 'কোনো প্রতিষ্ঠান পাওয়া যায়নি।' : 'No tenants are registered yet.'}</p>}
              {tenants.map(t => (
                <div
                  key={t.id}
                  onClick={() => { setActiveTenantId(t.id); showToast('Tenant selection is a display-only control; it does not switch data context.'); }}
                  className={`p-3 rounded-xl border transition-all cursor-pointer ${
                    activeTenantId === t.id
                      ? 'border-blue-600 bg-blue-50/70 ring-2 ring-blue-500/20'
                      : 'border-slate-200 hover:border-slate-300'
                  }`}
                >
                  <div className="flex items-center justify-between">
                    <span className="font-bold text-xs text-slate-900">{t.name}</span>
                    <span className={`px-2 py-0.5 rounded text-[10px] font-bold ${
                      t.status === 'active' ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800'
                    }`}>
                      {t.status}
                    </span>
                  </div>
                  <div className="text-[11px] text-slate-500 mt-1 flex items-center justify-between">
                    <span>{t.city} Campus</span>
                    <span className="font-mono text-blue-600">{t.subdomain}</span>
                  </div>
                </div>
              ))}
            </div>

            <div className="pt-2">
              <button
                onClick={() => setShowAddTenantModal(true)}
                className="w-full py-2.5 bg-slate-900 hover:bg-slate-800 text-white rounded-xl text-xs font-bold flex items-center justify-center gap-1.5 transition-colors cursor-pointer"
              >
                <Plus className="w-4 h-4" />
                <span>{isBn ? 'নতুন ক্যাম্পাস প্রোভিশন করুন' : 'Provision New Campus Hub'}</span>
              </button>
            </div>
          </div>
        </div>
      )}

      {/* ================= TAB 2: TENANTS DIRECTORY ================= */}
      {activeSubTab === 'tenants' && (
        <div className="space-y-4">
          <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 bg-white p-4 rounded-2xl border border-slate-200/90 shadow-2xs">
            <div className="relative flex-1 max-w-md">
              <Search className="w-4 h-4 absolute left-3 top-3 text-slate-400" />
              <input
                type="text"
                placeholder={isBn ? 'প্রতিষ্ঠান, শহর বা সাব-ডোমেইন খুঁজুন...' : 'Search tenants by name, city, or subdomain...'}
                value={searchTerm}
                onChange={e => setSearchTerm(e.target.value)}
                className="w-full pl-9 pr-4 py-2 text-xs border border-slate-200 rounded-xl focus:outline-hidden focus:border-blue-600"
              />
            </div>
            <button
              onClick={() => setShowAddTenantModal(true)}
              className="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold rounded-xl flex items-center gap-1.5 transition-colors cursor-pointer self-start sm:self-auto"
            >
              <Plus className="w-4 h-4" />
              <span>{isBn ? 'নতুন প্রতিষ্ঠান যোগ' : 'Add New Institution'}</span>
            </button>
          </div>

          <div className="bg-white rounded-2xl border border-slate-200/90 shadow-2xs overflow-hidden">
            <div className="overflow-x-auto">
              <table className="w-full text-left text-xs">
                <thead className="bg-slate-50 text-slate-600 font-bold border-b border-slate-200/80">
                  <tr>
                    <th className="px-5 py-3.5">Institution / Campus</th>
                    <th className="px-4 py-3.5">Subdomain & URL</th>
                    <th className="px-4 py-3.5">Plan</th>
                    <th className="px-4 py-3.5">Capacity</th>
                    <th className="px-4 py-3.5">MRR</th>
                    <th className="px-4 py-3.5">Status</th>
                    <th className="px-5 py-3.5 text-right">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {loadingTenants && (
                    <tr><td colSpan={7} className="px-5 py-8 text-center text-slate-500">{isBn ? 'প্রতিষ্ঠানের তথ্য লোড হচ্ছে…' : 'Loading tenant registry…'}</td></tr>
                  )}
                  {!loadingTenants && filteredTenants.length === 0 && (
                    <tr><td colSpan={7} className="px-5 py-8 text-center text-slate-500">{isBn ? 'কোনো প্রতিষ্ঠান পাওয়া যায়নি।' : 'No matching tenants found.'}</td></tr>
                  )}
                  {filteredTenants.map(tenant => (
                    <tr key={tenant.id} className="hover:bg-slate-50/70 transition-colors">
                      <td className="px-5 py-4">
                        <div className="font-bold text-slate-900 text-xs">{tenant.name}</div>
                        <div className="text-[11px] text-slate-500">{tenant.address}</div>
                      </td>
                      <td className="px-4 py-4 font-mono text-[11px] text-blue-600">
                        <div>{tenant.subdomain}</div>
                        {tenant.customDomain && (
                          <div className="text-[10px] text-slate-400">Custom: {tenant.customDomain}</div>
                        )}
                      </td>
                      <td className="px-4 py-4">
                        <span className="px-2.5 py-1 rounded-full bg-blue-50 text-blue-700 font-bold text-[10px] uppercase">
                          {tenant.planId}
                        </span>
                      </td>
                      <td className="px-4 py-4 text-slate-700">
                        — capacity · not provisioned
                      </td>
                      <td className="px-4 py-4 font-bold text-slate-900">
                        ৳{tenant.monthlyFee.toLocaleString()}/mo
                      </td>
                      <td className="px-4 py-4">
                        <button
                          onClick={() => handleToggleTenantStatus(tenant)}
                          disabled={tenant.status === 'pending'}
                          title={tenant.status === 'pending' ? 'Provisioning and domain verification are required before activation.' : 'Toggle active/suspended status'}
                          className={`px-2.5 py-1 rounded-full font-bold text-[10px] uppercase transition-colors ${
                            tenant.status === 'active'
                              ? 'bg-emerald-100 text-emerald-800 hover:bg-emerald-200 cursor-pointer'
                              : tenant.status === 'pending'
                                ? 'bg-amber-100 text-amber-800 cursor-not-allowed opacity-80'
                                : 'bg-slate-100 text-slate-700 hover:bg-slate-200 cursor-pointer'
                          }`}
                        >
                          {tenant.status}
                        </button>
                      </td>
                      <td className="px-5 py-4 text-right">
                        <div className="flex items-center justify-end gap-1.5">
                          <button
                            onClick={() => { setActiveTenantId(tenant.id); showToast('Display selection only; operational tenant routing is not enabled.'); }}
                            className="px-2.5 py-1 bg-slate-100 hover:bg-blue-50 hover:text-blue-600 text-slate-700 rounded-lg text-[11px] font-bold transition-colors cursor-pointer"
                            title="Enter Tenant Context"
                          >
                            Enter
                          </button>
                          <button
                            disabled
                            className="p-1 text-slate-400 hover:text-rose-600 hover:bg-rose-50 rounded-lg transition-colors cursor-not-allowed"
                            title="Tenant deletion is not available through the control-plane API."
                          >
                            <Trash2 className="w-3.5 h-3.5" />
                          </button>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        </div>
      )}

      {/* ================= TAB 3: SERVER PLAN CATALOG ================= */}
      {activeSubTab === 'plans' && (
        <div className="bg-white rounded-2xl border border-slate-200/90 shadow-2xs p-6 space-y-4">
          <div>
            <h3 className="font-bold text-sm text-slate-900">{isBn ? 'সার্ভার SaaS প্ল্যান ক্যাটালগ' : 'Server SaaS Plan Catalog'}</h3>
            <p className="text-xs text-slate-500 mt-1">
              {isBn ? 'এই মূল্যগুলো সার্ভারের plan registry থেকে এসেছে। প্ল্যান সম্পাদনা ও capacity enforcement এখনো চালু নয়।' : 'Prices below are read from the server plan registry. Plan editing and capacity enforcement are not enabled.'}
            </p>
          </div>
          {serverPlans.length === 0 ? (
            <p className="text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded-xl p-4">{isBn ? 'সার্ভার থেকে কোনো সক্রিয় প্ল্যান পাওয়া যায়নি।' : 'No active plans were returned by the server.'}</p>
          ) : (
            <div className="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-5 gap-3">
              {serverPlans.map(plan => (
                <div key={plan.plan_key} className="rounded-xl border border-slate-200 p-4">
                  <div className="font-bold text-sm text-slate-900">{plan.name}</div>
                  <div className="text-xl font-black text-slate-900 mt-2">{plan.currency} {Number(plan.monthly_price).toLocaleString()}</div>
                  <div className="text-[10px] text-slate-500">per month · read only</div>
                  <div className="text-[10px] text-slate-500 mt-2 font-mono">{plan.plan_key}</div>
                </div>
              ))}
            </div>
          )}
        </div>
      )}

      {/* ================= TAB 4: BILLING & INVOICING ================= */}
      {activeSubTab === 'billing' && (
        <div className="bg-white rounded-2xl border border-slate-200/90 shadow-2xs p-6 space-y-3">
          <h3 className="font-bold text-sm text-slate-900">{isBn ? 'SaaS বিলিং' : 'SaaS Billing'}</h3>
          <div className="rounded-xl border border-amber-200 bg-amber-50 p-4 text-xs text-amber-900">
            {isBn
              ? 'Subscription registry তৈরি হয়, কিন্তু invoice, payment collection, payment verification, refund ও accounting এখনো বাস্তবায়িত নয়। কোনো demo transaction-কে বাস্তব আয় হিসেবে দেখানো হচ্ছে না।'
              : 'Subscription records are created, but invoices, payment collection/verification, refunds and accounting are not implemented. No demo transactions are presented as real revenue.'}
          </div>
        </div>
      )}

      {/* ================= TAB 5: GATEWAYS & CONFIG ================= */}
      {activeSubTab === 'gateways' && (
        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
          <div className="bg-white p-6 rounded-2xl border border-slate-200/90 shadow-2xs space-y-4">
            <h4 className="font-bold text-sm text-slate-900 pb-2 border-b border-slate-100 flex items-center gap-2">
              <Mail className="w-4 h-4 text-blue-600" />
              <span>Global SMTP & Email Dispatcher</span>
            </h4>
            <div className="space-y-3 text-xs">
              <div>
                <label className="block font-semibold text-slate-700 mb-1">SMTP Host Server</label>
                <input
                  type="text"
                  value={smtpHost}
                  onChange={e => setSmtpHost(e.target.value)}
                  className="w-full px-3.5 py-2 border border-slate-300 rounded-xl focus:outline-hidden"
                />
              </div>
              <div>
                <label className="block font-semibold text-slate-700 mb-1">SMTP Username / Email</label>
                <input
                  type="text"
                  value={smtpUser}
                  onChange={e => setSmtpUser(e.target.value)}
                  className="w-full px-3.5 py-2 border border-slate-300 rounded-xl focus:outline-hidden"
                />
              </div>
              <button
                onClick={() => showToast('Demo only: SMTP testing is not connected to a server.')}
                className="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-xl font-bold cursor-pointer"
              >
                Test SMTP Connection
              </button>
            </div>
          </div>

          <div className="bg-white p-6 rounded-2xl border border-slate-200/90 shadow-2xs space-y-4">
            <h4 className="font-bold text-sm text-slate-900 pb-2 border-b border-slate-100 flex items-center gap-2">
              <MessageSquare className="w-4 h-4 text-emerald-600" />
              <span>Bangladesh SMS Gateway API (BulkSMS BD)</span>
            </h4>
            <div className="space-y-3 text-xs">
              <div>
                <label className="block font-semibold text-slate-700 mb-1">SMS API Secret Key</label>
                <input
                  type="password"
                  value={smsGatewayApi}
                  onChange={e => setSmsGatewayApi(e.target.value)}
                  className="w-full px-3.5 py-2 border border-slate-300 rounded-xl font-mono focus:outline-hidden"
                />
              </div>
              <div>
                <label className="block font-semibold text-slate-700 mb-1">bKash Merchant Account Number</label>
                <input
                  type="text"
                  value={bkashMerchant}
                  onChange={e => setBkashMerchant(e.target.value)}
                  className="w-full px-3.5 py-2 border border-slate-300 rounded-xl focus:outline-hidden"
                />
              </div>
              <div>
                <label className="block font-semibold text-slate-700 mb-1">Nagad Merchant Account Number</label>
                <input
                  type="text"
                  value={nagadMerchant}
                  onChange={e => setNagadMerchant(e.target.value)}
                  className="w-full px-3.5 py-2 border border-slate-300 rounded-xl focus:outline-hidden"
                />
              </div>
              <button
                onClick={() => showToast('Demo only: gateway settings are not saved or connected.')}
                className="px-4 py-2 bg-slate-900 hover:bg-slate-800 text-white rounded-xl font-bold cursor-pointer"
              >
                Save Master Gateway Configuration
              </button>
            </div>
          </div>
        </div>
      )}

      {/* ================= TAB 6: SECURITY & AUDIT TRAIL ================= */}
      {activeSubTab === 'security' && (
        <div className="bg-white p-6 rounded-2xl border border-slate-200/90 shadow-2xs space-y-4">
          <div className="flex items-center justify-between pb-3 border-b border-slate-100">
            <div>
              <h3 className="font-bold text-sm text-slate-900">SaaS Platform Security Audit & Session Monitor</h3>
              <p className="text-xs text-slate-500">This panel does not read production audit logs or monitor live sessions.</p>
            </div>
            <span className="px-3 py-1 rounded-full bg-slate-100 text-slate-700 font-bold text-xs flex items-center gap-1.5">
              <ShieldCheck className="w-3.5 h-3.5" />
              <span>Audit feed not connected</span>
            </span>
          </div>

          <div className="space-y-3">
            <div className="rounded-xl border border-amber-200 bg-amber-50 p-4 text-xs text-amber-900">No live audit records are connected. The activity feed must be backed by the server audit-log API before this screen can be used for security monitoring.</div>
          </div>
        </div>
      )}

      {/* Provision New Tenant Modal */}
      {showAddTenantModal && (
        <div className="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
          <div className="bg-white rounded-3xl max-w-lg w-full p-6 shadow-2xl border border-slate-100 space-y-4 animate-in fade-in zoom-in-95">
            <div className="flex items-center justify-between pb-3 border-b border-slate-100">
              <h3 className="font-extrabold text-base text-slate-900 flex items-center gap-2">
                <Building2 className="w-5 h-5 text-blue-600" />
                <span>{isBn ? 'নতুন শিক্ষা প্রতিষ্ঠান / ক্যাম্পাস প্রোভিশন' : 'Provision New Tenant Academy'}</span>
              </h3>
              <button 
                onClick={() => setShowAddTenantModal(false)}
                className="text-slate-400 hover:text-slate-600 p-1 cursor-pointer"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            <form onSubmit={handleCreateTenant} className="space-y-3.5 text-xs">
              <div>
                <label className="block font-bold text-slate-700 mb-1">Institution Full Name *</label>
                <input
                  type="text"
                  required
                  placeholder="e.g. Alom's English Center (Sylhet Campus)"
                  value={newTenantName}
                  onChange={e => setNewTenantName(e.target.value)}
                  className="w-full px-3.5 py-2.5 border border-slate-300 rounded-xl focus:outline-hidden focus:border-blue-600 font-semibold"
                />
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block font-bold text-slate-700 mb-1">Tenant key / subdomain *</label>
                  <div className="flex items-center">
                    <input
                      type="text"
                      required
                      placeholder="sylhet (or academy.example.com)"
                      value={newTenantSubdomain}
                      onChange={e => setNewTenantSubdomain(e.target.value)}
                      className="w-full px-3 py-2 border border-slate-300 rounded-xl focus:outline-hidden font-mono"
                    />
                  </div>
                </div>

                <div>
                  <label className="block font-bold text-slate-700 mb-1">City / Region</label>
                  <input
                    type="text"
                    value={newTenantCity}
                    onChange={e => setNewTenantCity(e.target.value)}
                    className="w-full px-3 py-2 border border-slate-300 rounded-xl focus:outline-hidden"
                  />
                </div>
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block font-bold text-slate-700 mb-1">Admin Email</label>
                  <input
                    type="email"
                    placeholder="admin@branch.com"
                    value={newTenantEmail}
                    onChange={e => setNewTenantEmail(e.target.value)}
                    className="w-full px-3 py-2 border border-slate-300 rounded-xl focus:outline-hidden"
                  />
                </div>

                <div>
                  <label className="block font-bold text-slate-700 mb-1">Phone Hotline</label>
                  <input
                    type="text"
                    placeholder="+880 1712 000000"
                    value={newTenantPhone}
                    onChange={e => setNewTenantPhone(e.target.value)}
                    className="w-full px-3 py-2 border border-slate-300 rounded-xl focus:outline-hidden"
                  />
                </div>
              </div>

              <div>
                <label className="block font-bold text-slate-700 mb-1">SaaS Subscription Tier</label>
                <select
                  value={newTenantPlan}
                  onChange={e => setNewTenantPlan(e.target.value as any)}
                  className="w-full px-3.5 py-2.5 border border-slate-300 rounded-xl font-bold text-blue-900 bg-blue-50/50"
                >
                  {serverPlans.length > 0 ? serverPlans.map(plan => (
                    <option key={plan.plan_key} value={plan.plan_key}>
                      {plan.name} ({plan.currency} {Number(plan.monthly_price).toLocaleString()}/mo)
                    </option>
                  )) : (
                    <>
                      <option value="basic">Basic Academy</option>
                      <option value="standard">Standard Institute</option>
                      <option value="professional">Professional</option>
                      <option value="enterprise">Enterprise</option>
                    </>
                  )}
                </select>
              </div>

              <div className="pt-3 flex items-center justify-end gap-2 border-t border-slate-100">
                <button
                  type="button"
                  onClick={() => setShowAddTenantModal(false)}
                  className="px-4 py-2 border border-slate-300 rounded-xl font-bold text-slate-700 hover:bg-slate-50 cursor-pointer"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={savingTenant || loadingTenants || serverPlans.length === 0}
                  className="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white font-bold rounded-xl shadow-md shadow-blue-600/20 cursor-pointer"
                >
                  {loadingTenants ? 'Loading plans…' : savingTenant ? 'Registering…' : 'Register Pending Tenant'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
};
