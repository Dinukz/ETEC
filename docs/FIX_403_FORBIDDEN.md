# Fix 403 Forbidden Error

When you see "403 Forbidden" after uploading ETEC, it's usually one of these issues:

## 1️⃣ Check document root in cPanel

**This is the #1 cause.**

1. Log into cPanel
2. Go to **Addon Domains** or **Domains**
3. Find your domain
4. Click **Manage** or the domain name
5. Look for **Document Root**
6. It should end with `/public` (e.g., `/home/u394069166/public_html/etec/public`)

**If it doesn't:**
- Change it to point to the `public` folder
- **DO NOT** point to the root folder

---

## 2️⃣ Set file permissions

SSH into your hosting and run:

```bash
cd ~/public_html/etec
chmod -R 755 .
chmod -R 777 storage
chmod -R 777 bootstrap/cache
chmod 644 .env
```

---

## 3️⃣ Verify `.htaccess` exists

The file `public/.htaccess` should exist and contain URL rewriting rules. 

**Check if it's there:**
```bash
ls -la public/.htaccess
```

**If missing**, create it:
```bash
cat > public/.htaccess << 'EOF'
<IfModule mod_rewrite.c>
    <IfModule mod_negotiation.c>
        Options -MultiViews
    </IfModule>

    RewriteEngine On

    # Handle Authorization Header
    RewriteCond %{HTTP:Authorization} .
    RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]

    # Redirect Trailing Slashes If Not A Folder...
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_URI} (.+)/$
    RewriteRule ^ %1 [L,R=301]

    # Send Requests To Front Controller...
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteRule ^ index.php [L]
</IfModule>
EOF
```

---

## 4️⃣ Check storage/ is writable

```bash
touch storage/test.txt
rm storage/test.txt
# If these work, permissions are OK
```

---

## 5️⃣ Check `storage/logs/` for errors

```bash
tail -50 storage/logs/laravel.log
```

This will show the actual error.

---

## 6️⃣ If still failing: try a test file

Create `public/test.php`:
```php
<?php echo "Hello World"; ?>
```

Visit `https://yourdomain.com/test.php`. If it shows "Hello World", web access works
and the issue is Laravel-specific. If it shows 403, it's a server permission issue.

---

## Common scenarios

### "403 Forbidden" on every page including test.php
→ **File permissions or document root** — follow steps 1-2 above

### "403 Forbidden" only on Laravel routes (test.php works)
→ **`.htaccess` missing or broken** — follow step 3 above

### Page loads but shows "No input file specified"
→ **Document root is set to root instead of public/** — follow step 1 above

### White blank page (no error)
→ **Check `storage/logs/laravel.log`** — follow step 5 above

---

## Quick checklist

- [ ] Document root ends with `/public`
- [ ] `chmod -R 777 storage` (writable)
- [ ] `public/.htaccess` exists
- [ ] `storage/logs/laravel.log` checked for errors
- [ ] Tried accessing `public/test.php` to confirm web access works

Once you fix these, reload the page and it should work!
