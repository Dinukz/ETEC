# ETEC — Education, LMS & Consultant Platform

ETEC is a mobile-first web application that combines a **corporate website**, a
**Learning Management System (LMS)**, and a **referral / commission management
system** into a single Laravel application.

- **Backend:** Laravel 12 · PHP 8.3 · MySQL 8
- **Auth/API:** Laravel Sanctum
- **Roles/Permissions:** spatie/laravel-permission
- **Audit logs:** spatie/laravel-activitylog
- **Frontend (planned):** Blade + Tailwind CSS + Alpine.js (light/dark theme)

> **Status:** Foundation phase complete — database schema, models, roles &
> permissions, settings/commission config, and seeders. Feature modules (auth
> UI, dashboards, LMS, payments, commission engine, reports, notifications) are
> being built in subsequent phases. See [docs/ROADMAP.md](docs/ROADMAP.md).

---

## Roles

| Role | Purpose |
|------|---------|
| `super_admin` | Full system access (bypasses all gates) |
| `admin` | User/course/consultant/CMS management, settings, reports |
| `accountant` | Verify payments, manage & pay commissions, financial reports |
| `lecturer` | Author courses/lessons, assignments, exams, results, Zoom |
| `consultant` | Referrals, view own students/groups/commissions, LMS access |
| `student` | Purchase & consume courses, track progress, certificates |

## Business rules (configurable — stored in DB, not code)

| Setting | Default |
|---------|---------|
| Course price | LKR 28,000 |
| Commission per student | LKR 2,500 |
| Max commission per group | LKR 15,000 |
| Group size | 6 students |
| Tier 1 (first 3 students) | LKR 7,500 |
| Tier 2 (next 3 students) | LKR 7,500 |

When a group of 6 fills, it **closes**; further payments become company revenue.
Values live in the `commission_settings` and `settings` tables and are editable
by admins without code changes.

---

## Requirements

- PHP **8.3+** with extensions: `pdo_mysql, mbstring, openssl, curl, fileinfo, gd, intl, zip`
- Composer 2.x
- MySQL 8 (or MariaDB 10.6+)
- Node 20+ / npm (for the frontend build, later phase)

## Installation (local / XAMPP)

```bash
# 1. Install PHP dependencies
composer install

# 2. Environment
cp .env.example .env
php artisan key:generate

# 3. Configure the database in .env
#    DB_CONNECTION=mysql
#    DB_HOST=127.0.0.1
#    DB_PORT=3308           # this machine's MySQL 8 runs on 3308
#    DB_DATABASE=etec
#    DB_USERNAME=etec_user
#    DB_PASSWORD=********

# 4. Create the schema and seed baseline data
php artisan migrate --seed

# 5. Serve
php artisan serve
```

> On this development machine PHP 8.3 is installed standalone at `C:\php83`.
> Prefix commands with it, e.g. `& C:\php83\php.exe artisan migrate --seed`,
> or put `C:\php83` first on `PATH`.

### Seeded accounts (password: `password`)

| Role | Email |
|------|-------|
| Super Admin | superadmin@etec.lk |
| Admin | admin@etec.lk |
| Accountant | accountant@etec.lk |
| Lecturer | lecturer@etec.lk |
| Consultant | consultant@etec.lk |
| Student | student@etec.lk |

**Change these before any non-local deployment.**

---

## Project structure (foundation)

```
app/
  Concerns/Auditable.php        # reusable activity-log trait
  Models/                       # 28 Eloquent models
  Providers/AppServiceProvider  # super-admin gate, lazy-load guard
database/
  migrations/                   # full normalized schema (30+ tables)
  seeders/                      # roles, settings, users, courses, CMS
docs/
  DATABASE.md                   # ER diagram + table reference
  ROADMAP.md                    # phased delivery plan
```

## Architecture (target)

Service Layer + Repository pattern, Events/Listeners, Queue jobs (email,
notifications, certificate & report generation, commission calculation),
Redis cache/queue/session, Horizon + Telescope monitoring, S3-compatible
private file storage with signed URLs, Dockerized (Nginx + PHP-FPM + MySQL +
Redis + Supervisor). These are layered on in later phases — see the roadmap.

## Security

CSRF, hashed passwords (bcrypt), role-based access control, policies,
soft deletes, audit logging, database transactions on money operations,
login throttling, and private file storage. Manual payment verification is
mandatory for every enrollment.
