# 09 — Hostinger (hPanel) deployment setup

What has to be configured **outside this repo** for `.github/workflows/backend-ci.yml`'s
deploy job to produce a working site. The workflow uploads code, migrates and rebuilds
caches; everything below is state that lives on the host and is never uploaded.

> **Not verified against a live account.** The domains below are this project's real ones,
> but nobody has run this setup end to end — there is no hPanel or SSH access from the
> development environment, so every item says what to check rather than asserting it works.
> hPanel also renames its menus periodically, so treat the navigation paths as "look for
> something called roughly this".

---

## The two origins, and how they are wired to each other

Two separate applications on two subdomains. Mixing them up is the failure that produces a
site which looks fine and cannot take a payment.

| Origin | Application | Repo |
|---|---|---|
| `https://100potal.nsbatihighschool.edu.bd` | Laravel API + admin console — **this repo** | `decent-event-management` |
| `https://100.nsbatihighschool.edu.bd` | Public ticket site (Next.js) | `centennial-celebration` |

Each has to be told the other's address, and the four keys below are the whole contract:

**On the backend host** (`.env`, §4):

```dotenv
APP_URL=https://100potal.nsbatihighschool.edu.bd
FRONTEND_URL=https://100.nsbatihighschool.edu.bd
CONTENT_REVALIDATE_URL=https://100.nsbatihighschool.edu.bd/api/revalidate
CONTENT_REVALIDATE_SECRET=            # same value both sides
```

**On the public site** (`.env`, that repo):

```dotenv
NEXT_PUBLIC_API_URL=https://100potal.nsbatihighschool.edu.bd
CONTENT_REVALIDATE_SECRET=            # same value both sides
```

`NEXT_PUBLIC_API_URL` is the **bare origin** — `src/lib/api/client.ts` appends `/api/v1`
itself, so a trailing `/api/v1` here produces `…/api/v1/api/v1` and a 404 on every call.

`FRONTEND_URL` is the load-bearing one on this side, because five separate things read it
and none of them accept a client-supplied alternative:

- **CORS** (`config/cors.php`) allowlists exactly this origin. Wrong value and the public
  site's every request fails in the browser with no server-side error to find.
- **The SSLCommerz return legs** redirect the payer here after checkout, and
  `SslCommerzReturnController` refuses any `next` whose host does not match — deliberately,
  so a crafted request cannot turn the return into an open redirect.
- **The payment callback URL** (`PaymentController::initiate`) is built from it server-side.
- **The ticket email's CTA** links to `{FRONTEND_URL}/registrations/{ulid}`.
- **CMS preview links** minted by the admin console.

Set it wrong and payments still take money at the gateway while the payer lands nowhere.

---

## 0. Read this before configuring anything

This application was designed for the topology in [docs/07 §7.3](07-scalability-plan.md):
a managed database, Redis, dedicated queue workers, and headless Chrome. Shared hosting
provides none of those. It will still serve the site, but three subsystems change or stop:

| Subsystem | What it assumes | On shared hosting |
|---|---|---|
| Queues | Redis + Horizon supervisors (`config/horizon.php` hardcodes `'connection' => 'redis'` on all four lanes) | **Horizon cannot run.** Switch to the `database` queue driver and drain it from cron (§5) |
| Cache + sessions | Redis (`.env.example` defaults all three to `redis`) | Use the `database` driver — the `cache` and `sessions` tables already exist in the migration set |
| PDF rendering | Headless Chrome (`config/pdf.php`) | **Ticket PDFs and the attendee directory export will fail** unless a Chromium binary exists (§7) |
| `/up` health check | Probes database, Redis **and** disk | Reports 500 while Redis is absent, even when the site is fine (§7) |

If the plan is a VPS rather than shared hosting, most of this collapses: install Redis,
run Horizon under systemd, install chromium, and only §1–§4 still apply.

---

## 1. Document root

Laravel serves from `public/`, not the project root. Everything above it — `.env`,
`storage/`, `vendor/` — must not be reachable over HTTP.

Two workable shapes:

- **Preferred:** deploy to `~/domains/100potal.nsbatihighschool.edu.bd/app`, then point the domain's document root
  at `~/domains/100potal.nsbatihighschool.edu.bd/app/public` (hPanel: *Websites → Manage → Advanced*, look for a
  document-root or website-root setting).
- **If the plan will not let you move the document root:** deploy to `~/domains/100potal.nsbatihighschool.edu.bd/app`
  and symlink `ln -s ~/domains/100potal.nsbatihighschool.edu.bd/app/public ~/domains/100potal.nsbatihighschool.edu.bd/public_html`. Delete
  the existing `public_html` first — a symlink cannot replace a non-empty directory.

Whichever you pick, `HOSTINGER_DEPLOY_PATH` (repo secret) is the **project root**, not the
document root. Pointing it at `public_html` uploads the whole framework into a
web-served directory and exposes `.env`.

**Verify:** `curl -sI https://100potal.nsbatihighschool.edu.bd/.env` must return 403 or 404, never 200.

---

## 2. PHP version and extensions

*hPanel: Advanced → PHP Configuration.*

- **Version: 8.3 or newer**, set for the domain. `composer.json` requires `^8.3`, and 8.2
  fails at composer's platform check before Laravel boots. This is separate from the CLI
  PHP the deploy uses — the workflow resolves that itself (see `HOSTINGER_PHP_BIN` below).
- **Extensions**, beyond the PHP defaults:

  | Extension | Needed by |
  |---|---|
  | `sodium` | `QrSigner` — Ed25519 ticket signing. Nothing issues a ticket without it |
  | `gd` | QR PNGs, media thumbnails, attendee-export photos |
  | `zip`, `xml`, `simplexml`, `xmlwriter` | `phpoffice/phpspreadsheet` (the .xlsx export) |
  | `pdo_mysql`, `mbstring`, `openssl`, `curl`, `fileinfo`, `tokenizer`, `ctype`, `intl`, `bcmath` | framework and gateway clients |
  | `pcntl`, `posix` | Horizon only — skip if you are using the cron worker in §5 |

  `predis/predis` is the Redis client, so **no `redis` extension is required** even if you
  do add a Redis server later.

**Verify:** `ssh` in and run `<php-binary> -m` — compare against the table.

**Also set the CLI path as a repo variable** if the deploy cannot find an 8.3+ binary.
Find it with `ls -d /opt/alt/php*/usr/bin/php`, then set `HOSTINGER_PHP_BIN` under
*Settings → Secrets and variables → Actions → **Variables*** (a path, not a secret).

---

## 3. Database

*hPanel: Databases → MySQL Databases.* Create a database and a user, and grant that user
everything on it.

- **MySQL 8.0+ is required, not MariaDB.** The migrations use `utf8mb4_0900_ai_ci`
  collation and `STORED` generated columns (`qr_signing_keys` relies on a generated column
  plus a unique index to make two active signing keys impossible). MariaDB will fail the
  migration rather than silently differ — but confirm the engine before committing to a
  plan, because there is no workaround short of rewriting migrations.
- Note the host: shared plans usually want `localhost`, not `127.0.0.1`.

**Verify:** `SELECT VERSION();` reports 8.x.

---

## 4. The `.env` file

**The deploy never uploads one** — `.env` is excluded from the rsync on purpose, because
it holds credentials and because `--delete` would otherwise destroy the server's copy.
Create it once, by hand, in the project root.

```dotenv
APP_NAME="Decent Event Management"
APP_ENV=production
APP_DEBUG=false
APP_KEY=                      # php artisan key:generate
APP_URL=https://100potal.nsbatihighschool.edu.bd
APP_TIMEZONE=Asia/Dhaka

# Shared hosting has no Redis. These three all default to redis in
# .env.example; the cache/sessions/jobs tables already exist in the
# migration set, so the database driver needs no extra work.
#
# The deploy repairs these three itself if it finds them set to redis
# with nothing listening -- it backs the file up to .env.backup-<stamp>
# first and prints what it changed. Setting them here is still better:
# the repair only runs after a deploy has already reached the host.
CACHE_STORE=database
SESSION_DRIVER=database
QUEUE_CONNECTION=database

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=
DB_USERNAME=
DB_PASSWORD=

FILESYSTEM_DISK=local

# Ticket signing. Generate ON THE SERVER (step 6) — never reuse a
# development key, and never commit one.
QR_SIGNING_KEY_ID=
QR_SIGNING_PRIVATE_KEY=

# The public site's origin — see "The two origins" above. CORS, the
# payment return legs, the ticket email's CTA and CMS preview links all
# read it server-side, never from a request.
FRONTEND_URL=https://100.nsbatihighschool.edu.bd

# The public site's ISR revalidation endpoint, and the shared secret it
# checks. A no-op until both are set, so the CMS is usable before the
# public site is deployed.
CONTENT_REVALIDATE_URL=https://100.nsbatihighschool.edu.bd/api/revalidate
CONTENT_REVALIDATE_SECRET=

MAIL_MAILER=smtp
MAIL_HOST=
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_FROM_ADDRESS=
MAIL_FROM_NAME="${APP_NAME}"

# Payments. Sandbox until the merchant applications land (Phase 4B).
SSLCOMMERZ_STORE_ID=
SSLCOMMERZ_STORE_PASSWORD=
SSLCOMMERZ_SANDBOX=true

# Headless Chrome for ticket PDFs — see §7. Leave blank if unavailable
# and expect PDF generation to fail.
CHROME_BINARY=
```

SMS credentials are **not** listed here deliberately: since 2026-08-22 the REVE api key,
secret key and sender ID are set from *Settings → SMS gateway* in the admin console and
stored encrypted, and a database value beats the env one. Leaving `REVESMS_*` unset is the
intended state.

`chmod 600 .env`.

**Verify:** `<php> artisan tinker --execute="echo config('app.env');"` prints `production`.

---

## 5. Cron jobs

*hPanel: Advanced → Cron Jobs.* Two entries. Substitute your PHP binary and project path.

**a. The scheduler** — one entry runs everything in `routes/console.php`:

```
* * * * * /opt/alt/php83/usr/bin/php /home/uXXXXXXXX/domains/100potal.nsbatihighschool.edu.bd/app/artisan schedule:run >> /dev/null 2>&1
```

This drives: payment-intent expiry (every 5 min — without it, abandoned checkouts hold
seats forever), SMS delivery-receipt polling (every 5 min — without it, no message ever
leaves `sent`), event reminders (08:00), settlement reconciliation (02:00), and the
database backup (03:00).

> **If your plan enforces a minimum interval longer than one minute**, the `dailyAt` tasks
> silently stop firing — Laravel runs what is due *in that minute*, so a 5-minute cron only
> catches a task scheduled at `08:00` if it happens to run exactly then. Check the minimum
> the plan allows; if it is not 1 minute, the daily schedules need rewriting to windows
> that align with it, which is a code change in `routes/console.php`.

**b. A queue worker** — required, because Horizon cannot run here and nothing else drains
the outbox:

```
* * * * * /opt/alt/php83/usr/bin/php /home/uXXXXXXXX/domains/100potal.nsbatihighschool.edu.bd/app/artisan queue:work --queue=payments,tickets,notifications,reports --stop-when-empty --max-time=55 --tries=3 >> /dev/null 2>&1
```

- Queue order **is** priority under the database driver — payments first, reports last,
  matching the latency budgets in CLAUDE.md's Horizon lanes.
- `--stop-when-empty` plus `--max-time=55` makes each run exit before the next minute
  starts, so runs cannot pile up. Do not drop either flag.
- Without this: notifications never send, ticket PDFs and QR images never render, and CMS
  revalidation never fires. The site looks healthy while nothing asynchronous happens.

**Verify:** register a test attendee, then check `notifications.status` moves off `queued`
within ~2 minutes, and that the ticket gets a `pdf_media_id`.

---

## 5a. Recovering a half-applied migration, without a shell

A migration that throws partway leaves its tables behind — MySQL does not roll DDL back —
and writes no `migrations` row recording them, so every retry dies on *"table
`permissions` already exists"*. This is not hypothetical: the first deploy here hit it,
because Spatie's permission migration creates five tables and only then flushes the
permission cache, which failed against a Redis that was not there.

Clearing it needs the tables dropped. If you have SSH:

```bash
cd ~/domains/100potal.nsbatihighschool.edu.bd/app
<php-binary> artisan migrate:fresh --force
```

If you do not, the workflow can do it: **Actions → CI/CD → Run workflow**, tick
**reset_database**, run. It is a `workflow_dispatch` input only, so a push can never
trigger it.

> **It drops every table.** Only ever use it on a deployment that has never carried real
> registrations or payments — which is to say, during first-deploy setup and never again.
> Afterwards you must re-run the seeders in §6.

---

## 6. One-time setup on the server, after the first successful deploy

The rsync excludes `storage/`, so on a fresh host that tree does not exist and Laravel
cannot boot. Create it once:

```bash
cd ~/domains/100potal.nsbatihighschool.edu.bd/app
mkdir -p storage/app/public storage/app/private \
         storage/framework/{cache/data,sessions,views} storage/logs
chmod -R 775 storage bootstrap/cache

php artisan key:generate            # only if APP_KEY is still blank
php artisan qr-signing:generate-key --if-missing   # writes its own keypair into .env
php artisan migrate --force
php artisan storage:link

# Seeders: the deploy runs none, deliberately. These are required once.
php artisan db:seed --class=RbacSeeder
php artisan db:seed --class=EventSettingSeeder
php artisan db:seed --class=TicketTypeSeeder
php artisan db:seed --class=NotificationTemplateSeeder
```

- `RbacSeeder` is not optional — the permission catalogue only exists in a database once
  it has run, and without it **every caller including Super Admin gets a 403**.
- `TicketTypeSeeder` seeds the centennial current-student price at ৳500, which is a
  carried-over placeholder, not a client decision. Set the real figure before or after.
- **Do not** run `DummyDataSeeder` or `LoadTestSeeder` on production.
- Re-running the four above is safe: both seeders that own admin-editable values
  (`EventSettingSeeder`, `NotificationTemplateSeeder`) only fill rows that do not exist yet.

---

## 7. What does not work here, and what to do about it

**Headless Chrome / PDFs.** `GenerateTicketPdf` and the attendee-directory export shell out
to Chromium (`config/pdf.php`) because mpdf silently dropped Bengali conjuncts from the
extractable text layer. Shared hosting will not let you install it. Options, worst to best:

1. Check whether one already exists: `ls /usr/bin/chromium* /usr/bin/google-chrome* /opt/**/chrome 2>/dev/null`. If so, point `CHROME_BINARY` at it.
2. Upload a static Chromium build to `~/bin` and point `CHROME_BINARY` at it — plausible, unverified, and it may be killed by the plan's memory limit (a render peaks around 15 MB of PHP but Chrome itself wants far more).
3. Move PDF rendering to a VPS or a rendering service.

Until one of those, ticket issuance still works — the QR is signed and the email carries it
as an inline image — but the **PDF download and both export formats fail**.

**`/up` — fixed, nothing to configure.** `CheckApplicationHealth` used to probe Redis
unconditionally, so a host with none reported unhealthy forever while serving every
request perfectly well. It now probes Redis only when the active cache store, queue
connection or session driver actually resolves to it, which for a `.env` written per §4
means never. Database and disk are still checked on every hit.

**Horizon's dashboard is dead weight.** `/horizon` will render but show nothing, because
nothing is supervising Redis queues. The cron worker in §5 has no dashboard; use the admin
console's notification delivery log to see whether work is draining.

**Backups.** The nightly `db:backup` writes a **gzip, not an encrypted archive**, to local
disk on the same box as the database — which is not a backup in any sense that survives
losing the box. Ship it somewhere else, and run `db:restore --verify` against one
periodically. Neither is automated.

---

## 8. Verification checklist after the first green deploy

- [ ] `curl -sI https://100potal.nsbatihighschool.edu.bd/.env` → 403/404, not 200
- [ ] `curl -s https://100potal.nsbatihighschool.edu.bd/api/v1/public/ticket-types` → JSON with the `CEN` type
- [ ] Admin console loads at `/login` and a seeded Super Admin can sign in
- [ ] A test registration reaches `pending_payment` and returns a real SSLCommerz redirect URL
- [ ] Within ~2 minutes of a paid registration: `notifications` row leaves `queued`, and the ticket has a QR image
- [ ] `php artisan schedule:list` shows all five scheduled commands
- [ ] `storage/logs/laravel.log` is being written and is not world-readable over HTTP

---

## Related

- CLAUDE.md, *Phase 9 — Production Deployment* — what shipped, and the hosting decision this doc assumes has now been made
- [docs/07 §7.3](07-scalability-plan.md) — the topology this app was designed for
- [docs/06 §6.7](06-security-architecture.md) — security headers and rate limits, applied in code
- `.github/workflows/backend-ci.yml` — the deploy job these settings serve
