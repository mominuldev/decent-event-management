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

### The layout this deploy is built for

```
/home/u804311556/domains/100potal.nsbatihighschool.edu.bd/
├── laravel/                 <- HOSTINGER_DEPLOY_PATH. Never served by Apache.
│   ├── .env
│   ├── artisan
│   ├── app/
│   ├── vendor/
│   ├── storage/
│   └── public/              <- source of what gets published below
└── public_html/             <- HOSTINGER_PUBLIC_PATH. The document root.
    ├── index.php
    ├── .htaccess
    ├── build/               <- Vite assets
    └── storage -> ../laravel/storage/app/public
```

The framework is outside the web root **as a matter of filesystem layout**, not because a
rewrite rule says so. That is the point of it: the previous shape put the project root *at*
the document root and relied on a single `.htaccess` to keep `.env`, `vendor/`, `app/` and
`artisan` from being served directly — and that file kept disappearing, at which point all
four answered **200** to anyone who asked.

Two repo secrets drive it:

| Secret | Value | What it is |
|---|---|---|
| `HOSTINGER_DEPLOY_PATH` | `/home/u804311556/domains/100potal.nsbatihighschool.edu.bd/laravel` | the Laravel project root — the directory holding `artisan` and `.env` |
| `HOSTINGER_PUBLIC_PATH` | `/home/u804311556/domains/100potal.nsbatihighschool.edu.bd/public_html` | the document root, left exactly where hPanel expects it |

`HOSTINGER_PUBLIC_PATH` may equally be a repository *variable* rather than a secret (it is a
path, not a credential); the workflow reads the secret first and falls back to the variable.

The deploy then does two rsyncs. The first uploads the repository into `laravel/`. The
second runs **on the host**, between two local directories, and copies only the contents of
`laravel/public/` into `public_html/` — so nothing is uploaded twice, and no part of the
framework ever lands in a directory Apache serves.

**Setting up an existing host that currently has the app in `public_html`:**

```bash
cd ~/domains/100potal.nsbatihighschool.edu.bd
mkdir -p laravel
mv public_html/.env laravel/.env          # the only file you cannot get back from git
mv public_html/storage laravel/storage    # uploaded media, ticket PDFs, logs
chmod 600 laravel/.env
```

Then set both secrets and push. The first deploy uploads the tree into `laravel/`, migrates,
and publishes `public/` over `public_html/` — which **deletes everything else in there**,
including the old copy of the application. Take a `db:backup` first, and confirm
`laravel/.env` is in place before you start, because the migrate step refuses without it and
that refusal is what stops a misconfigured run from reaching the delete.

### The two older shapes still work

Leave `HOSTINGER_PUBLIC_PATH` unset and the publish step skips entirely, so nothing about an
existing deployment changes:

- **Document root moved:** deploy to `.../app`, point the domain's document root at
  `.../app/public` (hPanel: *Websites → Manage → Advanced*, look for a document-root or
  website-root setting). Cleanest of the three, and unavailable on some plans.
- **`public_html` symlinked:** `ln -s .../app/public .../public_html`. Delete the existing
  `public_html` first — a symlink cannot replace a non-empty directory.

Both serve `public/` directly, so there is nothing to publish; if `HOSTINGER_PUBLIC_PATH`
is set anyway the step notices the two paths resolve to the same directory and skips rather
than syncing a directory over itself.

What is **not** a supported shape is `HOSTINGER_DEPLOY_PATH` pointing at `public_html`. It
uploads the whole framework into a web-served directory, and the publish step refuses to run
at all in that case rather than `--delete`-ing the application it would be publishing from.

**Verify:** `curl -sI https://100potal.nsbatihighschool.edu.bd/.env` must return 403 or 404, never 200.
Under the split layout there is no `.env` under the document root to serve at all.

### "Base table or view already exists: 1050"

If a deploy dies on

```
2026_08_02_195127_create_permission_tables ... FAIL
SQLSTATE[42S01]: Base table or view already exists: 1050 Table 'permissions' already exists
```

the database is **half-applied**, and it happened on an earlier run than the one reporting it.

Spatie's permission migration ends its `up()` with a cache flush, *after* all five of its
`Schema::create` calls:

```php
app('cache')->store(...)->forget(config('permission.cache.key'));
```

On a host whose `.env` names a cache store that is not reachable — `CACHE_STORE=redis` on
shared hosting, which is the default this repo ships — that throws. MySQL does not roll DDL
back and Laravel records a migration only after `up()` returns, so the five tables survive with
no `migrations` row naming them. Every run from then on dies re-creating `permissions`.

**Both halves are handled by the deploy now, and neither needs a shell:**

- **Prevention.** `migrate` runs with `CACHE_STORE=array`, so that last statement flushes an
  in-memory store and can no longer reach anything able to fail. It applies to the migrate
  commands only — `config:cache` afterwards uses the host's real values.
- **Repair.** `php artisan migrate:repair-permission-tables` runs before `migrate` on every
  deploy and is a no-op on a healthy database. Where the tables exist and the migration is
  unrecorded, it records it — reaching the cache flush at all means every create succeeded, so
  the schema is complete and there is nothing to rebuild. It records nothing and fails loudly
  if only *some* of the tables exist, because declaring a partial schema applied moves the
  eventual error further from its cause.

Verified against a real MySQL rather than reasoned about: with an unreachable cache store the
migration leaves `permissions` present and unrecorded and the next run reproduces the 1050
above; the repair records it and migrations run to completion; and on a clean database with the
same unreachable store, `CACHE_STORE=array` applies the migration normally.

`reset_database` remains for what the repair deliberately will not touch — a genuinely
half-created schema — and still drops every table, so it is a last resort on a database holding
real registrations.

### Email that reaches the inbox, not the spam folder

Mail sending and mail *arriving* are different problems. Once `MAIL_MAILER=smtp` works
(`php artisan mail:test you@example.com` succeeds), everything below is DNS and identity — no
amount of application code substitutes for it, and until it is in place a reset link landing in
junk is the expected outcome rather than a fault.

**1. The From address must belong to the site's own domain, and to the mailbox that
authenticates.** Two separate rules that are easy to satisfy by halves:

```dotenv
MAIL_USERNAME=no-reply@nsbatihighschool.edu.bd     # the mailbox that authenticates
MAIL_FROM_ADDRESS=no-reply@nsbatihighschool.edu.bd  # must be the same address
MAIL_FROM_NAME="NHS Centennial"
```

Hostinger rejects a `From` the authenticated mailbox does not own, so a mismatch fails at send
time and is easy to notice. The subtler failure is a `From` on *some other* domain — a personal
one, or the default `hello@example.com` — which sends fine and then fails DMARC alignment at the
recipient, because the domain being authenticated is not the domain in the `From` header. That
one is invisible from this side and lands in spam every time.

**2. Publish SPF, DKIM and DMARC for that domain.** All three are DNS TXT records; two of them
Hostinger generates for you (*hPanel → Emails → the domain → DNS / Email deliverability*, names
vary).

| Record | Where | What it does |
|---|---|---|
| SPF | `@` | Names the servers allowed to send as this domain. One SPF record only — merge, never add a second |
| DKIM | the selector host Hostinger gives you | Signs each message so the recipient can prove it was not altered |
| DMARC | `_dmarc` | Tells the recipient what to do when SPF/DKIM fail, and asks for reports |

Start DMARC at `p=none` while you check the reports, then tighten:

```
v=DMARC1; p=none; rua=mailto:dmarc@nsbatihighschool.edu.bd; fo=1
```

**3. Verify rather than assume.** Send to a Gmail address, open the message, *Show original*: SPF,
DKIM and DMARC must all read **PASS**. <https://www.mail-tester.com> scores the same things out of
10 and names what is missing — a fresh domain with all three records typically lands 8–10, and
without them 4–5, which is squarely in spam territory.

**4. A brand-new sending domain has no reputation.** The first bulk run — 12,000 ticket
confirmations — from a domain that has never sent anything is itself a spam signal, whatever the
DNS says. Send staff mail through it for a few days first, and if the event blast matters more
than the cost, a transactional provider (Postmark, SES, Resend — all already scaffolded in
`config/services.php`) carries its own reputation and is what those services are for.

**5. What the application already does about it.** `StaffPasswordResetMail` sends
`multipart/alternative` — HTML *and* a real plain-text part — because an HTML-only message is a
long-standing spam signal. ⚠️ **`NotificationMail` (the shell every attendee email uses,
including the ticket confirmation) is still HTML-only.** That is the same defect at 12,000 times
the volume, and it wants a text alternative before the ticket blast goes out.

### Server-only files and `rsync --delete`

The deploy syncs with `--delete`, so **anything on the host that this repo does not track is
removed on every deploy** unless the workflow names it. That is what `.env`, `storage/` and
`public/storage` are excluded for — and, since 2026-08-31, what these protect:

```
--filter='protect .htaccess'
--filter='protect .well-known/***'
--filter='protect .user.ini'
```

`protect` is not the same as `exclude`, and the difference is the whole point. An exclude
skips sending a path *and* spares it from deletion; `protect` spares it from deletion while
still letting the repo's own copy be sent. `.htaccess` needs exactly that split:

| File | Where it comes from | What must happen |
|---|---|---|
| `public/.htaccess` | tracked in this repo — Laravel's front-controller rewrite | keep deploying it |
| the document root's `.htaccess` | the host (Hostinger's PHP handler block, and the rewrite into `public/` if the document root could not be moved) | never delete it |

`--exclude=".htaccess"` would have protected the second by breaking the first, and a deploy
that stops shipping `public/.htaccess` takes the site down a different way.

**This mattered most in the shape where `HOSTINGER_DEPLOY_PATH` is the document root.** There
the host's `.htaccess` is the only thing rewriting requests into `public/`, so deleting it did
not merely break routing — it left `.env`, `vendor/`, `app/` and `storage/` sitting in a
directory Apache was serving directly. Moving the document root to `…/app/public` (above) is
the real fix for that; the protect filters stop the deploy from causing it.

**And since 2026-09-02 the deploy puts one back.** `protect` only stops *this* workflow
deleting the file — the run logs show it has not deleted one since the filters landed — so a
document root that keeps losing its `.htaccess` is losing it to something else: hPanel, an
FTP client, a file manager, another deploy mechanism. The remote script therefore copies
`deploy/hostinger/document-root.htaccess` into place **when the file is absent**, and never
touches one that is already there, so an hPanel-generated PHP handler block or a hand-added
rule survives every deploy.

That tracked copy sends *everything* into `public/`:

```apache
RewriteRule ^public/ - [L]
RewriteRule ^(.*)$ public/$1 [L]
```

which is stricter than the usual `!-f`/`!-d` recipe on purpose. With the document root set to
the project root, a rewrite that serves existing files first serves `composer.json`,
`artisan`, `vendor/` and `app/` to anyone who asks for them — verified against the live host,
where all four answered **200**. Under this rule they resolve to a path that does not exist
under `public/`, reach `public/.htaccess`, and come back as Laravel's 404.

It is a mitigation, not the fix. **The fix is still moving the document root to `…/public`**
(§1): a rewrite protects the source tree only for as long as the rewrite is there, which is
precisely the thing that keeps disappearing.

`.well-known` is the same failure with a slower fuse: ACME challenge files live there during a
certificate renewal, so deleting them surfaces weeks later as an expired certificate rather
than as a failed deploy.

**The second rsync — publishing `public/` — deletes too, and in the directory Apache serves.**
It runs on the host between two local directories and excludes the same three paths, for the
same reasons:

```
--exclude='.htaccess'        # usually the host's, carrying an hPanel PHP handler block
--exclude='.well-known/'     # ACME challenges
--exclude='.user.ini'        # per-directory PHP settings hPanel may write
```

Everything else in the document root that `laravel/public/` does not carry is removed on every
deploy. That is deliberate — a stale asset from three releases ago being served is a real
failure mode — but it means **the document root is not a place to keep anything**. A file you
put there by hand is gone on the next push.

Two things the publish step does around the excluded `.htaccess`, because excluding it
outright would leave the site broken in one direction or the other:

- **No `.htaccess` at all** → it copies Laravel's `public/.htaccess` in.
- **An `.htaccess` with no front-controller rewrite** (an hPanel default, typically just a PHP
  handler block) → it *appends* Laravel's rules, keeping what was there. Idempotent: a second
  deploy sees the rewrite and does nothing.
- **An `.htaccess` still carrying the old `RewriteRule ^(.*)$ public/$1`** → it fails the
  deploy and says so. Under this layout the document root *is* `public/`, so that rule points
  every request at a directory which no longer exists there and the whole site answers 404.

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
Create it once, by hand, at:

```
/home/u804311556/domains/100potal.nsbatihighschool.edu.bd/laravel/.env
```

That is `HOSTINGER_DEPLOY_PATH/.env` — the directory holding `artisan`, one level *above*
the document root. Nothing serves it, and the migrate step aborts the deploy if it is not
there, so a missing `.env` fails loudly rather than half-deploying.

> **After every `.env` edit, clear and rebuild the config cache**, or the change does
> nothing: `config:cache` writes `bootstrap/cache/config.php` and every later `artisan` and
> every request reads *that*, not the file you edited.
>
> ```bash
> cd /home/u804311556/domains/100potal.nsbatihighschool.edu.bd/laravel
> /opt/alt/php84/usr/bin/php artisan optimize:clear
> /opt/alt/php84/usr/bin/php artisan config:cache
> ```
>
> The deploy does this itself on every release (`optimize:clear`, then `config:cache`,
> `route:cache`, `view:cache`), so this is only for an edit made between deploys.
>
> This is also why application code must read `config('app.url')` and never
> `env('APP_URL')`: `env()` returns `null` once the config is cached, because the cached
> file is loaded *instead of* the `.env`. There are currently **no `env()` calls anywhere in
> `app/`, `routes/` or `database/`** — only in `config/`, where they belong, and in two
> tests, which never run cached. Keep it that way.

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

# Ticket signing. Leave both blank: the deploy runs
# `qr-signing:generate-key --if-missing`, which fills them in on the server
# on the first deploy that finds them empty and never touches them again.
# Never reuse a development key, and never commit one.
#
# If they are somehow blank at runtime, QrSigner::sign() throws and no
# ticket can be issued for any paid registration — the issuance job just
# fails and retries. The payment itself is safe; issuance runs on the
# `tickets` queue, off the transaction that settles the money.
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
* * * * * /opt/alt/php83/usr/bin/php /home/u804311556/domains/100potal.nsbatihighschool.edu.bd/laravel/artisan schedule:run >> /dev/null 2>&1
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
* * * * * /opt/alt/php83/usr/bin/php /home/u804311556/domains/100potal.nsbatihighschool.edu.bd/laravel/artisan queue:work --queue=payments,tickets,notifications,reports --stop-when-empty --max-time=55 --tries=3 >> /dev/null 2>&1
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
cd ~/domains/100potal.nsbatihighschool.edu.bd/laravel
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
cd ~/domains/100potal.nsbatihighschool.edu.bd/laravel
mkdir -p storage/app/public storage/app/private \
         storage/framework/{cache/data,sessions,views} storage/logs
chmod -R 775 storage bootstrap/cache

php artisan key:generate            # only if APP_KEY is still blank
php artisan qr-signing:generate-key --if-missing   # the deploy now does this too; harmless to repeat
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

### The first Super Admin

The deploy runs `admin:create-super-admin --if-missing --no-interaction` on every release, and
with nothing configured that **skips and exits 0** — no account is created and the deploy is
not blocked. It used to pass `--generate-password`, which invented one and printed it into the
build log; that flag is gone, because a build log is readable by everyone with access to this
repository and is retained long after the account is forgotten.

Two ways to get the first account, pick one:

**a. Let the deploy do it** — set both in the host's `.env`, then push:

```dotenv
SUPER_ADMIN_EMAIL=you@example.com
SUPER_ADMIN_PASSWORD=a-long-password-of-12-characters-or-more
```

Delete `SUPER_ADMIN_PASSWORD` once the account exists. It is a plaintext credential for as
long as it sits there, and every later deploy takes the "already exists" branch and never
reads it again — a password you change afterwards, a rename, or a suspension all survive
being deployed over.

**b. Create it by hand**, which needs nothing in `.env` at all:

```bash
cd ~/domains/100potal.nsbatihighschool.edu.bd/laravel
<php-binary> artisan admin:create-super-admin
```

It prompts for the email and password and prints neither.

A malformed `SUPER_ADMIN_*` pair (unparseable email, password under 12 characters) **fails the
deploy** rather than skipping — a blank setting is a decision, a malformed one is a mistake,
and a green deploy that quietly created no administrator is the failure this guards against.

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
- [ ] So do `/artisan`, `/composer.json`, `/vendor/autoload.php` and `/app/Providers/AppServiceProvider.php` — under the split layout none of them exist below the document root at all
- [ ] `ls ~/domains/100potal.nsbatihighschool.edu.bd/public_html` shows `index.php`, `.htaccess`, `build/`, `storage` — and **no** `app/`, `vendor/`, `artisan` or `.env`
- [ ] The admin console's JS and CSS load (the Vite `build/` directory published, not 404ing)
- [ ] An attendee photo loads over `/storage/...` (the published `storage` symlink resolves)
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
