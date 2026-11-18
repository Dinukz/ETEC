# ETEC — Quick Start

## For local development

```bash
# 1. Ensure PHP 8.3 is in PATH (Windows: C:\php83 or Linux/Mac: check php -v)
php -v                      # must be 8.3+

# 2. Install dependencies
composer install

# 3. Generate key (already done)
php artisan key:generate

# 4. Local testing (no live DB needed yet)
php artisan tinker
# Try: DB::table('users')->count();  (will error until migrations run)
```

## For remote hosting

### Step 1: Upload
```bash
git clone <repo> ~/public_html
cd ~/public_html
```

### Step 2: Configure
```bash
cp .env.example .env
# Edit .env with your hosting DB credentials:
#   DB_HOST=localhost
#   DB_DATABASE=u394069166_etec
#   DB_USERNAME=u394069166_etec
#   DB_PASSWORD=Cloud$@#!25
```

### Step 3: Deploy
```bash
# Run the deployment script (handles everything)
bash setup-remote.sh      # Linux/Mac
# or
setup-remote.bat          # Windows
```

### Step 4: Set public root in cPanel
- Go to **Domains** → your domain → **Document Root**
- Set to `/home/username/public_html/public`

### Step 5: Enable SSL
- Use **cPanel AutoSSL** or Let's Encrypt
- Update `.env`: `APP_URL=https://yourdomain.com`

---

## First login (all platforms)

| Role | Email | Password |
|---|---|---|
| Super Admin | superadmin@etec.lk | password |
| Admin | admin@etec.lk | password |
| Accountant | accountant@etec.lk | password |
| Lecturer | lecturer@etec.lk | password |
| Consultant | consultant@etec.lk | password |
| Student | student@etec.lk | password |

**⚠️ Change all passwords immediately in production.**

---

## What's included (Phase 1 Foundation)

✅ Full database schema (30+ migrations)  
✅ 28 Eloquent models with relationships  
✅ 6 roles + 45 granular permissions  
✅ Settings & commission configuration (DB-driven)  
✅ Activity logging (audit trail)  
✅ Seeded demo data & accounts  

## What's next (Phase 2+)

- Auth UI (registration, login, email verification, password reset)
- Role-based dashboards
- Public website (Blade + Tailwind)
- LMS core (courses, lessons, assignments, exams)
- Payments & enrollment (manual verification)
- Consultant & commission engine
- Reports (PDF/Excel export)

---

## File locations

- `.env` — database & app config (add to `.gitignore` before pushing)
- `app/Models/` — 28 domain models
- `database/migrations/` — schema definition
- `database/seeders/` — baseline data
- `docs/` — ER diagram, deployment guide, roadmap
- `storage/logs/laravel.log` — error tracking (check here if things break)

## Troubleshooting

**"No connection could be made"**
→ Check `.env` DB credentials are correct  

**"SQLSTATE[HY000] [2002]"**
→ MySQL not running or host/port wrong  

**"Class not found"**
→ Run `composer dump-autoload -o`  

**500 error on page load**
→ Check `storage/logs/laravel.log`
