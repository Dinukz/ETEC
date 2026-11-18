# Fix: "Access denied for user 'u394069166_etec'@'localhost'"

This error means Laravel cannot connect to MySQL. The password or permissions are wrong.

## Quick fixes in order:

### 1️⃣ Verify `.env` has correct credentials

SSH or cPanel File Manager:

```bash
cat .env | grep DB_
```

Should show:
```
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=u394069166_etec
DB_USERNAME=u394069166_etec
DB_PASSWORD=Cloud$@#!25
```

**If DB_PASSWORD is different**, update `.env` with the correct password.

---

### 2️⃣ Test MySQL access directly

SSH into your hosting:

```bash
mysql -u u394069166_etec -p u394069166_etec
```

When prompted, enter: `Cloud$@#!25`

**If it fails with "Access denied":**
→ Password is wrong. Go to cPanel → MySQL Databases → find user `u394069166_etec` → **Change Password**

**If it connects:**
→ Type `SHOW TABLES;` and press Enter
→ You should see 40+ tables (users, roles, courses, etc.)
→ If you don't see tables, run the import again

---

### 3️⃣ Verify user has privileges

In MySQL (from step 2), run:

```sql
SHOW GRANTS FOR 'u394069166_etec'@'localhost';
```

Should show something like:
```
GRANT ALL PRIVILEGES ON `u394069166_etec`.* TO 'u394069166_etec'@'localhost'
```

**If it shows nothing or USAGE only:**
→ User doesn't have database privileges. Go to cPanel → MySQL Databases → Manage User Privileges → check **All** → **Make Changes**

---

### 4️⃣ Clear Laravel cache

Some hosting servers cache `.env` values. SSH:

```bash
php artisan config:clear
php artisan cache:clear
```

Then reload the page.

---

### 5️⃣ Check if tables exist

SSH:

```bash
mysql -u u394069166_etec -p u394069166_etec
```

Enter password, then:

```sql
USE u394069166_etec;
SHOW TABLES;
SELECT COUNT(*) FROM users;
```

**If no tables show:**
→ The SQL import didn't work. Go back to phpMyAdmin and re-import:
   - `etec-schema.sql` (run first)
   - `etec-seed-data.sql` (run second)

**If tables exist but you still get 403:**
→ Check `storage/logs/laravel.log` for the exact error

---

## Most common cause

**Your MySQL password changed or is different from what's in `.env`**

### Fix it:

1. cPanel → **MySQL Databases**
2. Find user `u394069166_etec`
3. Click the red icon next to it to **Change Password**
4. Set it to: `Cloud$@#!25` (or your preferred password)
5. Click **Update Password**
6. Update `.env` to match:
   ```
   DB_PASSWORD=YourNewPassword
   ```
7. Run:
   ```bash
   php artisan config:clear
   ```
8. Reload the page

---

## Still failing? Run this diagnostic

SSH:

```bash
php artisan tinker
DB::connection()->getPdo()
echo "Connected!"
```

If it says "Connected!" → database works, error is elsewhere
If it fails → database connection is broken, follow the steps above

---

## Last resort: Reset everything

If nothing works, completely reset:

1. cPanel → **MySQL Databases** → Delete database `u394069166_etec`
2. **Add** a new database `u394069166_etec`
3. **Create User** `u394069166_etec` with password `Cloud$@#!25`
4. **Add User to Database** with **All Privileges**
5. In phpMyAdmin, import both SQL files again:
   - `etec-schema.sql`
   - `etec-seed-data.sql`
6. Update `.env` with the new credentials
7. Reload the page
