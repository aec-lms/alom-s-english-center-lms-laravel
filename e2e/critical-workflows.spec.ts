import { expect, test, type Page } from '@playwright/test';
import { readFileSync } from 'node:fs';

type Fixtures = {
  admin: { email: string; password: string; id: number };
  student: { email: string; password: string; id: number };
  outsider: { email: string; password: string; id: number };
  branch_manager: { email: string; password: string; id: number };
  branches: { primary: number; other: number };
  facility: { hostel_allocation: number; other_hostel_allocation: number; transport_assignment: number; other_transport_assignment: number };
  courses: { active: number; completed: number; enrollment: number; payment: number };
  enrollment: { pending: number };
  exam: { id: number; question_id: number };
  cms_post_id: number;
  ebook_id: string;
};

const fixturePath = process.env.E2E_FIXTURE_FILE;
if (!fixturePath) throw new Error('E2E_FIXTURE_FILE must point to the disposable MySQL fixture manifest.');
const fixtures = JSON.parse(readFileSync(fixturePath, 'utf8')) as Fixtures;

async function signIn(page: Page, email: string, password: string) {
  await page.goto('/');
  await page.getByRole('button', { name: 'Login' }).click();
  await page.getByLabel('Email address').fill(email);
  await page.getByLabel('Password').fill(password);
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page.getByRole('button', { name: /logout/i })).toBeVisible();
}

async function csrfToken(page: Page): Promise<string> {
  const response = await page.request.get('/api/auth.php?action=me');
  expect(response.status()).toBe(200);
  const payload = await response.json();
  expect(payload.success).toBe(true);
  expect(payload.data.user).toBeTruthy();
  return payload.data.csrf_token as string;
}

async function apiJson(page: Page, path: string, method = 'GET', data?: unknown, csrf?: string) {
  const response = await page.request.fetch(path, {
    method,
    data,
    headers: csrf ? { 'X-CSRF-Token': csrf } : undefined,
  });
  return { response, payload: await response.json() };
}

test.describe('API-backed LMS critical journeys', () => {
  test('public course discovery, login, session restoration, logout, CSRF and role-based access', async ({ page }) => {
    await page.goto('/');
    await page.getByRole('button', { name: /^Courses$/ }).click();
    await expect(page.getByText('E2E Active Course', { exact: true })).toBeVisible();

    await signIn(page, fixtures.admin.email, fixtures.admin.password);
    await page.reload();
    const session = await page.request.get('/api/auth.php?action=me');
    expect(session.status()).toBe(200);
    expect((await session.json()).data.user.role).toBe('admin');
    await expect(page.getByRole('button', { name: /Back to Public Website/ })).toBeVisible();
    await expect(page.getByRole('button', { name: /logout/i })).toBeVisible();

    const csrf = await csrfToken(page);
    const missingCsrf = await apiJson(page, '/api/notifications.php?action=broadcast', 'POST', {
      title: 'E2E CSRF check',
      message: 'This mutation must be rejected without a token.',
      type: 'info',
      target_role: 'student',
    });
    expect(missingCsrf.response.status()).toBe(419);

    await page.getByRole('button', { name: /logout/i }).click();
    await expect(page.getByRole('button', { name: 'Login' })).toBeVisible();
    const loggedOut = await page.request.get('/api/auth.php?action=me');
    expect(loggedOut.status()).toBe(401);
    expect(csrf).toHaveLength(64);

    await signIn(page, fixtures.student.email, fixtures.student.password);
    const deniedUsers = await page.request.get('/api/users.php?action=list');
    expect(deniedUsers.status()).toBe(403);
    const deniedEnrollmentMutation = await apiJson(page, '/api/enrollments.php?action=create', 'POST', {
      user_id: fixtures.student.id,
      course_id: fixtures.courses.enrollment,
      status: 'active',
    });
    expect(deniedEnrollmentMutation.response.status()).toBe(403);
  });

  test('admin generates branch-scoped hostel and transport invoices, records payments and students see balances', async ({ browser }) => {
    const admin = await browser.newPage();
    await signIn(admin, fixtures.admin.email, fixtures.admin.password);
    const adminCsrf = await csrfToken(admin);
    const month = new Date().toISOString().slice(0, 7);

    for (const branchId of [fixtures.branches.primary, fixtures.branches.other]) {
      for (const serviceType of ['hostel', 'transport'] as const) {
        const generated = await apiJson(admin, '/api/facility-fees.php?action=generate', 'POST', {
          service_type: serviceType, branch_id: branchId, billing_month: month,
        }, adminCsrf);
        expect(generated.response.status()).toBe(201);
        expect(generated.payload.data.created_count).toBe(1);
      }
    }

    const duplicateGeneration = await apiJson(admin, '/api/facility-fees.php?action=generate', 'POST', {
      service_type: 'hostel', branch_id: fixtures.branches.primary, billing_month: month,
    }, adminCsrf);
    expect(duplicateGeneration.response.status()).toBe(201);
    expect(duplicateGeneration.payload.data.created_count).toBe(0);

    const hostelRows = await admin.request.get(`/api/facility-fees.php?action=list&branch_id=${fixtures.branches.primary}&service_type=hostel`);
    expect(hostelRows.status()).toBe(200);
    const hostelInvoice = ((await hostelRows.json()).data as Array<{ id:number; amount:string; status:string; outstanding_amount:string }>)[0];
    expect(hostelInvoice).toMatchObject({ status: 'unpaid', amount: '1200.00' });

    const firstHostelPayment = await apiJson(admin, '/api/facility-fees.php?action=pay', 'POST', {
      invoice_id: hostelInvoice.id, amount: '300.00', payment_method: 'cash', payment_reference: `E2E-HOSTEL-PART-${month}`,
    }, adminCsrf);
    expect(firstHostelPayment.response.status()).toBe(201);
    expect(firstHostelPayment.payload.data.status).toBe('partial');
    expect(firstHostelPayment.payload.data.outstanding_amount).toBe('900.00');

    const secondHostelPayment = await apiJson(admin, '/api/facility-fees.php?action=pay', 'POST', {
      invoice_id: hostelInvoice.id, amount: '900.00', payment_method: 'bank_transfer', payment_reference: `E2E-HOSTEL-FULL-${month}`,
    }, adminCsrf);
    expect(secondHostelPayment.response.status()).toBe(201);
    expect(secondHostelPayment.payload.data.status).toBe('paid');

    const transportRows = await admin.request.get(`/api/facility-fees.php?action=list&branch_id=${fixtures.branches.primary}&service_type=transport`);
    const transportInvoice = ((await transportRows.json()).data as Array<{ id:number; amount:string; status:string }>)[0];
    expect(transportInvoice).toMatchObject({ status: 'unpaid', amount: '500.00' });
    const duplicateReference = await apiJson(admin, '/api/facility-fees.php?action=pay', 'POST', {
      invoice_id: transportInvoice.id, amount: '500.00', payment_method: 'bKash', payment_reference: `E2E-HOSTEL-FULL-${month}`,
    }, adminCsrf);
    expect(duplicateReference.response.status()).toBe(409);
    const transportPayment = await apiJson(admin, '/api/facility-fees.php?action=pay', 'POST', {
      invoice_id: transportInvoice.id, amount: '500.00', payment_method: 'bKash', payment_reference: `E2E-TRANSPORT-FULL-${month}`,
    }, adminCsrf);
    expect(transportPayment.response.status()).toBe(201);

    const branchManager = await browser.newPage();
    await signIn(branchManager, fixtures.branch_manager.email, fixtures.branch_manager.password);
    const branchRows = await branchManager.request.get('/api/facility-fees.php?action=list');
    expect(branchRows.status()).toBe(200);
    const scopedRows = (await branchRows.json()).data as Array<{ branch_id:number; service_type:string }>;
    expect(scopedRows.length).toBe(2);
    expect(scopedRows.every(row => row.branch_id === fixtures.branches.primary)).toBe(true);

    const student = await browser.newPage();
    await signIn(student, fixtures.student.email, fixtures.student.password);
    const myInvoices = await student.request.get('/api/facility-fees.php?action=my');
    expect(myInvoices.status()).toBe(200);
    const studentRows = (await myInvoices.json()).data as Array<{ service_type:string; status:string; branch_id:number }>;
    expect(studentRows.length).toBe(2);
    expect(studentRows.every(row => row.branch_id === fixtures.branches.primary && row.status === 'paid')).toBe(true);

    const payments = await admin.request.get(`/api/facility-fees.php?action=payments&branch_id=${fixtures.branches.primary}`);
    expect(payments.status()).toBe(200);
    const paymentRows = (await payments.json()).data as Array<{ journal_reference:string }>;
    expect(paymentRows.length).toBe(3);
    expect(paymentRows.every(row => Boolean(row.journal_reference))).toBe(true);
  });

  test('admin reviews enrollment and verifies a manual payment to activate course access', async ({ browser }) => {
    const admin = await browser.newPage();
    await signIn(admin, fixtures.admin.email, fixtures.admin.password);
    const adminCsrf = await csrfToken(admin);

    const pendingList = await admin.request.get('/api/enrollments.php?action=list&status=pending');
    expect(pendingList.status()).toBe(200);
    const pendingRows = (await pendingList.json()).data as Array<{ id: number; status: string }>;
    expect(pendingRows.some(row => row.id === fixtures.enrollment.pending && row.status === 'pending')).toBe(true);

    const activate = await apiJson(
      admin,
      `/api/enrollments.php?action=update&id=${fixtures.enrollment.pending}`,
      'POST',
      { status: 'active' },
      adminCsrf,
    );
    expect(activate.response.status()).toBe(200);

    const student = await browser.newPage();
    await signIn(student, fixtures.student.email, fixtures.student.password);
    const ownEnrollments = await student.request.get('/api/enrollments.php?action=my');
    expect(ownEnrollments.status()).toBe(200);
    expect((await ownEnrollments.json()).data.some((row: { id: number; status: string }) =>
      row.id === fixtures.enrollment.pending && row.status === 'active')).toBe(true);

    const payment = await apiJson(student, '/api/payments.php?action=submit', 'POST', {
      course_id: fixtures.courses.payment,
      amount: '1200.00',
      method: 'bKash',
      trx_id: `E2E-PAY-${Date.now()}`,
      sender_phone: '01712345678',
    }, await csrfToken(student));
    expect(payment.response.status()).toBe(201);
    const paymentId = payment.payload.data.id as number;

    const reviewed = await apiJson(
      admin,
      `/api/payments.php?action=verify&id=${paymentId}`,
      'POST',
      {},
      adminCsrf,
    );
    expect(reviewed.response.status()).toBe(200);
    expect(reviewed.payload.data.course_access_activated).toBe(true);

    const refreshedEnrollments = await student.request.get('/api/enrollments.php?action=my');
    const paymentEnrollment = (await refreshedEnrollments.json()).data.find(
      (row: { course_id: number }) => row.course_id === fixtures.courses.payment,
    );
    expect(paymentEnrollment?.status).toBe('active');

    await admin.close();
    await student.close();
  });

  test('student completes a server-timed exam and sees attendance history', async ({ browser }) => {
    const admin = await browser.newPage();
    await signIn(admin, fixtures.admin.email, fixtures.admin.password);
    const adminCsrf = await csrfToken(admin);
    const attendanceDate = new Date().toISOString().slice(0, 10);

    const savedAttendance = await apiJson(admin, '/api/attendance.php?action=save', 'POST', {
      course_id: fixtures.courses.active,
      attendance_date: attendanceDate,
      records: [{ user_id: fixtures.student.id, status: 'present', remarks: 'Playwright attendance fixture' }],
    }, adminCsrf);
    expect(savedAttendance.response.status()).toBe(200);
    expect(savedAttendance.payload.data.saved_count).toBe(1);

    const student = await browser.newPage();
    await signIn(student, fixtures.student.email, fixtures.student.password);
    const history = await student.request.get('/api/attendance.php?action=my');
    expect(history.status()).toBe(200);
    expect((await history.json()).data.some((row: { course_id: number; status: string }) =>
      row.course_id === fixtures.courses.active && row.status === 'present')).toBe(true);

    const studentCsrf = await csrfToken(student);
    const start = await apiJson(
      student,
      `/api/exams.php?action=start&id=${fixtures.exam.id}`,
      'POST',
      {},
      studentCsrf,
    );
    expect(start.response.status()).toBe(200);
    const attemptId = start.payload.data.attempt.id as number;
    const resumed = await apiJson(
      student,
      `/api/exams.php?action=start&id=${fixtures.exam.id}`,
      'POST',
      {},
      studentCsrf,
    );
    expect(resumed.response.status()).toBe(200);
    expect(resumed.payload.data.attempt.id).toBe(attemptId);

    const deniedAttendanceWrite = await apiJson(student, '/api/attendance.php?action=save', 'POST', {
      course_id: fixtures.courses.active,
      attendance_date: attendanceDate,
      records: [{ user_id: fixtures.student.id, status: 'absent' }],
    }, studentCsrf);
    expect(deniedAttendanceWrite.response.status()).toBe(403);

    const answers = { [String(fixtures.exam.question_id)]: 1 };
    const save = await apiJson(
      student,
      `/api/exams.php?action=save_answers&id=${attemptId}`,
      'POST',
      { answers },
      studentCsrf,
    );
    expect(save.response.status()).toBe(200);
    expect(save.payload.data.saved_answers).toBe(1);

    const submit = await apiJson(
      student,
      `/api/exams.php?action=submit&id=${attemptId}`,
      'POST',
      { answers },
      studentCsrf,
    );
    expect(submit.response.status()).toBe(200);
    expect(submit.payload.data.score).toBe(1);
    expect(submit.payload.data.total_marks).toBe(1);
    expect(submit.payload.data.passed).toBe(true);

    const outsider = await browser.newPage();
    await signIn(outsider, fixtures.outsider.email, fixtures.outsider.password);
    const forbiddenAttempt = await apiJson(
      outsider,
      `/api/exams.php?action=start&id=${fixtures.exam.id}`,
      'POST',
      {},
      await csrfToken(outsider),
    );
    expect(forbiddenAttempt.response.status()).toBe(404);

    await admin.close();
    await student.close();
    await outsider.close();
  });

  test('Admin restores private course media and enrolled students retain authorized delivery', async ({ browser }) => {
    const admin = await browser.newPage();
    await signIn(admin, fixtures.admin.email, fixtures.admin.password);
    const adminCsrf = await csrfToken(admin);

    const createdContent = await apiJson(admin, '/api/content.php?action=create', 'POST', {
      title: 'E2E Private Media Retention Lesson',
      content_type: 'file',
      body: 'Private media retention browser journey.',
      media_url: '',
      duration_minutes: 0,
      status: 'published',
    }, adminCsrf);
    expect(createdContent.response.status()).toBe(201);
    const contentId = createdContent.payload.data.id as number;

    const attached = await apiJson(admin, '/api/content.php?action=attach', 'POST', {
      course_id: fixtures.courses.active,
      content_item_id: contentId,
      sort_order: 0,
      is_preview: false,
    }, adminCsrf);
    expect(attached.response.status()).toBe(200);

    const pdf = Buffer.from('%PDF-1.4\\n1 0 obj<</Type/Catalog>>endobj\\n%%EOF');
    const filename = 'e2e-retention-media.pdf';
    const uploaded = await admin.request.post('/api/media.php?action=upload', {
      multipart: {
        content_item_id: String(contentId),
        file: { name: filename, mimeType: 'application/pdf', buffer: pdf },
      },
      headers: { 'X-CSRF-Token': adminCsrf },
    });
    expect(uploaded.status()).toBe(201);
    const assetId = (await uploaded.json()).data.id as number;

    const student = await browser.newPage();
    await signIn(student, fixtures.student.email, fixtures.student.password);
    const initialDownload = await student.request.get(`/api/media.php?action=download&id=${assetId}`);
    expect(initialDownload.status()).toBe(200);
    expect(await initialDownload.body()).toEqual(pdf);

    await admin.getByRole('button', { name: /Courses & Lessons/ }).click();
    await expect(admin.getByRole('heading', { name: 'Private media retention', exact: true })).toBeVisible();
    await expect(admin.getByText(filename, { exact: true })).toBeVisible();
    admin.once('dialog', dialog => dialog.accept());
    await admin.getByRole('button', { name: 'Move to trash' }).click();
    await expect(admin.getByText(filename, { exact: true })).not.toBeVisible();

    await admin.getByRole('button', { name: /Show trash/ }).click();
    await expect(admin.getByText(filename, { exact: true })).toBeVisible();
    await admin.getByRole('button', { name: 'Restore' }).click();
    await expect(admin.getByText('Private media restored.', { exact: true })).toBeVisible();

    const restoredDownload = await student.request.get(`/api/media.php?action=download&id=${assetId}`);
    expect(restoredDownload.status()).toBe(200);
    expect(await restoredDownload.body()).toEqual(pdf);

    await admin.close();
    await student.close();
  });

  test('certificate issue, public verification and revocation; CMS and protected e-book access', async ({ browser }) => {
    const admin = await browser.newPage();
    await signIn(admin, fixtures.admin.email, fixtures.admin.password);
    const adminCsrf = await csrfToken(admin);

    const issued = await apiJson(admin, '/api/certificates.php?action=issue', 'POST', {
      student_user_id: fixtures.student.id,
      course_id: fixtures.courses.completed,
      grade: 'A',
    }, adminCsrf);
    expect(issued.response.status(), JSON.stringify(issued.payload)).toBe(201);
    const certificateId = issued.payload.data.id as number;
    const certificateNumber = issued.payload.data.certificate_number as string;

    const publicVerification = await admin.request.get(
      `/api/certificates.php?action=verify&cert_no=${encodeURIComponent(certificateNumber)}`,
    );
    expect(publicVerification.status()).toBe(200);
    expect((await publicVerification.json()).data.valid).toBe(true);

    const revoked = await apiJson(
      admin,
      `/api/certificates.php?action=revoke&id=${certificateId}`,
      'POST',
      { reason: 'Playwright revocation test' },
      adminCsrf,
    );
    expect(revoked.response.status()).toBe(200);
    const verificationAfterRevocation = await admin.request.get(
      `/api/certificates.php?action=verify&cert_no=${encodeURIComponent(certificateNumber)}`,
    );
    expect((await verificationAfterRevocation.json()).data.valid).toBe(false);

    const publicPosts = await admin.request.get('/api/cms.php?action=public_posts');
    expect(publicPosts.status()).toBe(200);
    expect((await publicPosts.json()).data.some((post: { id: number }) => String(post.id) === String(fixtures.cms_post_id))).toBe(true);

    const pdf = Buffer.from('%PDF-1.4\\n1 0 obj\\n<< /Type /Catalog >>\\nendobj\\ntrailer\\n<< /Root 1 0 R >>\\n%%EOF\\n');
    const upload = await admin.request.post('/api/storefront.php?action=ebook_upload', {
      multipart: {
        ebook_id: fixtures.ebook_id,
        pdf: { name: 'e2e-protected-book.pdf', mimeType: 'application/pdf', buffer: pdf },
      },
      headers: { 'X-CSRF-Token': adminCsrf },
    });
    expect(upload.status()).toBe(201);

    const student = await browser.newPage();
    await signIn(student, fixtures.student.email, fixtures.student.password);
    const deniedCertificateRevoke = await apiJson(
      student,
      `/api/certificates.php?action=revoke&id=${certificateId}`,
      'POST',
      { reason: 'Students cannot revoke certificates.' },
      await csrfToken(student),
    );
    expect(deniedCertificateRevoke.response.status()).toBe(403);

    const deniedDownload = await student.request.get(
      `/api/storefront.php?action=ebook_download&id=${encodeURIComponent(fixtures.ebook_id)}`,
    );
    expect(deniedDownload.status()).toBe(403);

    const order = await apiJson(student, '/api/storefront.php?action=ebook_submit', 'POST', {
      ebook_id: fixtures.ebook_id,
      payment_method: 'bKash',
      trx_id: `E2E-BOOK-${Date.now()}`,
      sender_phone: '01712345678',
    }, await csrfToken(student));
    expect(order.response.status()).toBe(201);

    const verifyOrder = await apiJson(
      admin,
      `/api/storefront.php?action=ebook_verify&id=${order.payload.data.id}`,
      'POST',
      {},
      adminCsrf,
    );
    expect(verifyOrder.response.status()).toBe(200);

    const allowedDownload = await student.request.get(
      `/api/storefront.php?action=ebook_download&id=${encodeURIComponent(fixtures.ebook_id)}`,
    );
    expect(allowedDownload.status()).toBe(200);
    expect(allowedDownload.headers()['content-type']).toContain('application/pdf');
    expect((await allowedDownload.body()).subarray(0, 5).toString()).toBe('%PDF-');

    const deniedOrderManagement = await student.request.get('/api/storefront.php?action=ebook_orders');
    expect(deniedOrderManagement.status()).toBe(403);

    await admin.close();
    await student.close();
  });
});
