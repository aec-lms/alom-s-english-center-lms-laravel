# Laravel Course Enrollment Workflow

Course enrollment is implemented inside the Laravel course-management plugin. There are no legacy standalone PHP enrollment endpoints.

## Student endpoints

- POST /courses/{course}/enroll — authenticated student requests enrollment in a published course.
- GET /my-learning/enrollments — authenticated student sees only their own enrollment records, linked course and invoice.
- GET /api/mobile/me — authenticated mobile user receives their own enrollments and course summaries.

## Admin endpoints

- GET /admin/enrollments — requires the enrollments.manage permission; branch managers see only their own branch.
- PATCH /admin/enrollments/{enrollment}/status — requires enrollments.manage and enforces branch scope.

## Payment gating

- Free published courses activate immediately after a valid student profile and branch match.
- Paid courses create a pending enrollment and a fee invoice linked by invoice_id.
- A paid enrollment cannot be manually activated while its invoice is unpaid or partially paid.
- PaymentSettlementService activates the pending enrollment only after a verified payment transaction settles the linked invoice in full.
- If a refund reduces the invoice below its full amount, the LMS moves the enrollment back to pending until the balance is resolved.
- Duplicate user/course enrollments are prevented by the unique database constraint; an existing pending or active enrollment is not duplicated.

## Security

- Students can request enrollment only for themselves and only when a Student profile exists.
- A student cannot enroll in a course assigned to another branch.
- Branch managers can list and update only enrollments in their assigned branch.
- Course and invoice ownership is checked server-side; client-supplied prices, payment statuses or scores are not trusted.
- This workflow does not treat a browser redirect as proof of payment. Payment activation comes from the verified gateway settlement service.

## Operational requirements

- Course Management must be enabled.
- Paid course enrollment also requires Fees & Invoicing and Payment Management.
- To accept online payment, enable and configure a standalone payment gateway plugin, register its provider callbacks and complete sandbox verification.
- The Universal Payment plugin only orchestrates independently configured gateways; it does not store merchant credentials.
