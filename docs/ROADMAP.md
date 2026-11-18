# ETEC — Delivery Roadmap

This is a large, team-sized platform. It is delivered in phases; each phase
builds on a verified foundation.

## ✅ Phase 1 — Foundation (current)
- PHP 8.3 toolchain + Laravel 12 scaffold
- Packages: Sanctum, spatie/laravel-permission, spatie/laravel-activitylog
- Full normalized schema (30+ migrations) with FKs & indexes
- 28 Eloquent models with relationships, casts, soft deletes
- Roles (6) + granular permissions; super-admin gate bypass
- Settings + commission configuration (DB-driven)
- Seeders: roles/permissions, settings, demo users, demo course, CMS
- Docs: README, ER diagram, roadmap

## Phase 2 — Auth & theming
- Registration, login, email verification, password reset, 2FA (optional)
- Profile management + change password
- Light/dark theme switcher persisted to `users.theme`
- Role-based redirect to dashboards

## Phase 3 — Public website
- Home, About, Courses, Course details, Consultant program, Testimonials,
  Contact, FAQ, Blog — Blade + Tailwind + Alpine, mobile-first, PWA-ready

## Phase 4 — LMS core
- Course/module/lesson delivery, video + PDF materials, progress tracking
- Assignments, exams (MCQ engine), results, certificate generation (queued)

## Phase 5 — Payments & enrollment
- Purchase flow, receipt upload, **manual** accountant verification
- Enrollment activation on verified payment (DB transaction)

## Phase 6 — Consultant & commission engine
- Referral links & registration, auto group creation/assignment
- Tiered commission calculation (queued), group open→completed→closed,
  overflow → company revenue, earnings dashboard

## Phase 7 — Role dashboards & reports
- Admin, Accountant, Lecturer, Consultant, Student dashboards
- Reports (students, consultants, courses, revenue, commissions, payments,
  groups) with **PDF + Excel** export

## Phase 8 — Notifications & real-time
- Email + in-app notifications for payments, assignments, exams, commissions

## Phase 9 — Infra, performance & scale
- Redis cache/queue/session, Horizon + Telescope, Docker (Nginx/PHP-FPM/
  MySQL/Redis/Supervisor), S3 private storage + signed URLs, CDN
- Load tests (1k/5k/10k concurrent) + optimization report

## Phase 10 — Hardening & deployment
- Security review, rate limiting, backups, API docs, deployment guide
