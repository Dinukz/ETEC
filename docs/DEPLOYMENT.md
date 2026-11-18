# ETEC — Deployment Guide

## Overview

ETEC is deployed as a Laravel 12 application on shared hosting or a VPS. This
guide covers shared hosting (typical cPanel/WHM setup).

## Prerequisites

- PHP **8.3+** (request from your hosting provider if needed)
- MySQL 8 or MariaDB 10.6+
- Composer (typically available via `php composer.phar` or `/usr/local/bin/composer`)
- SSH access to your hosting account

## Deployment steps

### 1. Upload the code

Clone or upload the repository to your hosting account. Typically, files go into
a directory like `public_html` or a subdomain folder. The **public/** directory
must be the web root (set via cPanel "Document Root").

```bash
cd ~/public_html
git clone <your-repo-url> .
# or: unzip etec.zip
```

### 2. Install dependencies

```bash
php composer.phar install --no-dev --optimize-autoloader
# or, if composer is in PATH:
composer install --no-dev --optimize-autoloader
```

### 3. Configure the environment

Copy `.env.example` to `.env` and fill in your hosting DB credentials:

```bash
cp .env.example .env
```

Edit `.env`:
```
APP_NAME=ETEC
APP_ENV=production
APP_DEBUG=false
APP_URL=https://yourdomain.com

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=u394069166_etec        # Your hosting DB name
DB_USERNAME=u394069166_etec        # Your hosting DB user
DB_PASSWORD=Cloud$@#!25            # Your hosting DB password

MAIL_MAILER=smtp
MAIL_HOST=your-smtp-host
MAIL_PORT=587
MAIL_USERNAME=your-email@domain
MAIL_PASSWORD=your-email-password
MAIL_FROM_ADDRESS=no-reply@yourdomain.com
```

### 4. Generate app key and cache config

```bash
php artisan key:generate
php artisan config:cache
php artisan route:cache
```

### 5. Run migrations and seeders

```bash
php artisan migrate --seed
```

This creates all tables and seeds baseline data (roles, permissions, settings,
demo users, demo course).

### 6. Set file permissions

```bash
chmod -R 755 storage bootstrap/cache
chmod -R 775 storage
```

### 7. Set the public directory as web root

In cPanel:
1. Go to **Addon Domains** or **Domains**
2. Click the domain
3. Set **Document Root** to `/home/username/public_html/public`

Or, create a `.htaccess` in `public_html/` to redirect to `public/`:
```apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteRule ^(.*)$ public/$1 [L]
</IfModule>
```

### 8. SSL certificate

Use Let's Encrypt (free, usually available in cPanel) to enable HTTPS. Update
`APP_URL=https://...` in `.env` once SSL is active.

---

## After deployment

### First login

| Role | Email | Password |
|------|-------|----------|
| Super Admin | superadmin@etec.lk | password |
| Admin | admin@etec.lk | password |
| Accountant | accountant@etec.lk | password |
| Lecturer | lecturer@etec.lk | password |
| Consultant | consultant@etec.lk | password |
| Student | student@etec.lk | password |

**Change all passwords immediately.** Remove demo accounts if not needed.

### Email configuration

Update `.env` with your hosting's SMTP details so notifications actually send.
Test with `php artisan tinker` → `Mail::raw('test', fn ($m) => $m->to('you@example.com'));`

### Backups

Set up a cron job (via cPanel) to back up the database daily:
```bash
0 2 * * * mysqldump -u u394069166_etec -pCloud$@#!25 u394069166_etec > ~/backups/etec_$(date +%Y%m%d).sql
```

### Monitoring

Monitor `storage/logs/laravel.log` for errors. If your hosting provides Sentry
or similar, set `SENTRY_DSN=...` in `.env` for error tracking.

---

## Troubleshooting

**500 error after deployment:**
- Check `storage/logs/laravel.log`
- Verify file permissions on `storage/` and `bootstrap/cache/`
- Ensure `.env` DB credentials are correct

**Migrations fail:**
- Verify DB credentials in `.env`
- Check if your hosting's MySQL character set is `utf8mb4`
- Run `php artisan migrate --verbose` for detailed error

**Composer out of memory:**
```bash
COMPOSER_MEMORY_LIMIT=-1 composer install --no-dev
```

---

## Next steps

- Build Phase 2 (auth UI) locally, test thoroughly, then deploy
- Configure email providers (SendGrid, Mailgun) for transactional emails
- Set up monitoring (Sentry, New Relic, DataDog) in production
- Document your deployment process for your team
