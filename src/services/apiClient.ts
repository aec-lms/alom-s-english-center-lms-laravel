/**
 * Alom's English Center - same-origin PHP API client.
 * Session cookies are HttpOnly; CSRF tokens remain in memory only.
 */

const API_BASE = '/api';

export interface ApiResponse<T = unknown> {
  success: boolean;
  message?: string;
  data?: T;
  error?: string;
}

export interface CertificateApiRecord {
  id: number;
  certificateNumber: string;
  studentUserId: number;
  studentId: string;
  studentName: string;
  courseId: number;
  courseTitle: string;
  completionDate: string;
  grade: string;
  issueDate: string;
  authorizedBy: string;
  status: 'issued' | 'revoked';
  revokedAt: string | null;
  revokeReason: string | null;
}

export interface CertificateVerification {
  certificateNumber: string;
  studentName: string;
  courseTitle: string;
  completionDate: string;
  grade: string;
  authorizedBy: string;
  issueDate: string;
  status: 'issued' | 'revoked';
  valid: boolean;
  revokedAt: string | null;
}

class ApiService {
  private csrfToken: string | null = null;

  private async request<T>(endpoint: string, options: RequestInit = {}): Promise<ApiResponse<T>> {
    const method = (options.method || 'GET').toUpperCase();
    const isFormData = typeof FormData !== 'undefined' && options.body instanceof FormData;
    const headers: Record<string, string> = {
      Accept: 'application/json',
      ...(options.body && !isFormData ? { 'Content-Type': 'application/json' } : {}),
      ...(options.headers as Record<string, string> | undefined),
    };
    if (method !== 'GET' && !endpoint.startsWith('/auth.php?action=login') && this.csrfToken) {
      headers['X-CSRF-Token'] = this.csrfToken;
    }

    try {
      const response = await fetch(`${API_BASE}${endpoint}`, {
        ...options, method, credentials: 'same-origin', headers,
      });
      const payload = await response.json().catch(() => ({}));
      if (!response.ok || payload.success === false) {
        return {
          success: false, message: payload.message,
          error: payload.message || payload.error || `Server responded with status ${response.status}`,
        };
      }
      return payload as ApiResponse<T>;
    } catch (error) {
      return { success: false, error: error instanceof Error ? error.message : 'Network error communicating with LMS API' };
    }
  }

  public pluginPackages = {
    list: () => this.request<Array<{ id: string; name: string; version: string; enabled: boolean; rollback_snapshots: string[] }>>('/plugin-updater.php?action=list'),
    install: (file: File) => {
      const body = new FormData();
      body.append('package', file);
      return this.request<Record<string, unknown>>('/plugin-updater.php?action=install', { method: 'POST', body });
    },
    toggle: (id: string, enabled: boolean) =>
      this.request<Record<string, unknown>>('/plugin-updater.php?action=toggle', { method: 'POST', body: JSON.stringify({ id, enabled }) }),
    rollback: (id: string, snapshot: string) =>
      this.request<{ id: string }>('/plugin-updater.php?action=rollback', { method: 'POST', body: JSON.stringify({ id, snapshot }) }),
  };

  public saas = {
    listTenants: () => this.request<Array<Record<string, unknown>>>('/saas-tenants.php?action=list'),
    getPlans: () => this.request<Array<{ plan_key: string; name: string; monthly_price: number; currency: string }>>('/saas-tenants.php?action=plans'),
    createTenant: (data: { tenant_key: string; name: string; contact_email?: string; contact_phone?: string; city?: string; plan_key: string; domain?: string }) =>
      this.request<{ id: number; tenant_key: string; status: string; provisioned: boolean }>('/saas-tenants.php?action=create', { method: 'POST', body: JSON.stringify(data) }),
    updateTenantStatus: (id: number, status: 'active' | 'suspended') =>
      this.request<{ id: number; status: string }>(`/saas-tenants.php?action=status&id=${encodeURIComponent(String(id))}`, { method: 'POST', body: JSON.stringify({ status }) }),
  };

  public reports = {
    get: (type: 'revenue' | 'admission' | 'attendance' | 'exams' | 'expenses' | 'facility_fees' | 'trial_balance' | 'income_statement', period: 'current_month' | 'last_month' | 'last_30_days' | 'current_year', branchId: string | number = 'all') =>
      this.request<{ type: string; period: string; rows: Record<string, unknown>[] }>(`/reports.php?type=${encodeURIComponent(type)}&period=${encodeURIComponent(period)}&branch_id=${encodeURIComponent(String(branchId))}`),
  };

  public commissions = {
    getSummary: (teacherId?: string | number) => this.request<{
      balance: { gross_commission: number; paid_commission: number; reserved_payout: number; available_balance: number };
      courses: Array<Record<string, unknown>>;
    }>(`/commissions.php?action=summary${teacherId ? `&teacher_id=${encodeURIComponent(String(teacherId))}` : ''}`),
    getRules: () => this.request<Array<Record<string, unknown>>>('/commissions.php?action=rules'),
    saveRule: (data: { course_id: number; teacher_user_id: number; commission_rate: number }) =>
      this.request<{ id: number }>('/commissions.php?action=set_rule', { method: 'POST', body: JSON.stringify(data) }),
    getPayouts: () => this.request<Array<Record<string, unknown>>>('/commissions.php?action=payouts'),
    requestPayout: (data: { amount: number; payout_method: 'bKash' | 'Nagad' | 'Bank Transfer'; account_number: string; bank_name?: string; bank_branch?: string; remarks?: string }) =>
      this.request<{ id: number }>('/commissions.php?action=request_payout', { method: 'POST', body: JSON.stringify(data) }),
    approvePayout: (id: string | number) =>
      this.request<{ id: number; status: string }>(`/commissions.php?action=approve&id=${encodeURIComponent(String(id))}`, { method: 'POST', body: JSON.stringify({}) }),
    rejectPayout: (id: string | number, reason: string) =>
      this.request<{ id: number; status: string }>(`/commissions.php?action=reject&id=${encodeURIComponent(String(id))}`, { method: 'POST', body: JSON.stringify({ reason }) }),
    completePayout: (id: string | number, transaction_reference: string) =>
      this.request<{ id: number; status: string }>(`/commissions.php?action=complete&id=${encodeURIComponent(String(id))}`, { method: 'POST', body: JSON.stringify({ transaction_reference }) }),
  };

  public accountVerification = {
    emailDelivery: () => this.request<{ summary: Record<string, number>; items: Array<{ id: number; recipient_email: string; subject: string; status: string; attempts: number; max_attempts: number; next_attempt_at: string; sent_at: string | null; last_error_code: string | null; created_at: string }> }>('/accounts.php?action=email_delivery'),
    requestEmail: () => this.request<{ email: string }>('/accounts.php?action=request_verification', { method: 'POST', body: JSON.stringify({}) }),
    requestPhone: (phone: string) => this.request<{ phone: string; expires_in_seconds: number }>('/phone-verification.php?action=request', { method: 'POST', body: JSON.stringify({ phone }) }),
    testSms: (phone: string) => this.request<{ sent: boolean }>('/phone-verification.php?action=test', { method: 'POST', body: JSON.stringify({ phone }) }),
    verifyPhone: (code: string) => this.request<{ verified: boolean }>('/phone-verification.php?action=verify', { method: 'POST', body: JSON.stringify({ code }) }),
  };

  public seo = {
    getPublic: (path: string) => this.request<Record<string, string>>(`/seo.php?action=meta&path=${encodeURIComponent(path)}`),
  };

  public marketingSettings = {
    getPublic: () => this.request<Record<string, string | boolean>>('/marketing-settings.php?action=public'),
    getAdmin: () => this.request<Record<string, string | boolean>>('/marketing-settings.php?action=admin_get'),
    save: (settings: Record<string, string>) =>
      this.request<{ settings: Record<string, string> }>('/marketing-settings.php?action=save', { method: 'POST', body: JSON.stringify({ settings }) }),
  };

  public notices = {
    visible: () => this.request<Array<Record<string, unknown>>>('/notices.php?action=visible'),
    list: () => this.request<Array<Record<string, unknown>>>('/notices.php?action=list'),
    create: (data: { title: string; body: string; audience_role: string; branch_id?: number | null; starts_at?: string; ends_at?: string }) =>
      this.request<{ id: number; status: string }>('/notices.php?action=create', { method: 'POST', body: JSON.stringify(data) }),
    update: (id: number, data: { title: string; body: string; audience_role: string; branch_id?: number | null; starts_at?: string; ends_at?: string }) =>
      this.request<{ id: number; status: string }>(`/notices.php?action=update&id=${encodeURIComponent(String(id))}`, { method: 'POST', body: JSON.stringify(data) }),
    updateStatus: (id: number, status: 'draft' | 'published' | 'archived') =>
      this.request<{ id: number; status: string }>(`/notices.php?action=status&id=${encodeURIComponent(String(id))}`, { method: 'POST', body: JSON.stringify({ status }) }),
    markRead: (noticeId: number) =>
      this.request<{ notice_id: number; read: boolean }>('/notices.php?action=mark_read', { method: 'POST', body: JSON.stringify({ notice_id: noticeId }) }),
  };

  public promotions = {
    getActive: (placement: 'homepage_featured' | 'course_listing' | 'branch_directory' | 'notice_banner' = 'homepage_featured') =>
      this.request<Array<Record<string, unknown>>>(`/promotions.php?action=active&placement=${encodeURIComponent(placement)}`),
    list: () => this.request<Array<Record<string, unknown>>>('/promotions.php?action=list'),
    create: (data: { campaign_name: string; entity_type: 'course' | 'institution' | 'branch'; entity_id?: number | null; placement: 'homepage_featured' | 'course_listing' | 'branch_directory' | 'notice_banner'; starts_at: string; ends_at: string; priority?: number; destination_url?: string }) =>
      this.request<{ id: number; status: string }>('/promotions.php?action=create', { method: 'POST', body: JSON.stringify(data) }),
    updateStatus: (id: number, status: 'active' | 'paused' | 'cancelled') =>
      this.request<{ id: number; status: string }>(`/promotions.php?action=status&id=${encodeURIComponent(String(id))}`, { method: 'POST', body: JSON.stringify({ status }) }),
    track: (campaignId: number, eventType: 'impression' | 'click') =>
      this.request<{ tracked: boolean }>('/promotions.php?action=track', { method: 'POST', keepalive: true, body: JSON.stringify({ campaign_id: campaignId, event_type: eventType }) }),
  };

  public aiAssistant = {
    chat: (message: string) => this.request<{ reply: string; proposal: { action_type: string; payload: Record<string, unknown> } | null; mode: 'approval_required' }>('/ai-assistant.php', {
      method: 'POST', body: JSON.stringify({ message }),
    }),
  };

  public aiActions = {
    preview: (actionType: string, payload: Record<string, unknown>) =>
      this.request<{ proposal_id: number; expires_in_seconds: number; preview: Record<string, unknown> }>('/ai-actions.php?action=preview', { method: 'POST', body: JSON.stringify({ action_type: actionType, payload }) }),
    approve: (proposalId: number, confirmation: string) =>
      this.request<{ proposal_id: number; status: string; result: Record<string, unknown> }>('/ai-actions.php?action=approve', { method: 'POST', body: JSON.stringify({ proposal_id: proposalId, confirmation }) }),
    rollback: (proposalId: number, confirmation: string) =>
      this.request<{ proposal_id: number; status: string }>('/ai-actions.php?action=rollback', { method: 'POST', body: JSON.stringify({ proposal_id: proposalId, confirmation }) }),
  };

  public auth = {
    login: async (email: string, password: string) => {
      const result = await this.request<{ user: unknown; csrf_token: string }>('/auth.php?action=login', {
        method: 'POST', body: JSON.stringify({ email, password }),
      });
      if (result.success && result.data?.csrf_token) this.csrfToken = result.data.csrf_token;
      return result;
    },
    logout: async () => {
      const result = await this.request('/auth.php?action=logout', { method: 'POST' });
      if (result.success) this.csrfToken = null;
      return result;
    },
    me: async () => {
      const result = await this.request<{ user: unknown; csrf_token: string }>('/auth.php?action=me');
      if (result.success && result.data?.csrf_token) this.csrfToken = result.data.csrf_token;
      return result;
    },
    updatePassword: async (currentPassword: string, newPassword: string) => {
      const result = await this.request<{ csrf_token: string }>('/auth.php?action=change_password', {
        method: 'POST', body: JSON.stringify({ current_password: currentPassword, new_password: newPassword }),
      });
      if (result.success && result.data?.csrf_token) this.csrfToken = result.data.csrf_token;
      return result;
    },
  };

  public users = {
    getAll: (query = '', limit = 50) => this.request<unknown[]>(`/users.php?action=list&limit=${encodeURIComponent(String(limit))}&q=${encodeURIComponent(query)}`),
    create: (user: { name: string; email: string; password: string; role: string; phone?: string; branch_id?: number | null }) =>
      this.request<{ id: number }>('/users.php?action=create', { method: 'POST', body: JSON.stringify(user) }),
    update: (id: string | number, user: Record<string, unknown>) =>
      this.request<{ id: number }>(`/users.php?action=update&id=${encodeURIComponent(id)}`, { method: 'POST', body: JSON.stringify(user) }),
    deactivate: (id: string | number) =>
      this.request<{ id: number }>(`/users.php?action=deactivate&id=${encodeURIComponent(id)}`, { method: 'POST' }),
  };

  public categories = {
    getAll: () => this.request<unknown[]>('/categories.php?action=list'),
    create: (category: { name: string; slug: string; name_bn?: string; parent_id?: number | null; description?: string; sort_order?: number }) =>
      this.request<{ id: number }>('/categories.php?action=create', { method: 'POST', body: JSON.stringify(category) }),
    update: (id: string | number, category: { name: string; slug: string; name_bn?: string; parent_id?: number | null; description?: string; sort_order?: number }) =>
      this.request<{ id: number }>(`/categories.php?action=update&id=${encodeURIComponent(String(id))}`, { method: 'POST', body: JSON.stringify(category) }),
    delete: (id: string | number) =>
      this.request(`/categories.php?action=delete&id=${encodeURIComponent(String(id))}`, { method: 'POST' }),
  };

  public courses = {
    getAll: () => this.request<Record<string, unknown>[]>('/courses.php?action=list'),
    getById: (id: string | number) => this.request<Record<string, unknown>>(`/courses.php?action=detail&id=${encodeURIComponent(id)}`),
    create: (courseData: Record<string, unknown>) => this.request<{ id: number }>('/courses.php?action=create', { method: 'POST', body: JSON.stringify(courseData) }),
    update: (id: string | number, courseData: Record<string, unknown>) => this.request<{ id: number }>(`/courses.php?action=update&id=${encodeURIComponent(id)}`, { method: 'POST', body: JSON.stringify(courseData) }),
    delete: (id: string | number) => this.request<{ id?: number }>(`/courses.php?action=delete&id=${encodeURIComponent(id)}`, { method: 'POST' }),
    restore: (id: string | number) => this.request<{ id: number }>(`/courses.php?action=restore&id=${encodeURIComponent(id)}`, { method: 'POST' }),
  };

  public content = {
    getAll: (type?: string) => this.request<Record<string, unknown>[]>(`/content.php?action=list${type ? `&type=${encodeURIComponent(type)}` : ''}`),
    getPublic: (type?: string) => this.request<Record<string, unknown>[]>(`/content.php?action=list&public=1${type ? `&type=${encodeURIComponent(type)}` : ''}`),
    getById: (id: string | number) => this.request<Record<string, unknown>>(`/content.php?action=detail&id=${encodeURIComponent(id)}`),
    getLinks: (id: string | number) => this.request<Array<{ course_id: number; course_title: string; sort_order: number; is_preview: number }>>(`/content.php?action=links&id=${encodeURIComponent(id)}`),
    getProgress: (courseId: string | number) => this.request<Array<{ content_item_id: number; is_completed: number; notes: string | null; updated_at: string }>>(`/content.php?action=progress&course_id=${encodeURIComponent(courseId)}`),
    saveProgress: (data: { course_id: number; content_item_id: number; is_completed: boolean; notes: string }) =>
      this.request<{ course_id: number; content_item_id: number; is_completed: boolean; notes: string }>('/content.php?action=progress_update', {
        method: 'POST', body: JSON.stringify(data),
      }),
    create: (item: Record<string, unknown>) => this.request<{ id: number }>('/content.php?action=create', { method: 'POST', body: JSON.stringify(item) }),
    update: (id: string | number, item: Record<string, unknown>) => this.request<{ id: number }>(`/content.php?action=update&id=${encodeURIComponent(id)}`, { method: 'POST', body: JSON.stringify(item) }),
    delete: (id: string | number) => this.request<{ id?: number }>(`/content.php?action=delete&id=${encodeURIComponent(id)}`, { method: 'POST' }),
    syncLinks: (contentItemId: string | number, links: Array<{ course_id: number; sort_order: number; is_preview: boolean }>) =>
      this.request<{ content_item_id: number; linked_courses: number }>('/content.php?action=sync_links', {
        method: 'POST', body: JSON.stringify({ content_item_id: contentItemId, links }),
      }),
    attachToCourse: (courseId: string | number, contentItemId: string | number, sortOrder = 0, isPreview = false) =>
      this.request('/content.php?action=attach', { method: 'POST', body: JSON.stringify({ course_id: courseId, content_item_id: contentItemId, sort_order: sortOrder, is_preview: isPreview }) }),
    detachFromCourse: (courseId: string | number, contentItemId: string | number) =>
      this.request('/content.php?action=detach', { method: 'POST', body: JSON.stringify({ course_id: courseId, content_item_id: contentItemId }) }),
  };


  public facilityFees = {
    getMine: () => this.request<Array<Record<string, unknown>>>('/facility-fees.php?action=my'),
    getBranches: () => this.request<Array<{id:number;name:string}>>('/facility-fees.php?action=branches'),
    getPayments: (branchId?:string|number) => this.request<Array<Record<string,unknown>>>(
      `/facility-fees.php?action=payments${branchId?`&branch_id=${encodeURIComponent(String(branchId))}`:''}`,
    ),
    getAll: (branchId?:string|number, serviceType?:'hostel'|'transport', status?:string) => this.request<Array<Record<string, unknown>>>(
      `/facility-fees.php?action=list${branchId?`&branch_id=${encodeURIComponent(String(branchId))}`:''}${serviceType?`&service_type=${serviceType}`:''}${status?`&status=${status}`:''}`,
    ),
    generate: (data:{branch_id:number;service_type:'hostel'|'transport';billing_month:string}) =>
      this.request<{created_count:number;service_type:string;billing_month:string}>('/facility-fees.php?action=generate',{method:'POST',body:JSON.stringify(data)}),
    pay: (data:{invoice_id:number;amount:string|number;payment_method:'cash'|'bank_transfer'|'bKash'|'Nagad';payment_reference:string}) =>
      this.request<{payment_id:number;invoice_id:number;status:string;outstanding_amount:string;journal_reference:string}>('/facility-fees.php?action=pay',{method:'POST',body:JSON.stringify(data)}),
  };

  public expenses = {
    getBranches: () => this.request<Array<{id:number;name:string}>>('/expenses.php?action=branches'),
    getAll: (branchId?:string|number,status?:string) => this.request<Array<Record<string,unknown>>>(`/expenses.php?action=list${branchId?`&branch_id=${encodeURIComponent(String(branchId))}`:''}${status?`&status=${encodeURIComponent(status)}`:''}`),
    getSummary: (branchId?:string|number) => this.request<Array<{status:string;record_count:number;total_amount:string|number}>>(`/expenses.php?action=summary${branchId?`&branch_id=${encodeURIComponent(String(branchId))}`:''}`),
    getSuppliers: (branchId?:string|number) => this.request<Array<Record<string,unknown>>>(`/expenses.php?action=suppliers&active=1${branchId?`&branch_id=${encodeURIComponent(String(branchId))}`:''}`),
    createSupplier: (data:Record<string,unknown>) => this.request<{id:number}>('/expenses.php?action=supplier_create',{method:'POST',body:JSON.stringify(data)}),
    archiveSupplier: (id:number) => this.request<{id:number}>('/expenses.php?action=supplier_archive&id='+id,{method:'POST',body:JSON.stringify({})}),
    getBudgets: (month:string,branchId?:string|number) => this.request<Array<Record<string,unknown>>>(`/expenses.php?action=budgets&month=${encodeURIComponent(month)}${branchId?`&branch_id=${encodeURIComponent(String(branchId))}`:''}`),
    saveBudget: (data:{branch_id:number;category:string;budget_month:string;budget_amount:string|number;notes?:string}) => this.request<{id:number}>('/expenses.php?action=budget_save',{method:'POST',body:JSON.stringify(data)}),
    getRecurring: (branchId?:string|number) => this.request<Array<Record<string,unknown>>>(`/expenses.php?action=recurring_list${branchId?`&branch_id=${encodeURIComponent(String(branchId))}`:''}`),
    createRecurring: (data:Record<string,unknown>) => this.request<{id:number}>('/expenses.php?action=recurring_create',{method:'POST',body:JSON.stringify(data)}),
    archiveRecurring: (id:number) => this.request<{id:number}>('/expenses.php?action=recurring_archive&id='+id,{method:'POST',body:JSON.stringify({})}),
    generateRecurring: (data:{expense_month:string;branch_id?:number}) => this.request<{created_count:number;skipped_count:number;expense_month:string}>('/expenses.php?action=recurring_generate',{method:'POST',body:JSON.stringify(data)}),
    create: (data:{branch_id:number;category:string;description:string;vendor?:string;supplier_id?:number;amount:string|number;expense_date:string}) => this.request<{id:number}>( '/expenses.php?action=create',{method:'POST',body:JSON.stringify(data)}),
    approve: (id:number) => this.request<{id:number;status:string}>(`/expenses.php?action=approve&id=${id}`,{method:'POST',body:JSON.stringify({})}),
    reject: (id:number,reason:string) => this.request<{id:number;status:string}>(`/expenses.php?action=reject&id=${id}`,{method:'POST',body:JSON.stringify({reason})}),
    pay: (id:number,payment_method:'cash'|'bank_transfer'|'bKash'|'Nagad',payment_reference:string) => this.request<{id:number;status:string}>(`/expenses.php?action=pay&id=${id}`,{method:'POST',body:JSON.stringify({payment_method,payment_reference})}),
  };

  public transport = {
    getBranches: () => this.request<Array<{id:number;name:string}>>('/transport.php?action=branches'),
    getStudents: (branchId?:string|number) => this.request<Array<Record<string,unknown>>>(`/transport.php?action=students${branchId?`&branch_id=${encodeURIComponent(String(branchId))}`:''}`),
    getRoutes: (branchId?:string|number) => this.request<Array<Record<string,unknown>>>(`/transport.php?action=list${branchId?`&branch_id=${encodeURIComponent(String(branchId))}`:''}`),
    getAssignments: (branchId?:string|number) => this.request<Array<Record<string,unknown>>>(`/transport.php?action=assignments${branchId?`&branch_id=${encodeURIComponent(String(branchId))}`:''}`),
    createRoute: (data:Record<string,unknown>) => this.request<{id:number}>('/transport.php?action=create',{method:'POST',body:JSON.stringify(data)}),
    assignStudent: (data:{route_id:number;student_user_id:number;start_date?:string}) => this.request<{id:number}>('/transport.php?action=assign',{method:'POST',body:JSON.stringify(data)}),
    endAssignment: (id:number) => this.request<{id:number}>('/transport.php?action=end',{method:'POST',body:JSON.stringify({assignment_id:id})}),
    archiveRoute: (id:number) => this.request<{id:number}>('/transport.php?action=archive',{method:'POST',body:JSON.stringify({route_id:id})}),
  };

  public hostel = {
    getBranches: () => this.request<Array<{id:number;name:string}>>('/hostel.php?action=branches'),
    getStudents: (branchId?:string|number) => this.request<Array<Record<string,unknown>>>(`/hostel.php?action=students${branchId?`&branch_id=${encodeURIComponent(String(branchId))}`:''}`),
    getRooms: (branchId?:string|number) => this.request<Array<Record<string,unknown>>>(`/hostel.php?action=rooms${branchId?`&branch_id=${encodeURIComponent(String(branchId))}`:''}`),
    getAllocations: (branchId?:string|number) => this.request<Array<Record<string,unknown>>>(`/hostel.php?action=allocations${branchId?`&branch_id=${encodeURIComponent(String(branchId))}`:''}`),
    createRoom: (data:Record<string,unknown>) => this.request<{id:number}>('/hostel.php?action=create_room',{method:'POST',body:JSON.stringify(data)}),
    allocateStudent: (data:{room_id:number;student_user_id:number;start_date?:string;remarks?:string}) => this.request<{id:number}>('/hostel.php?action=allocate',{method:'POST',body:JSON.stringify(data)}),
    vacate: (id:number) => this.request<{id:number}>('/hostel.php?action=vacate',{method:'POST',body:JSON.stringify({allocation_id:id})}),
    archiveRoom: (id:number) => this.request<{id:number}>('/hostel.php?action=archive_room',{method:'POST',body:JSON.stringify({room_id:id})}),
  };

  public library = {
    getBranches: () => this.request<Array<{ id: number; name: string }>>('/library.php?action=branches'),
    getMyLoans: () => this.request<Array<Record<string, unknown>>>('/library.php?action=my_loans'),
    getFineBranches: () => this.request<Array<{ id: number; name: string }>>('/library.php?action=fine_branches'),
    getOutstandingFines: (branchId?: string | number) => this.request<Array<Record<string, unknown>>>(
      `/library.php?action=fine_list${branchId ? `&branch_id=${encodeURIComponent(String(branchId))}` : ''}`,
    ),
    getFinePayments: (branchId?: string | number) => this.request<Array<Record<string, unknown>>>(
      `/library.php?action=fine_payments${branchId ? `&branch_id=${encodeURIComponent(String(branchId))}` : ''}`,
    ),
    payFine: (data: { loan_id: number; amount: string | number; payment_method: 'cash' | 'bank_transfer' | 'bKash' | 'Nagad'; payment_reference: string }) =>
      this.request<{ id: number; loan_id: number; amount: string; outstanding_amount: string; journal_reference: string }>(
        '/library.php?action=fine_pay', { method: 'POST', body: JSON.stringify(data) },
      ),
    getStudents: (branchId?: string | number) => this.request<Array<{ id: number; name: string; email: string; branch_id: number | null }>>(
      `/library.php?action=students${branchId ? `&branch_id=${encodeURIComponent(String(branchId))}` : ''}`,
    ),
    getBooks: (branchId?: string | number, query = '') => this.request<Array<Record<string, unknown>>>(
      `/library.php?action=list${branchId ? `&branch_id=${encodeURIComponent(String(branchId))}` : ''}${query ? `&q=${encodeURIComponent(query)}` : ''}`,
    ),
    getLoans: (branchId?: string | number, status?: 'issued' | 'returned' | 'lost') => this.request<Array<Record<string, unknown>>>(
      `/library.php?action=loans${branchId ? `&branch_id=${encodeURIComponent(String(branchId))}` : ''}${status ? `&status=${status}` : ''}`,
    ),
    createBook: (data: { branch_id: number; title: string; author?: string; isbn?: string; category?: string; publisher?: string; publication_year?: number | null; shelf_location?: string; total_copies: number; daily_fine?: number }) =>
      this.request<{ id: number }>('/library.php?action=create', { method: 'POST', body: JSON.stringify(data) }),
    issueBook: (data: { book_id: number; student_user_id: number; due_date: string; remarks?: string }) =>
      this.request<{ id: number }>('/library.php?action=issue', { method: 'POST', body: JSON.stringify(data) }),
    returnBook: (loanId: number) =>
      this.request<{ id: number; fine_amount: number; late_days: number }>('/library.php?action=return', { method: 'POST', body: JSON.stringify({ loan_id: loanId }) }),
    archiveBook: (bookId: number) =>
      this.request<{ id: number }>('/library.php?action=archive', { method: 'POST', body: JSON.stringify({ book_id: bookId }) }),
  };

  public media = {
    list: (contentId?: string | number) => this.request<Array<{ id: number; content_item_id: number; original_filename: string; mime_type: string; size_bytes: number; sha256: string; uploaded_at: string }>>(
      `/media.php?action=list${contentId ? `&content_id=${encodeURIComponent(String(contentId))}` : ''}`,
    ),
    upload: (contentItemId: string | number, file: File) => {
      const body = new FormData();
      body.append('content_item_id', String(contentItemId));
      body.append('file', file);
      return this.request<{ id: number; content_item_id: number; filename: string; mime_type: string; size_bytes: number; download_url: string }>(
        '/media.php?action=upload', { method: 'POST', body },
      );
    },
    delete: (id: number) => this.request<{ id: number; purge_after: string }>('/media.php?action=delete', {
      method: 'POST', body: JSON.stringify({ id }),
    }),
    trash: () => this.request<Array<{ id: number; content_item_id: number; original_filename: string; mime_type: string; size_bytes: number; sha256: string; deleted_at: string; purge_after: string; can_purge: number }>>('/media.php?action=trash'),
    restore: (id: number) => this.request<{ id: number }>('/media.php?action=restore', { method: 'POST', body: JSON.stringify({ id }) }),
    purge: (id: number) => this.request<{ id: number }>('/media.php?action=purge', { method: 'POST', body: JSON.stringify({ id }) }),
  };

  public enrollments = {
    getMine: () => this.request<unknown[]>('/enrollments.php?action=my'),
    getAll: (status = '', limit = 100) => this.request<unknown[]>(`/enrollments.php?action=list&limit=${encodeURIComponent(String(limit))}${status ? `&status=${encodeURIComponent(status)}` : ''}`),
    create: (data: { user_id: number; course_id: number; status?: 'pending' | 'active' | 'completed' | 'cancelled'; branch_id?: number | null; notes?: string }) =>
      this.request<{ id: number }>('/enrollments.php?action=create', { method: 'POST', body: JSON.stringify(data) }),
    update: (id: number, data: { status?: 'pending' | 'active' | 'completed' | 'cancelled'; starts_at?: string | null; ends_at?: string | null; notes?: string }) =>
      this.request<{ id: number }>(`/enrollments.php?action=update&id=${encodeURIComponent(String(id))}`, { method: 'POST', body: JSON.stringify(data) }),
  };

  public payments = {
    submit: (paymentData: { course_id: number; amount: number; method: 'bKash' | 'Nagad' | 'Bank Transfer'; trx_id: string; sender_phone?: string }) =>
      this.request<{ id: number; remaining_amount: string }>('/payments.php?action=submit', { method: 'POST', body: JSON.stringify(paymentData) }),
    verify: (paymentId: string | number) => this.request<{ ledger_reference: string }>(`/payments.php?action=verify&id=${encodeURIComponent(paymentId)}`, { method: 'POST' }),
    reject: (paymentId: string | number, reason: string) => this.request(`/payments.php?action=reject&id=${encodeURIComponent(paymentId)}`, { method: 'POST', body: JSON.stringify({ reason }) }),
    refund: (paymentId: string | number, data: { amount: string; refund_reference: string; reason: string }) =>
      this.request<{ refund_id: number; ledger_reference: string; payment_status: string; refunded_total: string; remaining_refundable: string; net_course_paid: string; course_access_cancelled: boolean }>(`/payments.php?action=refund&id=${encodeURIComponent(paymentId)}`, { method: 'POST', body: JSON.stringify(data) }),
    getAll: (status = '') => this.request<unknown[]>(`/payments.php?action=list${status ? `&status=${encodeURIComponent(status)}` : ''}`),
    getMine: () => this.request<unknown[]>('/payments.php?action=my'),
    getLedger: () => this.request<unknown[]>('/payments.php?action=ledger'),
    reconcile: () => this.request<{ balanced: boolean; discrepancies: Array<Record<string, unknown>> }>('/payments.php?action=reconcile'),
  };

  public storefront = {
    getEbookFiles: () => this.request<Array<{ ebook_id: string; original_filename: string; size_bytes: number; uploaded_at: string }>>('/storefront.php?action=ebook_files'),
    uploadEbookFile: (ebookId: string, file: File) => {
      const form = new FormData();
      form.append('ebook_id', ebookId);
      form.append('pdf', file);
      return this.request<{ ebook_id: string; original_filename: string; size_bytes: number; sha256: string }>('/storefront.php?action=ebook_upload', { method: 'POST', body: form });
    },
    getEbookDownloadUrl: (ebookId: string) => `${API_BASE}/storefront.php?action=ebook_download&id=${encodeURIComponent(ebookId)}`,

    getMyEbooks: () => this.request<{ ebook_ids: string[]; available_file_ids: string[]; orders: Array<Record<string, unknown>> }>('/storefront.php?action=my_ebooks'),
    submitEbookPayment: (data: { ebook_id: string; payment_method: 'bKash' | 'Nagad'; trx_id: string; sender_phone: string }) =>
      this.request<{ id: number; status: 'pending'; amount: string }>('/storefront.php?action=ebook_submit', { method: 'POST', body: JSON.stringify(data) }),
    getEbookOrders: (status = '') => this.request<Array<Record<string, unknown>>>(`/storefront.php?action=ebook_orders${status ? `&status=${encodeURIComponent(status)}` : ''}`),
    reviewEbookOrder: (id: string | number, action: 'ebook_verify' | 'ebook_reject', reason = '') =>
      this.request<{ id: number; status: string }>(`/storefront.php?action=${action}&id=${encodeURIComponent(String(id))}`, { method: 'POST', body: JSON.stringify({ reason }) }),
    submitProductOrder: (data: { product_id: string; quantity: number; customer_name: string; customer_phone: string; delivery_address: string }) =>
      this.request<{ id: number; status: 'pending'; total_amount: string }>('/storefront.php?action=product_submit', { method: 'POST', body: JSON.stringify(data) }),
    getProductOrders: (status = '') => this.request<Array<Record<string, unknown>>>(`/storefront.php?action=product_orders${status ? `&status=${encodeURIComponent(status)}` : ''}`),
    updateProductOrder: (id: string | number, status: string) =>
      this.request<{ id: number; status: string }>(`/storefront.php?action=product_update&id=${encodeURIComponent(String(id))}`, { method: 'POST', body: JSON.stringify({ status }) }),
  };

  public notifications = {
    getAll: (limit = 50) => this.request<{ items: Array<Record<string, unknown>>; unread_count: number }>(`/notifications.php?action=list&limit=${encodeURIComponent(String(limit))}`),
    markRead: (id: string | number) => this.request<{ id: number; is_read: boolean }>(`/notifications.php?action=mark_read&id=${encodeURIComponent(String(id))}`, { method: 'POST' }),
    markAllRead: () => this.request<{ updated: number }>('/notifications.php?action=mark_all_read', { method: 'POST' }),
    delete: (id: string | number) => this.request<{ id: number }>(`/notifications.php?action=delete&id=${encodeURIComponent(String(id))}`, { method: 'POST' }),
    broadcast: (data: { title: string; title_bn?: string; message: string; message_bn?: string; type: 'info'|'success'|'warning'|'alert'; target_role: string; action_url?: string }) =>
      this.request<{ recipient_count: number; target_role: string }>('/notifications.php?action=broadcast', { method: 'POST', body: JSON.stringify(data) }),
  };

  public marksheets = {
    getAll: () => this.request<Array<Record<string, unknown>>>('/marksheets.php?action=list'),
    getEligibleAttempts: () => this.request<Array<Record<string, unknown>>>('/marksheets.php?action=eligible_attempts'),
    issue: (attemptId: number) => this.request<{ id: number; marksheet_number: string; grade: string }>('/marksheets.php?action=issue', {
      method: 'POST', body: JSON.stringify({ attempt_id: attemptId }),
    }),
  };

  // Persistent question-bank endpoints; management is protected by the server-side permission check.
  public questionBank = {
    getAll: () => this.request<Record<string, unknown>[]>('/question_bank.php?action=list'),
    getById: (id: string | number) => this.request<Record<string, unknown>>(`/question_bank.php?action=detail&id=${encodeURIComponent(id)}`),
    create: (data: Record<string, unknown>) => this.request<{ id: number }>('/question_bank.php?action=create', { method: 'POST', body: JSON.stringify(data) }),
    update: (id: string | number, data: Record<string, unknown>) => this.request<{ id: number }>(`/question_bank.php?action=update&id=${encodeURIComponent(id)}`, { method: 'POST', body: JSON.stringify(data) }),
    delete: (id: string | number) => this.request<{ id: number }>(`/question_bank.php?action=delete&id=${encodeURIComponent(id)}`, { method: 'POST' }),
    addToExam: (examId: string | number, questionBankIds: number[]) =>
      this.request<{ exam_id: number; imported_count: number; total_marks: number }>(`/question_bank.php?action=add_to_exam&exam_id=${encodeURIComponent(examId)}`, { method: 'POST', body: JSON.stringify({ question_bank_ids: questionBankIds }) }),
  };

  public attendance = {
    getRoster: (courseId: string | number, date: string) =>
      this.request<Array<{ id: number; name: string; email: string; branch_id: number | null; status: 'present' | 'absent' | 'late' | 'excused' | null; remarks: string | null }>>(
        `/attendance.php?action=roster&course_id=${encodeURIComponent(String(courseId))}&date=${encodeURIComponent(date)}`
      ),
    save: (courseId: string | number, date: string, records: Array<{ user_id: number; status: 'present' | 'absent' | 'late' | 'excused'; remarks: string }>) =>
      this.request<{ course_id: number; attendance_date: string; saved_count: number }>('/attendance.php?action=save', {
        method: 'POST', body: JSON.stringify({ course_id: Number(courseId), attendance_date: date, records }),
      }),
    getMy: () => this.request<Array<{ id: number; course_id: number; course_title: string; attendance_date: string; status: 'present' | 'absent' | 'late' | 'excused'; remarks: string | null }>>('/attendance.php?action=my'),
    getAll: (courseId: string | number, date: string) =>
      this.request<Array<Record<string, unknown>>>(`/attendance.php?action=list&course_id=${encodeURIComponent(String(courseId))}&date=${encodeURIComponent(date)}`),
  };


  public branches = {
    getAll: () => this.request<Array<Record<string, unknown>>>('/branches.php?action=list'),
    create: (data: { code: string; name: string; city?: string; address?: string; phone?: string }) =>
      this.request<{ id: number }>('/branches.php?action=create', { method: 'POST', body: JSON.stringify(data) }),
    update: (id: number, data: { code: string; name: string; city?: string; address?: string; phone?: string }) =>
      this.request<{ id: number }>(`/branches.php?action=update&id=${encodeURIComponent(String(id))}`, { method: 'POST', body: JSON.stringify(data) }),
    deactivate: (id: number) =>
      this.request<{ id: number }>(`/branches.php?action=deactivate&id=${encodeURIComponent(String(id))}`, { method: 'POST' }),
  };

  public batches = {
    getAll: () => this.request<Array<Record<string, unknown>>>('/batches.php?action=list'),
    create: (data: { course_id: number; branch_id?: number | null; instructor_id: number; name: string; batch_code: string; schedule?: string; room_number?: string; max_capacity: number; start_date: string; end_date: string; status: 'upcoming' | 'ongoing' | 'completed' }) =>
      this.request<{ id: number }>('/batches.php?action=create', { method: 'POST', body: JSON.stringify(data) }),
    deactivate: (id: number) =>
      this.request<{ id: number }>(`/batches.php?action=deactivate&id=${encodeURIComponent(String(id))}`, { method: 'POST' }),
    assignStudent: (batch_id: number, student_user_id: number) =>
      this.request('/batches.php?action=student_assign', { method: 'POST', body: JSON.stringify({ batch_id, student_user_id }) }),
    withdrawStudent: (batch_id: number, student_user_id: number) =>
      this.request('/batches.php?action=student_withdraw', { method: 'POST', body: JSON.stringify({ batch_id, student_user_id }) }),
    getRoutines: () => this.request<Array<Record<string, unknown>>>('/batches.php?action=routines'),
    createRoutine: (data: { batch_id: number; instructor_id?: number; day_of_week: string; start_time: string; end_time: string; subject: string; room_number: string }) =>
      this.request<{ id: number }>('/batches.php?action=routine_create', { method: 'POST', body: JSON.stringify(data) }),
    deleteRoutine: (id: number) =>
      this.request<{ id: number }>(`/batches.php?action=routine_delete&id=${encodeURIComponent(String(id))}`, { method: 'POST' }),
  };

  public liveClasses = {
    getAll: () => this.request<Array<Record<string, unknown>>>('/live_classes.php?action=list'),
    create: (data: { course_id: number; instructor_id?: number; title: string; date: string; time: string; meeting_platform: 'google_meet' | 'zoom' | 'teams'; meeting_url: string }) =>
      this.request<{ id: number }>('/live_classes.php?action=create', { method: 'POST', body: JSON.stringify(data) }),
    update: (id: number, data: { status?: 'upcoming' | 'live' | 'completed'; recording_url?: string }) =>
      this.request<{ id: number; status: string }>(`/live_classes.php?action=update&id=${encodeURIComponent(String(id))}`, { method: 'POST', body: JSON.stringify(data) }),
    cancel: (id: number) =>
      this.request<{ id: number; status: 'cancelled' }>(`/live_classes.php?action=cancel&id=${encodeURIComponent(String(id))}`, { method: 'POST' }),
  };

  public admissions = {
    submit: (application: {
      name: string; email: string; phone: string; course_id: number;
      branch_label?: string; preferred_contact: 'phone' | 'email'; message?: string; website?: string;
    }) => this.request<{ reference_code: string; status: 'pending' }>('/admissions.php?action=submit', {
      method: 'POST', body: JSON.stringify(application),
    }),
    getAll: (status = '') => this.request<Array<Record<string, unknown>>>(`/admissions.php?action=list${status ? `&status=${encodeURIComponent(status)}` : ''}`),
    update: (id: number, status: 'pending' | 'contacted' | 'rejected', review_notes = '') =>
      this.request<{ id: number; status: string }>(`/admissions.php?action=update&id=${encodeURIComponent(String(id))}`, {
        method: 'POST', body: JSON.stringify({ status, review_notes }),
      }),
  };

  public cms = {
    getSettings: () => this.request<Record<string, unknown>>('/cms.php?action=settings'),
    saveSettings: (settings: Record<string, unknown>) =>
      this.request<Record<string, unknown>>('/cms.php?action=save_settings', { method: 'POST', body: JSON.stringify({ settings }) }),
    getPublicPosts: () => this.request<Array<Record<string, unknown>>>('/cms.php?action=public_posts'),
    getPosts: () => this.request<Array<Record<string, unknown>>>('/cms.php?action=posts'),
    getPost: (slug: string) => this.request<Record<string, unknown>>(`/cms.php?action=post&slug=${encodeURIComponent(slug)}`),
    createPost: (post: Record<string, unknown>) => this.request<{ id: number }>('/cms.php?action=create_post', { method: 'POST', body: JSON.stringify(post) }),
    updatePost: (id: string | number, post: Record<string, unknown>) =>
      this.request<{ id: number }>(`/cms.php?action=update_post&id=${encodeURIComponent(String(id))}`, { method: 'POST', body: JSON.stringify(post) }),
    deletePost: (id: string | number) => this.request<{ id: number }>(`/cms.php?action=delete_post&id=${encodeURIComponent(String(id))}`, { method: 'POST' }),
    getPublicTestimonials: () => this.request<Array<Record<string, unknown>>>('/cms.php?action=public_testimonials'),
    getTestimonials: () => this.request<Array<Record<string, unknown>>>('/cms.php?action=testimonials'),
    createTestimonial: (item: Record<string, unknown>) => this.request<{ id: number }>('/cms.php?action=create_testimonial', { method: 'POST', body: JSON.stringify(item) }),
    updateTestimonial: (id: string | number, item: Record<string, unknown>) =>
      this.request<{ id: number }>(`/cms.php?action=update_testimonial&id=${encodeURIComponent(String(id))}`, { method: 'POST', body: JSON.stringify(item) }),
    deleteTestimonial: (id: string | number) => this.request<{ id: number }>(`/cms.php?action=delete_testimonial&id=${encodeURIComponent(String(id))}`, { method: 'POST' }),
  };

  public exams = {
    getAll: () => this.request<Record<string, unknown>[]>('/exams.php?action=list'),
    getById: (id: string | number) => this.request<Record<string, unknown>>(`/exams.php?action=detail&id=${encodeURIComponent(String(id))}`),
    create: (data: Record<string, unknown>) => this.request<{ id: number }>('/exams.php?action=create', { method: 'POST', body: JSON.stringify(data) }),
    update: (id: string | number, data: Record<string, unknown>) => this.request<{ id: number }>(`/exams.php?action=update&id=${encodeURIComponent(String(id))}`, { method: 'POST', body: JSON.stringify(data) }),
    saveQuestions: (id: string | number, questions: Array<Record<string, unknown>>) =>
      this.request<{ id: number; question_count: number; total_marks: number }>(`/exams.php?action=save_questions&id=${encodeURIComponent(String(id))}`, { method: 'POST', body: JSON.stringify({ questions }) }),
    start: (id: string | number) => this.request<{ attempt: { id: number; started_at: string; expires_at: string; remaining_seconds: number; answers: Record<string, number> } }>(`/exams.php?action=start&id=${encodeURIComponent(id)}`, { method: 'POST' }),
    saveAnswers: (attemptId: string | number, answers: Record<string, number>) =>
      this.request<{ attempt_id: number; saved_answers: number }>(`/exams.php?action=save_answers&id=${encodeURIComponent(attemptId)}`, { method: 'POST', body: JSON.stringify({ answers }) }),
    submitAttempt: (attemptId: string | number, answers: Record<string, number>) =>
      this.request<Record<string, unknown>>(`/exams.php?action=submit&id=${encodeURIComponent(attemptId)}`, { method: 'POST', body: JSON.stringify({ answers }) }),
    getAttempts: (examId?: string | number) => this.request<Record<string, unknown>[]>(`/exams.php?action=attempts${examId ? `&exam_id=${encodeURIComponent(examId)}` : ''}`),
    delete: (id: string | number) => this.request<{ id: number }>(`/exams.php?action=delete&id=${encodeURIComponent(id)}`, { method: 'POST' }),
  };

  // Persistent certificate issuance, student wallet and public verification API.
  public certificates = {
    getAll: (query = '') => this.request<CertificateApiRecord[]>(`/certificates.php?action=list&q=${encodeURIComponent(query)}`),
    getStudents: (query = '') => this.request<Array<{ id: number; name: string; email: string }>>(`/certificates.php?action=students&q=${encodeURIComponent(query)}`),
    getMine: () => this.request<CertificateApiRecord[]>('/certificates.php?action=my'),
    issue: (studentUserId: number, courseId: number, grade: string) =>
      this.request<{ id: number; certificate_number: string }>('/certificates.php?action=issue', {
        method: 'POST', body: JSON.stringify({ student_user_id: studentUserId, course_id: courseId, grade }),
      }),
    revoke: (id: number, reason: string) =>
      this.request<{ id: number; status: 'revoked' }>(`/certificates.php?action=revoke&id=${encodeURIComponent(String(id))}`, {
        method: 'POST', body: JSON.stringify({ reason }),
      }),
    verify: (certificateNumber: string) =>
      this.request<CertificateVerification>(`/certificates.php?action=verify&cert_no=${encodeURIComponent(certificateNumber.trim().toUpperCase())}`),
  };

}

export const apiClient = new ApiService();
