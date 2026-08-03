# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Current Phase Status

**Phase 2 — Backend API Development (Week 2 of 6 weeks)**

### ✅ Completed - All Major Deliverables
- Six-module domain structure (Registration, Payment, Ticketing, Notification, CheckIn, Reporting, Shared)
- All 26 migrations with seeders and realistic factories
- Eloquent models with relationships and observers
- RBAC with roles, permissions, and policies (spatie/laravel-permission)
- Sanctum authentication for all three guards (web-admin, attendee, scanner)
- REST API v1 route structure (public, attendee, admin, scanner, webhooks)
- API Resources for all major entities
- Horizon queue configuration with four lanes
- CI pipeline (Pint, PHPStan level 8, tests, composer audit)
- OpenAPI documentation controller and basic spec
- Idempotency middleware and handling
- Activity logging infrastructure
- State machine implementation (HasStateMachine trait) - integrated across all models
- All controller actions implemented (no stubs remaining)
- Fake gateway drivers (FakeGateway, FakeSmsDriver, FakeEmailDriver, FakeWhatsAppDriver)
- TOTP 2FA for staff (TwoFactorAuthenticationService + controller)
- Comprehensive test coverage (83 tests passing, including domain logic tests)

### ✅ Recent Wins
- Fixed 2 failing concurrency tests - now all 83 tests passing (100% pass rate)
- Atomic reservation and admission mechanisms verified working correctly
- All Phase 2 core deliverables completed ahead of schedule

### 📋 Exit Criteria (from docs/08-development-roadmap.md)
- [ ] Registration → payment → ticketing → admission flow works end-to-end in tests
- [x] Concurrency tests pass (300 purchases against 100 capacity, 20 concurrent scans)
- [ ] Every permission has passing allow-case and deny-case tests
- [ ] OpenAPI spec published and reviewed by frontend lead

### 🚨 External Dependencies (start during Phase 2!)
- [ ] Payment gateway merchant applications (bKash, Nagad, Rocket, SSLCommerz) — **2-6 weeks lead time**
- [ ] WhatsApp Business template approval
- [ ] SMS vendor contract and sender ID
- [ ] Domain registration and email SPF/DKIM/DMARC setup

---

## Repository layout

This repo is a single Laravel application at the repo root. `.github/workflows/backend-ci.yml` runs lint/static-analysis/tests against the repo root.

## Commands

**PHP dependencies / setup**
```bash
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate
```

**Run the full dev stack** (server + queue listener + logs + Vite, concurrently):
```bash
composer dev
```

**Tests** — full suite:
```bash
composer test
# or
php artisan test
```
Single test file / single test method:
```bash
php artisan test tests/Feature/Auth/AdminAuthTest.php
php artisan test --filter=test_method_name
```
Tests require a **real MySQL** database, not SQLite (see `phpunit.xml`): migrations use `utf8mb4_0900_ai_ci` collation, `VARBINARY` IP columns, and `STORED` generated columns that SQLite can't represent. Point `DB_DATABASE=decent_event_testing` at a running MySQL 8 instance before running tests.

**Static analysis** (PHPStan/Larastan, level 8, config in `phpstan.neon`):
```bash
./vendor/bin/phpstan analyse
```

**Lint / format** (Laravel Pint):
```bash
./vendor/bin/pint          # fix
./vendor/bin/pint --test   # check only, as run in CI
```

**Frontend build** (Vite, for the Blade/asset pipeline in this repo — the separate Next.js public/admin apps described in the docs are not part of this repo):
```bash
npm run dev
npm run build
```

## Architecture

Full design docs live in `docs/` (`01`–`08`, start at `docs/README.md`). Read the relevant doc before making non-trivial changes to a subsystem — the ADRs in `docs/README.md` explain *why*, and getting them wrong breaks correctness at the gate on event day, not just a test. The load-bearing decisions:

- **Modular monolith.** Laravel organized into six domain modules under `app/Domain/`: `Registration`, `Payment`, `Ticketing`, `Notification`, `CheckIn`, `Reporting`, plus `Shared` for cross-cutting concerns (`User`, `EventSetting`, `ActivityLog`, `IdempotencyKey`, and support traits). Modules communicate through events and service interfaces — **never reach into another module's Eloquent models directly**.
- **Layering within a module:** HTTP (`app/Http/Controllers` — thin, no business logic) → FormRequests (validation + `authorize()`) → Actions/domain services in `app/Domain/*/Actions` and `*/Services` → Repositories/Eloquent → async Jobs for anything slow (gateway calls, PDF/QR rendering, SMS/email/WhatsApp). The queue boundary is deliberate: registration and payment must stay fast and transactional; notification delivery and asset generation are allowed to be slow or briefly broken.
- **Money is integer paisa, never float/decimal** (`BIGINT UNSIGNED`, 1 BDT = 100 paisa), with an explicit `currency` column. Never introduce a decimal money column.
- **Public-facing identifiers are ULIDs**; auto-increment BIGINT PKs stay internal only. Routes use `{model:ulid}` binding (see `routes/api/*.php`).
- **Tickets are immutable once issued.** Corrections are void + reissue (`replaces_ticket_id` chain), never edit/delete.
- **State transitions go through `HasStateMachine`** (`app/Domain/Shared/Support/HasStateMachine.php`): a model defines a `TRANSITIONS` constant (`['from' => ['to', ...]]`) and calls `transitionTo()`; illegal transitions throw `InvalidStateTransitionException`. Don't mutate a `status` column directly — use this instead. Permitted maps are specified in `docs/04-erd.md` §4.7.
- **QR tickets are Ed25519-signed**, not database-lookup tokens; scanner devices hold only the public key plus a signed revocation manifest, so admission decisions work fully offline. Admission counting uses an atomic conditional `UPDATE ... WHERE admitted_count + :n <= admits_total` — never SELECT-then-INSERT — to stay race-safe under concurrent gate scans.
- **RBAC:** `spatie/laravel-permission` under the `web-admin` guard, catalogue seeded from the versioned `config/rbac.php` (never created ad hoc — this is what keeps staging/production provably in sync). Code must check **permissions** (`payment.verify_manual`), never role names. Staff (`users`) and attendees (`attendees`) are separate identity domains/guards — do not conflate them. Volunteer (`scanner` guard) access is additionally scoped server-side by enrolled device, assigned gate, and the check-in time window — see `docs/02-rbac-permissions.md` §2.4 for the full authorization flow (permission check **and** model policy must both pass).
- **Routes** are split by audience under `routes/api/`: `public.php` (unauthenticated browse/register), `attendee.php` (attendee self-service, `auth:attendee`), `admin.php` (staff console, `auth:web-admin`), wired together in `routes/api/v1.php`; plus `routes/scanner.php` (volunteer devices) and `routes/webhooks.php` (payment gateway IPNs). `routes/api.php` just mounts `v1.php` under the `api/v1` prefix.
- **Payments:** four gateways (bKash, Nagad, Rocket, SSLCommerz) behind one adapter contract (`createIntent`, `verify`, `refund`, `parseWebhook`) under `app/Domain/Payment/Gateways/`. A browser return-callback is **never** trusted to mark a payment succeeded — only a server-to-server verify call or a signature-validated IPN can do that. `payments` (the money intent) and `payment_transactions` (every gateway interaction, append-only) are deliberately separate tables — don't collapse them.
- **Notifications** go through a database outbox (`notifications`/`notification_events`) written in the same transaction as the triggering business event, then drained by queue workers via provider-agnostic channel drivers (Email/SMS/WhatsApp). Don't call a notification provider directly from request-handling code.

Queue lanes (Horizon) are named by urgency — `payments` (<5s), `tickets` (<30s), `notifications` (<60s), `reports` (minutes) — keep jobs on the queue that matches their latency budget, since notification volume must never delay a payment webhook.

## Development conventions

Follow these so new code matches what's already in `app/` and passes CI (Pint, PHPStan level 8, `composer audit`, tests).

- **Use the shared traits instead of hand-rolling behavior.** New Eloquent models: `HasUlid` (public `ulid` route key — every model reachable by URL/API/QR needs this, not the auto-increment `id`), `HasImmutableCreatedAt` for append-only tables (no `updated_at`, never call `->update()` on these rows), `HasStateMachine` for anything with a `status` column (define a `TRANSITIONS` const, call `transitionTo()`, never assign `status` directly).
- **PHPStan runs at level 8** (`phpstan.neon`) — use the modern `casts()` method, not the legacy `$casts` property, and keep everything fully typed (params, returns, PHPDoc generics for collections/relations).
- **Money is `BIGINT UNSIGNED` paisa** on every monetary column, with an explicit `currency` column alongside it. Never add a `decimal`/`float` money column, never compare amounts across currencies without an explicit check.
- **Idempotency is mandatory on unsafe operations**, not optional hardening: payment intents, gateway webhooks, ticket issuance, and scanner sync all need an `idempotency_key` with a unique index (see `App\Domain\Shared\Models\IdempotencyKey`, `isInFlight()`). A retried webhook or a double-tapped button must produce one effect.
- **A payment only reaches `succeeded` via server-to-server gateway verification or an authenticated Event Manager approving a manual payment** — never from a browser return-callback. If you touch payment code, this invariant is the one thing that must never regress.
- **Log sensitive actions to `activity_logs`**: refunds, voids, manual check-in overrides, role/permission changes, key rotation, exports, impersonation. Record actor, subject, before/after diff, IP, and `request_id`. These rows are append-only — no update/delete path.
- **API responses use explicit field allowlists (API Resources), never blocklists.** A Volunteer-facing resource must never contain `email`, `mobile`, or money fields — omit them from the resource, don't filter them at render time. Errors use the uniform shape `{ code, message, errors?, request_id }`; never leak stack traces or confirm resource existence to an unauthorized caller (404, not 403, for "not yours").
- **Authorization is always two-stage**: a permission check (`$user->can('payment.refund')`, catalogue in `config/rbac.php`) *and* a model policy check (`PaymentPolicy::refund()`) — a permission grants the capability in general, the policy decides for that specific record. Check permissions by name, never by role (`if ($user->hasRole(...))` in business logic is wrong).
- **Query-scope by ownership at the builder, not just in the controller** — e.g. an attendee's own-resource queries should filter `attendee_id = auth()->id()` in the query itself, so a missed controller check can't leak another attendee's data.
- **Rate limits are part of the spec, not an afterthought** — see `docs/06-security-architecture.md` §6.7 for the per-route-class limits (OTP/magic-link requests are throttled primarily to control SMS/email cost, not just abuse).
- **File uploads are never trusted on extension or client `Content-Type`** — validate by magic bytes, re-encode images (strips both malicious payloads and EXIF/GPS), store private with a randomized filename, serve only via short-TTL signed URLs.
- **Seed data belongs in versioned config/seeders, not ad hoc.** RBAC roles/permissions are seeded from `config/rbac.php` via `RbacSeeder` — add new permissions there, not directly through Spatie's API. `EventSettingSeeder` / `TicketTypeSeeder` follow the same pattern; extend them rather than creating rows manually in migrations.
- **Test every permission with the two-case pattern**: one authorized actor succeeds, one unauthorized actor gets denied (see `tests/Feature/Rbac/PermissionCatalogueTest.php`). New permissions in `config/rbac.php` should get a matching pair of assertions. Target ≥80% coverage on domain logic (`app/Domain/**`).
- **Module boundary discipline**: don't import another domain's Eloquent model directly from outside its module — go through its published events or a service interface. If a new feature needs data from another module, that's a signal to add an event/listener, not a cross-module query.
