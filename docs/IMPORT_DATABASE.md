# Import ETEC Database Schema & Seed Data

Two SQL files are included in the `database/` folder. Import them in this exact order via phpMyAdmin:

1. `etec-schema.sql` — Creates all 40+ tables
2. `etec-seed-data.sql` — Adds roles, permissions, demo users, settings, and sample course

## Method 1: phpMyAdmin (Easiest)

1. Log into **cPanel** → **phpMyAdmin**
2. Select the database `u394069166_etec`
3. Click **Import** tab at the top
4. Click **Choose File**
5. Select `database/etec-schema.sql`
6. Scroll to bottom → Click **Import** button
7. Wait for "Import successful" message
8. Repeat steps 3-7 for `database/etec-seed-data.sql`

---

## Method 2: SSH / Command Line

SSH into your hosting:

```bash
cd ~/public_html/etec/database

# Import schema
mysql -u u394069166_etec -p u394069166_etec < etec-schema.sql
# (enter password when prompted: Cloud$@#!25)

# Import seed data
mysql -u u394069166_etec -p u394069166_etec < etec-seed-data.sql
```

---

## Method 3: cPanel File Manager + SQL Import

1. Upload `etec-schema.sql` and `etec-seed-data.sql` to `public_html/etec/database/` via File Manager
2. In cPanel, go to **Databases** → **phpMyAdmin**
3. Select your database
4. Use **Import** to upload and run each SQL file in order

---

## After import: Verify

```bash
# SSH into your hosting:
mysql -u u394069166_etec -p u394069166_etec

# Inside mysql:
SHOW TABLES;
SELECT COUNT(*) FROM users;
SELECT COUNT(*) FROM roles;
```

You should see:
- 40+ tables ✅
- 6 users ✅
- 6 roles ✅

---

## Default login accounts (after import)

| Email | Password | Role |
|-------|----------|------|
| superadmin@etec.lk | password | Super Admin |
| admin@etec.lk | password | Admin |
| accountant@etec.lk | password | Accountant |
| lecturer@etec.lk | password | Lecturer |
| consultant@etec.lk | password | Consultant |
| student@etec.lk | password | Student |

**⚠️ Change all passwords immediately in production!**

---

## If import fails

**Error: "Table 'X' already exists"**
→ Database not empty. Go to phpMyAdmin → drop all tables first, then re-import

**Error: "Access denied for user..."**
→ Wrong DB user/password in SQL command. Verify `.env` has correct credentials

**Error: "No privileges to create tables"**
→ Your DB user needs `CREATE` privilege. Contact hosting support to grant it

---

## Troubleshoot connection

After importing, verify the app can connect:

1. Visit: `https://yourdomain.com/public/index.php`
2. Should show login page (not 500 error)
3. Try logging in as `student@etec.lk` / `password`

If still 500: Check `storage/logs/laravel.log` for the error.
