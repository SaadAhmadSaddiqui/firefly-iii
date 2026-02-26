# Firefly III — Local Setup Guide (No Docker, PostgreSQL)

A step-by-step guide to running Firefly III on your local Windows machine as your daily budgeting app, without Docker, using PostgreSQL.

---

## Prerequisites

| Tool | Version | Notes |
|------|---------|-------|
| **PHP** | >= 8.4 | Installed at `C:\tools\php85` |
| **Composer** | Latest | PHP dependency manager |
| **Node.js + npm** | LTS (v20+) | For building frontend assets |
| **PostgreSQL** | 15+ | Database server |
| **Git** | Latest | Already installed if you cloned this repo |

### PHP Extensions (already configured)

The following extensions have been enabled in `C:\tools\php85\php.ini`:

- curl, fileinfo, gd, mbstring, openssl *(were already enabled)*
- **intl** *(newly enabled)*
- **sodium** *(newly enabled)*
- **zip** *(newly enabled)*
- pdo_pgsql, pgsql *(were already enabled — required for PostgreSQL)*
- bcmath, iconv, json, pdo, session, simplexml, tokenizer, xml, xmlwriter *(built-in with PHP 8.5)*

`memory_limit` has also been increased from 128M to 512M for Firefly III.

---

## Step 1 — Install & Configure PostgreSQL

1. **Download and install** PostgreSQL from [postgresql.org/download/windows](https://www.postgresql.org/download/windows/).

2. During installation, note the **password** you set for the `postgres` superuser.

3. **Create a database and user** for Firefly III. Open a terminal (or use pgAdmin) and run:

   ```sql
   -- Connect to PostgreSQL as superuser
   psql -U postgres

   -- Create a dedicated user
   CREATE USER firefly WITH PASSWORD 'your_secure_password_here';

   -- Create the database
   CREATE DATABASE firefly_iii OWNER firefly;

   -- Grant privileges
   GRANT ALL PRIVILEGES ON DATABASE firefly_iii TO firefly;

   -- Exit
   \q
   ```

---

## Step 2 — Clone the Repository

If you haven't already:

```bash
git clone https://github.com/firefly-iii/firefly-iii.git
cd firefly-iii
```

---

## Step 3 — Install PHP Dependencies

```bash
composer install --no-dev --prefer-dist
```

> Use `--no-dev` for a production-like setup. If you plan to develop/debug, omit it.

---

## Step 4 — Configure the Environment

The `.env` file has already been created with PostgreSQL settings pre-configured.
If starting fresh, you would copy from `.env.example`:

```bash
cp .env.example .env
```

**Generate an application key** (replaces the placeholder in `.env`):

```bash
php artisan key:generate
```

**Verify these key values in `.env`:**

```dotenv
# Database — PostgreSQL
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=firefly_iii
DB_USERNAME=firefly
DB_PASSWORD=secret_firefly_password    # <-- change this to your actual PG password

# App URL — must match the port you serve on
APP_URL=http://localhost:8080

# Frontend layout: v1 (classic) or v2 (new UI). Use v1 if v2 has missing/incomplete features.
FIREFLY_III_LAYOUT=v1
```

**Layout (v1 vs v2):** Firefly III has two UIs. Set `FIREFLY_III_LAYOUT=v1` for the classic, feature-complete interface (Twig + jQuery). Set `FIREFLY_III_LAYOUT=v2` for the newer UI (Blade + Vite + Alpine.js). If you see placeholder text, empty charts, or missing data on v2, switch to v1 by setting `FIREFLY_III_LAYOUT=v1` in `.env`, then run `php artisan config:clear` and reload the app.

> **Important:** Update `DB_PASSWORD` in `.env` to match the password you set when creating the PostgreSQL user in Step 1.

---

## Step 5 — Set Up the Database

Run the following commands in order. They work with PostgreSQL: the upgrade command runs Laravel migrations (DB-agnostic), seeds reference data, and explicitly runs a PostgreSQL-specific step (`upgrade:600-pgsql-sequences`) to fix sequences when `DB_CONNECTION=pgsql`.

```bash
# Run migrations, seed reference data, and upgrade the schema
php artisan firefly-iii:upgrade-database

# Fix any data integrity issues
php artisan firefly-iii:correct-database

# Generate a report (optional, good to verify)
php artisan firefly-iii:report-integrity

# Generate OAuth keys for the API
php artisan firefly-iii:laravel-passport-keys
```

---

## Step 6 — Build Frontend Assets

Firefly III has two frontend versions. **V2 is the current/default UI** (Vite + Alpine.js).

```bash
# Install all npm dependencies (from the project root)
npm install

# Build the v2 frontend
npm run build --workspace=resources/assets/v2
```

If you also want the legacy v1 frontend:

```bash
npm run production --workspace=resources/assets/v1
```

---

## Step 7 — Set Directory Permissions

Laravel needs write access to certain directories:

```bash
# On Windows (Git Bash / WSL), make sure these directories are writable:
mkdir -p storage/framework/cache
mkdir -p storage/framework/sessions
mkdir -p storage/framework/views
mkdir -p storage/logs
mkdir -p bootstrap/cache

# If using Linux/WSL:
chmod -R 775 storage bootstrap/cache
```

On native Windows, these directories should already be writable.

---

## Step 8 — Start the Application

### Option A: PHP's Built-in Server (simplest)

```bash
c:\tools\php85\php.exe artisan serve --port=8080
```

Or if PHP is in your PATH:

```bash
php artisan serve --port=8080
```

Then open [http://localhost:8080](http://localhost:8080) in your browser.

> This is fine for personal/local use. The built-in server handles one request at a time, which is perfectly acceptable for a single-user budgeting app.

### Option B: Nginx + PHP-FPM (better performance)

If you want a more robust setup (e.g., via WSL or Laragon):

1. Point the Nginx `root` to the `public/` directory of this project.
2. Configure PHP-FPM to handle `.php` files.
3. Example Nginx config:

   ```nginx
   server {
       listen 8080;
       server_name localhost;
       root /path/to/firefly-iii/public;

       index index.php index.html;

       location / {
           try_files $uri $uri/ /index.php?$query_string;
       }

       location ~ \.php$ {
           fastcgi_pass 127.0.0.1:9000;
           fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
           include fastcgi_params;
       }

       location ~ /\.(?!well-known).* {
           deny all;
       }
   }
   ```

### Option C: Laragon (Windows-native, zero config)

If you use [Laragon](https://laragon.org/), you can serve Firefly III from a symlink in Laragon's `www` directory.

**What to turn on**

- Click **Start All** (or start **Apache** only). That’s all you need for the site to be served.
- **MySQL** — You don’t need it for Firefly III if you’re using **PostgreSQL**. Leave it off or start it; the app uses the database in your `.env` (PostgreSQL).
- **Mailpit** — Optional. It’s a local mail catcher. If you use Gmail SMTP, mail goes to Gmail; you don’t need Mailpit.
- **Redis** — Not in Laragon by default and **not required**. Firefly III works with `CACHE_DRIVER=file` and `SESSION_DRIVER=file`. Only install Redis if you want it for performance later.

**Steps after the symlink is in `www`**

1. **Document root must be `public`.**  
   Laragon must serve the **`public`** folder of Firefly III, not the project root.  
   - If your symlink is `C:\laragon\www\firefly-iii` → project root, then either:
     - In Laragon: **Menu → Apache → Virtual Hosts** (or **Nginx → …**), and set the document root for `firefly-iii.test` to `C:\laragon\www\firefly-iii\public`, or  
     - Replace the symlink with one that points directly to the `public` folder (e.g. `C:\laragon\www\firefly-iii` → `D:\…\firefly-iii\public`).  
   Then restart Apache (Laragon → **Stop All** → **Start All**).

2. **Set `APP_URL` in `.env`** to the URL you’ll use, e.g.:  
   `APP_URL=http://firefly-iii.test`

3. **Open the site** in your browser: **http://firefly-iii.test** (or the hostname Laragon shows for that folder).

4. **PHP version:** Firefly III expects PHP 8.4+. If Laragon is on PHP 8.3, switch Laragon to PHP 8.4+ (Laragon → **Menu → PHP → Version**) if available, or use another way to run the app (e.g. `php artisan serve` with your `C:\tools\php85` PHP).

---

## Step 9 — Create Your Account

1. Open the app in your browser.
2. Click **Register** to create your first (owner) account.
3. You're in! Start adding your bank accounts, transactions, and budgets.

---

## Changing the primary currency (e.g. to DHS/AED)

By default Firefly III uses **Euro (EUR)** as the primary currency. To use **UAE Dirham (DHS)** or another currency:

1. **Add the currency** (if it's not listed):
   - Go to **Profile** (top right) → **Financial administration** → **Currencies** (or **Options** → **Currencies** in the menu).
   - Click **Create currency**.
   - Set **Code** to `AED` (for UAE Dirham), **Name** to e.g. *UAE Dirham*, **Symbol** to e.g. `د.إ`, **Decimal places** to `2`, and enable it. Save.

2. **Set it as primary**:
   - On the **Currencies** page, find **UAE Dirham (AED)** in the list.
   - Click the green **Make default** button (star icon) next to it.
   - The page will reload; AED will show a **primary** label. Your default currency is now DHS.

All new accounts and summaries will use this currency. Existing transactions keep their original currency; Firefly III can convert and show amounts in the primary currency where relevant.

### Why can't I disable or delete Euro?

If you get **"Cannot disable Euro because it is used in asset accounts"** (or similar), Firefly III is blocking the action because at least one **account** still has Euro set as its **account currency** in the database. That is separate from the **primary (default) currency**: each account has its own currency (e.g. for balances and new transactions). The list page can show balances converted to your primary currency (e.g. dhs), but the account’s stored currency might still be EUR.

**Fix:**

1. **See which accounts use Euro** (from the project directory):
   ```bash
   php artisan firefly:list-accounts-using-currency EUR
   ```
2. **Edit each listed account**: go to **Accounts** → open the account → **Edit** → set **Currency** to **UAE Dirham (AED)** (or your desired currency) → Save.
3. After every account that used EUR is switched to AED, you can **Disable** (or delete) Euro on the Currencies page.

---

## Step 10 — Set Up the Cron Job

Firefly III needs a daily cron job to process recurring transactions, auto-budgets, and exchange rate updates.

### Windows Task Scheduler

1. Open **Task Scheduler** (`taskschd.msc`).
2. Create a new **Basic Task**:
   - **Name:** `Firefly III Cron`
   - **Trigger:** Daily, at 3:00 AM (or whenever you prefer).
   - **Action:** Start a program.
   - **Program:** `C:\tools\php85\php.exe`
   - **Arguments:** `artisan firefly-iii:cron`
   - **Start in:** `D:\My Stuff\Work\Open Source Work\firefly-iii`
3. Save and enable.

### Alternative: Web-based Cron

If you can't use Task Scheduler, you can hit the cron endpoint via a browser or `curl`:

```
GET http://localhost:8080/api/v1/cron/YOUR_STATIC_CRON_TOKEN
```

You can use a free service like [cron-job.org](https://cron-job.org) to ping this URL daily.

---

## Running Firefly III Permanently (Without Cursor)

You can have Firefly III available whenever your PC is on, without opening Cursor or running commands each time.

### Option A: Start the PHP server with Windows

Use Task Scheduler to run the built-in PHP server when you log in (or when the machine starts):

1. Open **Task Scheduler** (`taskschd.msc`).
2. **Create Basic Task** → Name: e.g. `Firefly III Server`.
3. **Trigger:** “When I log on” (or “At startup” if you want it before login).
4. **Action:** Start a program.
   - **Program:** `C:\tools\php85\php.exe`
   - **Arguments:** `artisan serve --port=8080 --host=127.0.0.1`
   - **Start in:** `D:\My Stuff\Work\Open Source Work\firefly-iii` (your project path).
5. **Finish.** Optionally: Task → Properties → “Run whether user is logged on or not” if you want it to run before login (requires entering your Windows password).

After that, open **http://127.0.0.1:8080** in your browser anytime; no need to start Cursor or a terminal. The app and data stay the same between reboots.

### Option B: Laragon (always-on local hosting)

[Laragon](https://laragon.org/) runs Apache or Nginx and PHP in the background. You can:

1. Install Laragon and set it to start with Windows (Laragon → Menu → Auto start).
2. Put your Firefly III project in Laragon’s `www` folder (e.g. `C:\laragon\www\firefly-iii`), or symlink it there.
3. Point the browser to the virtual host Laragon creates (e.g. **http://firefly-iii.test**).

Then Firefly III is served by Laragon whenever Laragon is running; no `php artisan serve` needed.

### Option C: Nginx or Apache + PHP-FPM

For a more production-style setup, install Nginx (or Apache) and PHP-FPM, configure a virtual host whose document root is the Firefly III `public/` folder, and start the web server with Windows. The app is then always on when the server is running. See **Step 8 — Option B** for a sample Nginx config.

---

## Updating Firefly III

When a new version is released:

```bash
# Pull latest changes
git pull origin main

# Update PHP dependencies
composer install --no-dev --prefer-dist

# Update frontend
npm install
npm run build --workspace=resources/assets/v2

# Run database upgrades
php artisan firefly-iii:upgrade-database
php artisan firefly-iii:correct-database
php artisan firefly-iii:laravel-passport-keys
```

---

## Backing Up Your Data

**Your data is persistent.** PostgreSQL stores everything on disk, and your Firefly III files (including `.env` and `storage/`) stay on your machine. You do **not** need to back up just to “keep data between sessions” — closing Cursor or stopping the server does not erase anything. Backups are for **disaster recovery** (disk failure, accidental deletion, malware) and **portability** (moving to another PC or restoring after a reinstall).

### Full Backup (database + attachments)

The built-in backup command creates a single `.tar.gz` archive containing the full PostgreSQL dump and all attachment files:

```bash
# Create a full backup (saved to storage/backups/)
php artisan firefly:backup

# Restore from a backup (replaces ALL data)
php artisan firefly:restore storage/backups/firefly_backup_2026-02-26_130317.tar.gz
```

You can also do this from the web UI at `/backup` (sidebar: **Backup & Restore**).

### CSV Export

For a partial CSV export of transactions and other data:

```bash
php artisan firefly-iii:export-data --export_directory=./exports
```

### What the Full Backup Includes

| Item | How it's captured |
|------|-------------------|
| Database (all tables) | `pg_dump` inside the archive |
| Uploaded attachments | Copied from `storage/upload/` |
| OAuth keys | Stored in the database (auto-restored) |
| Environment config | **NOT included** — copy `.env` separately |

---

## Troubleshooting

### "PDO driver not found" or "could not find driver"

Make sure `extension=pdo_pgsql` is uncommented in `C:\tools\php85\php.ini`. Verify with:

```bash
c:\tools\php85\php.exe -m | findstr pgsql
```

You should see both `pdo_pgsql` and `pgsql`.

### "Unsupported cipher or incorrect key length"

Laravel requires `APP_KEY` to start with `base64:` followed by a base64-encoded key. If you see this error during `composer install` or when running artisan:

1. Open `.env` and ensure the line looks like:  
   `APP_KEY=base64:xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx=`  
   (not just `APP_KEY=xxxx...` without the `base64:` prefix).
2. If you're not sure, run `php artisan key:generate` — it will overwrite `APP_KEY` with the correct format. Back up `.env` first if you already have other values you care about.

### "Permission denied" on storage/logs

Ensure the `storage/` and `bootstrap/cache/` directories are writable by the user running PHP.

### "Writing to the log file failed" / errno=9 Bad file descriptor

If you see `UnexpectedValueException: Writing to the log file failed: Write of ... bytes failed with errno=9 Bad file descriptor` (often when using Laragon or a symlinked document root), the app is failing to write to the log file. Try:

1. **Use stdout for logging (quick fix)**  
   In `.env` set:
   ```env
   LOG_CHANNEL=stdout
   ```
   Logs will go to the web server’s output instead of a file, and the error should stop.

2. **If you want file logging again later:**  
   Ensure `storage/logs` exists and is writable by the user running Apache/PHP. Delete or clear existing log files in `storage/logs` (e.g. `ff3-*.log`) so Laravel opens a fresh file. Then set `LOG_CHANNEL=stack` or `LOG_CHANNEL=daily` again. If the error returns, keep `LOG_CHANNEL=stdout` or fix permissions/path (e.g. avoid symlinks that make the log path invalid for the server process).

### Blank page or 500 error

```bash
# Check the Laravel log
cat storage/logs/laravel.log

# Clear all caches and retry
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear
```

### Database connection refused

1. Verify PostgreSQL is running: `pg_isready -h 127.0.0.1 -p 5432`
2. Double-check credentials in `.env`.
3. Make sure `pg_hba.conf` allows local connections with password auth.

### Frontend assets not loading (404 on CSS/JS)

Rebuild assets:

```bash
npm run build --workspace=resources/assets/v2
```

Make sure `APP_URL` in `.env` matches the URL you're accessing.

### Asset accounts (or other v2 pages) show "firefly.wait_loading_data" and "Not yet implemented"

The v2 UI loads translations from `/v2/i18n/{locale}.json`. This project serves that via a Laravel route (no static JSON files needed). If you still see the raw key:

1. **Rebuild the frontend** so the latest JS (including error handling) is used:
   ```bash
   npm run build --workspace=resources/assets/v2
   ```
2. **Hard-refresh the page** (Ctrl+F5) and, if using Laragon, restart Apache so the new route is in effect.
3. **Check the browser Network tab** (F12 → Network): ensure `v2/i18n/en_US.json` (or your locale) returns **200** with JSON. If it returns 404, the route is not registered (e.g. config or route cache). Run `php artisan route:clear` and `php artisan config:clear`, then reload.
4. If the loading spinner never disappears, an API call (preferences or accounts) may be failing. Check the Network tab for red (failed) requests to `/api/v1/...` and the Console for errors. Fix any backend or CORS issues so those requests succeed.

### Account page charts (Expenses by category, Expenses by budget, Income by category) are empty

On the account show page (e.g. **Accounts → Emirates NBD**), three pie charts show expenses by category, expenses by budget, and income by category for the selected date range.

- **Expected when you have no data:** If there are no withdrawals (expenses) or deposits (income) in that period, the chart will show *"There is not enough information (yet) to generate this chart."* That is normal for a new account with only an opening balance.
- **If the boxes stay completely empty (no message):** The chart API may be failing. Open DevTools (F12) → **Network**, reload the page, and look for requests to `/chart/account/expense-category/...`, `/chart/account/expense-budget/...`, `/chart/account/income-category/...`. If they return **500** or **404**, fix the backend (e.g. run `php artisan config:clear` and `php artisan route:clear`). After a fix in this project, failed chart requests now show the same "not enough information" message instead of staying blank.
- **To see charts:** Add some transactions in the period (withdrawals with categories/budgets, or deposits) and refresh the page.

---

## Quick Reference

| Task | Command |
|------|---------|
| Start the app | `php artisan serve --port=8080` |
| Run cron manually | `php artisan firefly-iii:cron` |
| Clear all caches | `php artisan optimize:clear` |
| Check database | `php artisan firefly-iii:verify-database-connection` |
| Upgrade database | `php artisan firefly-iii:upgrade-database` |
| Full backup | `php artisan firefly:backup` |
| Restore backup | `php artisan firefly:restore <path-to-archive>` |
| Export CSV | `php artisan firefly-iii:export-data --export_directory=./exports` |
| Refresh balances | `php artisan firefly-iii:refresh-running-balance` |

### Custom Import Commands

These are custom Artisan commands for importing bank data into Firefly III.

**Emirates NBD / Emirates Islamic (JSON format)**

```bash
# Import Emirates NBD debit transactions (account #1)
php artisan firefly:import-transactions new-transactions.json --source-account-id=1

# Import Emirates Islamic credit card transactions (account #49)
php artisan firefly:import-transactions emirates-islamic-transactions.json --source-account-id=49

# Dry run (preview without creating)
php artisan firefly:import-transactions <file> --source-account-id=<id> --dry-run
```

**Mashreq Cashback Card (CSV format)**

```bash
# Import Mashreq credit card transactions (account #50)
php artisan firefly:import-mashreq mashreq-transactions.csv

# Dry run
php artisan firefly:import-mashreq mashreq-transactions.csv --dry-run

# With a different account ID
php artisan firefly:import-mashreq mashreq-transactions.csv --source-account-id=<id>
```

**FAB Cashback Card (CSV format)**

```bash
# Import FAB credit card transactions (account #51)
php artisan firefly:import-fab fab-transactions.csv

# Dry run
php artisan firefly:import-fab fab-transactions.csv --dry-run
```

**Other tools**

```bash
# Import beneficiaries as expense accounts
php artisan firefly:import-beneficiaries beneficiaries.json --source-account-id=1

# List accounts using a specific currency (useful for disabling EUR)
php artisan firefly:list-accounts-using-currency EUR

# Analyze recurring transactions from a JSON export
php artisan firefly:analyze-recurring more-transactions.json
```

**Account IDs**

| Account | ID | Type |
|---------|---:|------|
| Emirates NBD (Debit) | 1 | Asset |
| Emirates Islamic RTA Credit Card | 49 | Asset (Credit Card) |
| Mashreq Cashback Card | 50 | Asset (Credit Card) |
| FAB Cashback Card | 51 | Asset (Credit Card) |

---

## Annex: Optional env vars and feature completion

The `.env` file includes many variables that are empty or placeholders. Below is what each group does and what you need to do to make those features complete. **You do not need to change any of these for basic local use** — they are optional.

### Already set / no action needed

| Variable(s) | Purpose | Notes |
|-------------|---------|--------|
| `APP_KEY`, `STATIC_CRON_TOKEN` | Encryption and cron URL | Generated/set during setup. |
| `DB_*`, `PGSQL_*` | PostgreSQL | You configured these in the main guide. |
| `PASSPORT_PRIVATE_KEY`, `PASSPORT_PUBLIC_KEY` | OAuth / API tokens | Leave empty; Laravel Passport keys are generated by `php artisan firefly-iii:laravel-passport-keys` and stored under `storage/keys/`. Only set these if you want to supply your own keys. |
| `CACHE_DRIVER=file`, `SESSION_DRIVER=file` | Cache and sessions | Fine for local use. No action. |
| `AUTHENTICATION_GUARD=web` | Login method | Built-in DB auth. No action unless you use a reverse proxy with remote-user auth. |
| `DKR_CHECK_SQLITE` | Docker startup | Ignored when not using Docker. |
| `PUSHER_*`, `BROADCAST_DRIVER=log` | Real-time broadcast | Not used for normal budgeting. Leave as-is. |
| `DEMO_USERNAME`, `DEMO_PASSWORD` | Demo mode | Only used if you enable demo mode. Leave empty otherwise. |

---

### Email notifications (make notifications "complete")

**Current:** `MAIL_MAILER=log` — messages are written to the log file only, not sent.

To receive real emails (e.g. for recurring transaction reminders, error reports, or admin alerts):

1. Set `MAIL_MAILER` to your provider: `smtp`, `mailgun`, `mandrill`, `sparkpost`, or `mailersend`.
2. Fill the matching variables in `.env`.

---

#### Gmail / Google SMTP (send to your Gmail or any address)

Yes — you can use your Google account so Firefly III sends emails through Gmail. Those emails can go to the same Gmail address or to any other email you specify in Firefly III’s notification settings.

**Step 1: Turn on 2-Step Verification** (required for App Passwords)

1. Open [Google Account → Security](https://myaccount.google.com/security).
2. Under “How you sign in to Google”, turn on **2-Step Verification** and complete the setup.

**Step 2: Create an App Password**

1. Go to [Google Account → Security → 2-Step Verification](https://myaccount.google.com/apppasswords) (or search “App passwords” in your account).
2. At the bottom, click **App passwords**.
3. Choose app: **Mail**, device: **Other** (e.g. “Firefly III”).
4. Click **Generate**. Google shows a **16-character password** (like `abcd efgh ijkl mnop`).
5. Copy it and keep it somewhere safe — you’ll paste it into `.env` (no spaces: `abcdefghijklmnop`).

**Step 3: Set these in `.env`**

Replace `your-email@gmail.com` with your Gmail address and `your-16-char-app-password` with the generated app password (no spaces):

```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_FROM=your-email@gmail.com
MAIL_USERNAME=your-email@gmail.com
MAIL_PASSWORD=your-16-char-app-password
MAIL_ENCRYPTION=tls
MAIL_FROM_NAME="Firefly III"
```

**Step 4:** Restart the app (or run `php artisan config:clear`). Firefly III will then send notification emails through Gmail. Who receives them is controlled inside Firefly III (e.g. your profile email or notification preferences), not by Gmail — Gmail is just the *sender*.

**If you use a Google Workspace (work) account:** Same steps; 2-Step Verification and App Passwords must be enabled by you or your admin.

---

**SMTP (generic, other providers):**

**Mailgun:**

```env
MAIL_MAILER=mailgun
MAIL_FROM=noreply@yourdomain.com
MAILGUN_DOMAIN=yourdomain.com
MAILGUN_SECRET=key-xxxx
MAILGUN_ENDPOINT=api.mailgun.net
```

Use `api.eu.mailgun.net` for EU. Leave `MAIL_HOST`, `MAIL_PORT`, etc. as-is unless you use SMTP.

**Other providers:** Set `MANDRILL_SECRET`, `SPARKPOST_SECRET`, or `MAILERSEND_API_KEY` if you use Mandrill, SparkPost, or MailerSend. See [Firefly III email docs](https://docs.firefly-iii.org/how-to/firefly-iii/advanced/notifications/#email).

---

### Logging and audit

| Variable | Purpose | To make it "complete" |
|----------|---------|------------------------|
| `PAPERTRAIL_HOST`, `PAPERTRAIL_PORT` | Send logs to Papertrail | Only if you use Papertrail; set both. |
| `AUDIT_LOG_LEVEL` | Audit log verbosity | Set to `info` to enable audit logging; keep `emergency` to keep it off. |
| `AUDIT_LOG_CHANNEL` | Where audit logs go | Optional: `audit_daily`, `audit_stdout`, `audit_syslog`, etc. |

---

### Maps (transaction / account locations)

**Current:** `MAP_DEFAULT_LAT`, `MAP_DEFAULT_LONG`, `MAP_DEFAULT_ZOOM` are set to a default (Netherlands).

To default the map to your region, set in `.env`:

```env
MAP_DEFAULT_LAT=25.276987
MAP_DEFAULT_LONG=55.296249
MAP_DEFAULT_ZOOM=10
```

Use your preferred coordinates and zoom. No API key is required for the default map.

---

### Redis (optional performance)

**Current:** `CACHE_DRIVER=file`, `SESSION_DRIVER=file` — no Redis.

If you install Redis and want to use it for cache and sessions:

1. Install and run Redis (e.g. Windows port or WSL).
2. In `.env` set: `CACHE_DRIVER=redis`, `SESSION_DRIVER=redis`, and the `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD` (if needed), `REDIS_DB`, `REDIS_CACHE_DB` variables.
3. Leave `REDIS_USERNAME` empty unless you use Redis ACL.

---

### Reverse proxy (e.g. Nginx in front)

If you put Firefly III behind a reverse proxy, set `TRUSTED_PROXIES=**` so Laravel trusts `X-Forwarded-*` headers. For local-only use, leave empty.

---

### Remote-user authentication (e.g. Authelia)

If you use a reverse proxy that does auth and passes a remote user header, set `AUTHENTICATION_GUARD=remote_user_guard`, `AUTHENTICATION_GUARD_HEADER=REMOTE_USER`, and optionally `AUTHENTICATION_GUARD_EMAIL`. See [Firefly III authentication docs](https://docs.firefly-iii.org/how-to/firefly-iii/advanced/authentication/).

---

### Custom logout URL

Set `CUSTOM_LOGOUT_URL` to a full URL if you use external auth and want "Log out" to redirect there. Leave empty for default Firefly III logout.

---

### Analytics (Matomo)

To track your own usage with Matomo, set `TRACKER_SITE_ID` and `TRACKER_URL` (no `http://` or `https://`). Only Matomo is supported.

---

### IP geolocation (optional)

Set `IPINFO_TOKEN` to an [ipinfo.io](https://ipinfo.io) access token if you want IP-to-location resolution. Leave empty otherwise.

---

### Summary: minimum for "feature complete" local use

- **Must have (you already did):** `APP_KEY`, `DB_*`, `APP_URL`, `STATIC_CRON_TOKEN`, Passport keys generated via artisan.
- **Recommended:** Set `MAIL_FROM` to a real address and configure `MAIL_*` (or keep `MAIL_MAILER=log` if you're fine with log-only).
- **Optional:** Maps (`MAP_DEFAULT_*`), Redis, TRUSTED_PROXIES, audit/Papertrail, Matomo, IPINFO_TOKEN, custom logout, remote-user guard — only if you use those features.

Everything else in `.env` can stay as-is unless you have a specific need.
