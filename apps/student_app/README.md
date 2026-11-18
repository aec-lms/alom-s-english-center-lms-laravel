# Alom Student App

Flutter student client for the Alom's English Center Laravel API.

## First-time setup

From this directory:

```bash
flutter create --platforms=android,ios --project-name aloms_student_app .
flutter pub get
flutter run --dart-define=API_BASE_URL=https://YOUR-DOMAIN/api/v1
```

Replace YOUR-DOMAIN with the actual LMS domain. The app deliberately does not hard-code a production domain.

Current client flows: login/session restore, public course catalogue and details, enrollment-gated lesson lists, enrolled-course summary, notices, student-owned support tickets with threaded replies, logout and QR attendance submission. Support ticket access is scoped to the authenticated student by the server. QR check-in is processed by the LMS attendance controller, authoritative for expiry, replay, roster membership, branch scope and optional GPS requirements.

Wishlist, support tickets, cart/checkout, invoices, lesson progress, assignments, exam attempts, reviews and question-set attempts remain tracked in docs/FINAL-LMS-FEATURE-PARITY-TRACKER.md. Do not enable those screens until matching server APIs and acceptance tests exist.
