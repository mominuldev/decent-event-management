# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Current Phase Status

**Phase 2 — Backend API Development (Week 2 of 6 weeks) — D1–D4 closed 2026-08-04, sign-off still pending frontend-lead OpenAPI review**

> Reviewed 2026-08-03, defects closed 2026-08-04. The 2026-08-03 architecture/code review found four real defects (D1–D4) plus six doc-vs-code drift items (D5–D10); **D1–D4 are now closed** (see below) with a gateway-path regression test added. Full original detail, evidence, and file references live in [docs/08-development-roadmap.md §"Phase 2 review findings"](docs/08-development-roadmap.md). D5–D10 remain as tracked drift — none of them block sign-off, which now rests solely on the frontend-lead OpenAPI review. The roadmap was revised in the 2026-08-03 pass — Phase 4 split into 4A (SSLCommerz sandbox, unblocked) and 4B (live cutover), and Phase 3.5 (CMS) added.

### ✅ D1–D4 closed 2026-08-04

- **D1 — fixed.** `VerifyPayment::markSucceeded()` now dispatches `App\Domain\Payment\Events\PaymentSucceeded`, handled by `App\Domain\Ticketing\Listeners\IssueTicketForSucceededPayment` (registered in `AppServiceProvider::boot()`), which issues the ticket. Deliberately built as an event/listener rather than a direct `IssueTicket` call, per the module-boundary rule below — this does not touch `VerifyManualPayment`'s existing direct call, which is still tracked under D6.
- **D2 — fixed.** `tests/Feature/Payment/VerifyPaymentTest.php` and `tests/Feature/Payment/WebhookTest.php` now assert a `tickets` row after gateway verification; `tests/Feature/EndToEndTest.php` gained `test_complete_registration_to_admission_flow_via_gateway()`, a full registration → initiate → IPN → ticket → gate-admission run through the actual gateway path (no manual-verification shortcut).
- **D3 — fixed.** `POST /api/v1/public/registrations/{registration}/payment/initiate` (`App\Http\Controllers\Api\Public\PaymentController::initiate`) calls `InitiatePayment` against the registration's pending payment and returns a `redirect_url`. The callback/return URL is built server-side from `services.frontend.url` (`FRONTEND_URL` env), never accepted from the client, to avoid an open-redirect. `success`/`fail`/`cancel` browser-return *handlers* were **not** added — the existing `GET /api/v1/public/registrations/{registration}` already exposes live payment status for the frontend to poll after redirect, and per docs/06 §6.6 the browser redirect must never itself mutate a payment, so there is nothing for a dedicated handler to do yet.
- **D4 — fixed.** `idempotent:registration.create` is now attached to `POST /public/registrations` and `idempotent:payment.initiate` to the new initiate route (both require an `Idempotency-Key` header). Existing tests updated to send it; a replay-produces-cached-response regression test was added for both routes.

### ⚠️ Doc-vs-code drift (D5–D10) — fix the code or fix the docs, don't leave both

- **D5** Reserved capacity leaks: `tryReserve()` on every registration, released only on explicit payment failure. `payments.expires_at` is never written and `routes/console.php` defines no schedule. Sweeper is a Phase 4A deliverable.
- **D6** The event-driven module boundary described below **still only partially exists** — `PaymentSucceeded`/`IssueTicketForSucceededPayment` (added closing D1) is the first real instance; `VerifyManualPayment` still calls `IssueTicket` directly, `CreateRegistration` still creates `Payment` directly, and the other nine `Events/`/`Listeners/` dirs are still empty. Write further cross-module code the right way rather than copying the remaining direct calls.
- **D7** `payment_method` reaches the DB unvalidated (defaults to `bkash`); no validation of `max_admits`, `allowed_participant_types`, `is_active`/`is_public`, or the sale window.
- **D8** `ActivityLog::create()` lives in five admin controllers, not in the actions — non-HTTP callers skip the audit trail.
- **D9** No observers exist; no media upload endpoint (so manual payment proof is unusable); no `config/cors.php` for the Next.js origin; `config/sanctum.php` has `'expiration' => null`, so staff tokens never expire.
- **D10** `routes/api/admin.php` has **no check-in endpoints**, though Phase 2's deliverable list names them — so the SPA's Check-in page has no backend. Also missing: users/roles, gates, devices, and volunteer CRUD. **Explicitly rescheduled**, not silently dropped: this is a multi-endpoint slice of its own (check-in, gates, devices, users/roles, volunteer CRUD) rather than a same-day fix like D1–D4, and is tracked as the next follow-up after this close-out, ahead of Phase 3 needing the SPA's Check-in page un-stubbed. The notifications dashboard is correctly deferred to Phase 5.

### 🚧 Deferred by design — do NOT report these as bugs

Scheduled deliverables of Phases 4A/5/6, correctly absent in Phase 2:
`placeholder_sig` QR signature and the missing `QrSigner`; `ProcessCheckIn` not verifying signatures; ticket PDF rendering; the interim O(n) ticket-number counter in `IssueTicket.php:19`; notification delivery (the outbox is never written); the expiry sweeper and reconciliation jobs; real gateway adapters other than `FakeGateway`.

**Security constraint until Phase 6 lands:** QR admission is unauthenticated — `ProcessCheckIn.php:176` hardcodes `signature_valid => true` and verifies nothing. No ticket PDF may be delivered to a real attendee before the Ed25519 signing scheme ships.

### ✅ Genuinely complete

- Six-module domain structure (Registration, Payment, Ticketing, Notification, CheckIn, Reporting, Shared)
- All 26 migrations with seeders and realistic factories (`DummyDataSeeder` for demo, `LoadTestSeeder` for volume)
- Eloquent models with relationships, casts, and `HasUlid`/`HasStateMachine`/`HasImmutableCreatedAt` traits applied
- RBAC with roles, permissions, and policies (spatie/laravel-permission), seeded from `config/rbac.php`
- Sanctum authentication for all three guards (web-admin, attendee, scanner) + TOTP 2FA for staff
- REST API v1 route structure (public, attendee, admin, scanner, webhooks)
- API Resources for all major entities
- Horizon configuration with four queue lanes *(configured; nothing dispatches to them yet — see D6/Phase 5)*
- CI pipeline (Pint, PHPStan level 8, tests, `composer audit`)
- OpenAPI attributes on all ~47 endpoints; spec generates cleanly
- State machine (`HasStateMachine`) integrated across all models
- Fake drivers: `FakeGateway`, `FakeSmsDriver`, `FakeEmailDriver`, `FakeWhatsAppDriver`
- Atomic reservation (`tryReserve`/`confirmSale`/`releaseReservation`) and atomic admission (`tryAdmit`) — verified under concurrency
- A gateway-verified payment issues a ticket (D1) and idempotency is enforced on registration creation and payment initiation (D4)
- 115 tests passing, Pint clean, PHPStan level 8 clean, `composer audit` clean

### ⚠️ Previously listed as complete — corrected 2026-08-03, D3/D4/D1-related rows resolved 2026-08-04

These were on the "done" list but the code did not support the claim at the time. D1, D3, and the idempotency half of D4 are now fixed (see above); the rest still apply — do not rely on them:

| Claimed | Reality |
|---|---|
| "Eloquent models with … observers" | **No observers exist** anywhere in `app/` |
| "Activity logging infrastructure" | Works, but lives in controllers rather than actions, so non-HTTP callers bypass it (D8) |

### ✅ Recent wins (accurate)

- Closed a real RBAC gap: 14 admin endpoints (registrations, attendees, payments, tickets, ticket types, reports, settings) had no permission check at all — any authenticated staff member of any role could call them. Added the missing checks plus two permissions that didn't exist yet (`attendee.delete`, `ticket_type.delete`).
- Full permission-catalogue test: allow + deny case for every entry in `config/rbac.php`, plus HTTP round-trips against every enforced endpoint
- Concurrency tests green: 300 purchases against 100 capacity sells exactly 100; 20 concurrent scans admit exactly once
- Documented all ~47 API endpoints with OpenAPI attributes (was 1/50)
- Closed D1–D4 (2026-08-04): gateway payments now issue tickets end-to-end, a public payment-initiate endpoint exists, and idempotency is enforced on registration creation and payment initiation

### 📋 Exit Criteria (from docs/08-development-roadmap.md)
- [x] Registration → payment → ticketing → admission flow works end-to-end in tests — **met 2026-08-04**: `EndToEndTest::test_complete_registration_to_admission_flow_via_gateway()` proves the actual gateway path (initiate → IPN → verify → ticket → admission), not just manual verification
- [x] Concurrency tests pass (300 purchases against 100 capacity, 20 concurrent scans)
- [x] Every permission has passing allow-case and deny-case tests
- [~] OpenAPI spec published (`public/docs/openapi.json`, regenerate via `php artisan app:generate-open-api-spec`) — **still needs review by the frontend lead**, which is a human sign-off step outside what this session can complete. Phase 3 formally depends on it.
- [x] D1–D4 closed, with a regression test asserting a `tickets` row after gateway verification
- [ ] D10's admin check-in endpoints delivered, or explicitly rescheduled — **rescheduled** (see D10 above), not delivered
- [x] `composer test` green with the new assertions (115 passing), Pint and PHPStan level 8 clean
- [ ] D1–D4 closed, with a regression test asserting a `tickets` row after **gateway** verification

### 💳 Payments — development environment

Development and all Phase 4A work run against the **SSLCommerz sandbox** (<https://sandbox-gw.sslcommerz.com/docs>, <https://developer.sslcommerz.com/doc/v4/>). Sandbox credentials are self-service — no merchant onboarding required — so the full money path is buildable now.

| Purpose | Sandbox endpoint |
|---|---|
| Session initiation | `POST https://sandbox-gw.sslcommerz.com/gwprocess/v4/api.php` |
| Order validation (`val_id`) | `GET https://sandbox.sslcommerz.com/validator/api/validationserverAPI.php` |
| Refund | `https://sandbox.sslcommerz.com/validator/api/merchantTransIDvalidationAPI.php` |

`store_id`/`store_passwd` belong in `config/services.php` + `.env`, never committed and never in an unencrypted `event_settings` row. The mandatory order for every transaction: **IPN `verify_sign`/`verify_key` check → `val_id` validation API (accept only `VALID` or `VALIDATED`) → amount/currency re-check against `amount_due_paisa` → only then `succeeded`.** The `success_url` browser redirect proves nothing and must never transition a payment — SSLCommerz's own docs say so explicitly. SSLCommerz transacts in decimal BDT with a 10.00 minimum; that conversion lives inside `SslCommerzClient` alone and no decimal amount may leak past the adapter.

### 🚨 External Dependencies (start during Phase 2!)
- [ ] Payment gateway merchant applications (bKash, Nagad, Rocket, SSLCommerz) — **2-6 weeks lead time**. Blocks Phase 4B (live cutover) only; sandbox work is unblocked. Sequence SSLCommerz first.
- [ ] WhatsApp Business template approval
- [ ] SMS vendor contract and sender ID
- [ ] Domain registration and email SPF/DKIM/DMARC setup

---

## Repository layout

A single Laravel application at the repo root, which serves **both** the API and the admin dashboard SPA. `.github/workflows/backend-ci.yml` runs lint/static-analysis/tests against the repo root.

| Path | What lives there |
|---|---|
| `app/Domain/{Module}/` | The six domain modules — Actions, Models, Policies, Services, Events, Listeners |
| `app/Http/Controllers/Api/{Public,Attendee,Admin,Scanner}/` | Thin controllers, split by audience |
| `app/Http/Controllers/Webhooks/` | Gateway IPN endpoints (one per gateway) |
| `routes/api/` | `public.php`, `attendee.php`, `admin.php`, wired by `v1.php`; plus `scanner.php`, `webhooks.php` at `routes/` |
| `resources/js/` | **The admin dashboard** — Vite + React 19 SPA (see below) |
| `docs/01`–`08` | Design docs. Start at `docs/README.md`; the ADRs explain *why* |

**Three frontends, two repos.** Only one of them is here:

- **Admin dashboard — in this repo** at `resources/js`, a Vite + React 19 SPA served by the catch-all in `routes/web.php`. Deliberately *not* Next.js: it is authenticated-only, so SSR/ISR buys nothing and co-location removes a deploy target.
- **Public site — separate Next.js repo**, consuming this API cross-origin. Needs `config/cors.php` with an explicit origin allowlist, which does not exist yet (D9).
- **Scanner — separate React Native app** (Phase 7), talking to `routes/scanner.php`.

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

**Admin dashboard SPA** (Vite + React, `resources/js`):
```bash
npm run dev        # Vite dev server (or just use `composer dev`, which runs it)
npm run build
npm run typecheck  # tsc --noEmit — TypeScript is strict; run before committing SPA changes
```

**OpenAPI spec** — regenerate after adding or changing any endpoint:
```bash
php artisan app:generate-open-api-spec   # writes public/docs/openapi.json
```

**Seed data:**
```bash
php artisan db:seed                              # RBAC, settings, ticket types, sessions, gates
php artisan db:seed --class=DummyDataSeeder      # demo registrations/payments/tickets for local dev
php artisan db:seed --class=LoadTestSeeder       # bulk volume for performance work
```

## Architecture

Full design docs live in `docs/` (`01`–`08`, start at `docs/README.md`). Read the relevant doc before making non-trivial changes to a subsystem — the ADRs in `docs/README.md` explain *why*, and getting them wrong breaks correctness at the gate on event day, not just a test. The load-bearing decisions:

- **Modular monolith.** Laravel organized into six domain modules under `app/Domain/`: `Registration`, `Payment`, `Ticketing`, `Notification`, `CheckIn`, `Reporting`, plus `Shared` for cross-cutting concerns (`User`, `EventSetting`, `ActivityLog`, `IdempotencyKey`, and support traits). Modules communicate through events and service interfaces — **never reach into another module's Eloquent models directly**. ⚠️ **This is the target, not the current state (D6):** the `Events/`/`Listeners/` directories are empty and existing code violates it (`CreateRegistration` creates `Payment` directly; `VerifyManualPayment` calls `IssueTicket` directly). Write *new* cross-module code the right way — an event and a listener — rather than copying the existing violations. Phase 3.5 adds a seventh module, `Content`.
- **Layering within a module:** HTTP (`app/Http/Controllers` — thin, no business logic) → FormRequests (validation + `authorize()`) → Actions/domain services in `app/Domain/*/Actions` and `*/Services` → Repositories/Eloquent → async Jobs for anything slow (gateway calls, PDF/QR rendering, SMS/email/WhatsApp). The queue boundary is deliberate: registration and payment must stay fast and transactional; notification delivery and asset generation are allowed to be slow or briefly broken.
- **Money is integer paisa, never float/decimal** (`BIGINT UNSIGNED`, 1 BDT = 100 paisa), with an explicit `currency` column. Never introduce a decimal money column.
- **Public-facing identifiers are ULIDs**; auto-increment BIGINT PKs stay internal only. Routes use `{model:ulid}` binding (see `routes/api/*.php`).
- **Tickets are immutable once issued.** Corrections are void + reissue (`replaces_ticket_id` chain), never edit/delete.
- **State transitions go through `HasStateMachine`** (`app/Domain/Shared/Support/HasStateMachine.php`): a model defines a `TRANSITIONS` constant (`['from' => ['to', ...]]`) and calls `transitionTo()`; illegal transitions throw `InvalidStateTransitionException`. Don't mutate a `status` column directly — use this instead. Permitted maps are specified in `docs/04-erd.md` §4.7.
- **QR tickets are Ed25519-signed**, not database-lookup tokens; scanner devices hold only the public key plus a signed revocation manifest, so admission decisions work fully offline. Admission counting uses an atomic conditional `UPDATE ... WHERE admitted_count + :n <= admits_total` — never SELECT-then-INSERT — to stay race-safe under concurrent gate scans. ⚠️ **Signing is not implemented yet (Phase 6).** `IssueTicket.php:52-58` writes the literal `'placeholder_sig'` and `ProcessCheckIn.php:176` hardcodes `signature_valid => true` without verifying anything — it reads the ticket ULID out of the payload and admits. Payload expiry is not checked either. The atomic admission counting *is* real and correct; only the signature layer is missing. **No ticket PDF may reach a real attendee until `QrSigner` ships.**
- **RBAC:** `spatie/laravel-permission` under the `web-admin` guard, catalogue seeded from the versioned `config/rbac.php` (never created ad hoc — this is what keeps staging/production provably in sync). Code must check **permissions** (`payment.verify_manual`), never role names. Staff (`users`) and attendees (`attendees`) are separate identity domains/guards — do not conflate them. Volunteer (`scanner` guard) access is additionally scoped server-side by enrolled device, assigned gate, and the check-in time window — see `docs/02-rbac-permissions.md` §2.4 for the full authorization flow (permission check **and** model policy must both pass).
- **Routes** are split by audience under `routes/api/`: `public.php` (unauthenticated browse/register), `attendee.php` (attendee self-service, `auth:attendee`), `admin.php` (staff console, `auth:web-admin`), wired together in `routes/api/v1.php`; plus `routes/scanner.php` (volunteer devices) and `routes/webhooks.php` (payment gateway IPNs). `routes/api.php` just mounts `v1.php` under the `api/v1` prefix.
- **Payments:** four gateways (bKash, Nagad, Rocket, SSLCommerz) behind one adapter contract (`createIntent`, `verify`, `refund`, `parseWebhook`) under `app/Domain/Payment/Gateways/`. A browser return-callback is **never** trusted to mark a payment succeeded — only a server-to-server verify call or a signature-validated IPN can do that. `payments` (the money intent) and `payment_transactions` (every gateway interaction, append-only) are deliberately separate tables — don't collapse them. ⚠️ Today **every** gateway name resolves to `FakeGateway` (`PaymentGatewayResolver::forMethod()`); `SslCommerzClient` is the Phase 4A deliverable — see the sandbox section above. `PaymentGatewayResolver` is the *only* place that may branch on gateway name; domain code never does.
- **Notifications** go through a database outbox (`notifications`/`notification_events`) written in the same transaction as the triggering business event, then drained by queue workers via provider-agnostic channel drivers (Email/SMS/WhatsApp). Don't call a notification provider directly from request-handling code. ⚠️ Nothing writes to the outbox yet and there is no `app/Jobs` — delivery is Phase 5.

Queue lanes (Horizon) are named by urgency — `payments` (<5s), `tickets` (<30s), `notifications` (<60s), `reports` (minutes) — keep jobs on the queue that matches their latency budget, since notification volume must never delay a payment webhook. ⚠️ There is no `app/Jobs` directory yet and nothing is dispatched; the first jobs land in Phase 4A/5. When you add one, put it on the lane matching its latency budget rather than the default.

## Development conventions

Follow these so new code matches what's already in `app/` and passes CI (Pint, PHPStan level 8, `composer audit`, tests).

- **Use the shared traits instead of hand-rolling behavior.** New Eloquent models: `HasUlid` (public `ulid` route key — every model reachable by URL/API/QR needs this, not the auto-increment `id`), `HasImmutableCreatedAt` for append-only tables (no `updated_at`, never call `->update()` on these rows), `HasStateMachine` for anything with a `status` column (define a `TRANSITIONS` const, call `transitionTo()`, never assign `status` directly).
- **PHPStan runs at level 8** (`phpstan.neon`) — use the modern `casts()` method, not the legacy `$casts` property, and keep everything fully typed (params, returns, PHPDoc generics for collections/relations).
- **Money is `BIGINT UNSIGNED` paisa** on every monetary column, with an explicit `currency` column alongside it. Never add a `decimal`/`float` money column, never compare amounts across currencies without an explicit check.
- **Idempotency is mandatory on unsafe operations**, not optional hardening: payment intents, gateway webhooks, ticket issuance, and scanner sync all need an `idempotency_key` with a unique index (see `App\Domain\Shared\Models\IdempotencyKey`, `isInFlight()`). A retried webhook or a double-tapped button must produce one effect.
- **A payment only reaches `succeeded` via server-to-server gateway verification or an authenticated Event Manager approving a manual payment** — never from a browser return-callback. If you touch payment code, this invariant is the one thing that must never regress.
- **Log sensitive actions to `activity_logs`**: refunds, voids, manual check-in overrides, role/permission changes, key rotation, exports, impersonation. Record actor, subject, before/after diff, IP, and `request_id`. These rows are append-only — no update/delete path. **Write the log inside the Action, not the controller** — existing code gets this wrong (D8), so a job or console command performing the same operation silently skips the audit trail. New code should not copy that pattern.
- **API responses use explicit field allowlists (API Resources), never blocklists.** A Volunteer-facing resource must never contain `email`, `mobile`, or money fields — omit them from the resource, don't filter them at render time. Errors use the uniform shape `{ code, message, errors?, request_id }`; never leak stack traces or confirm resource existence to an unauthorized caller (404, not 403, for "not yours").
- **Authorization is always two-stage**: a permission check (`$user->can('payment.refund')`, catalogue in `config/rbac.php`) *and* a model policy check (`PaymentPolicy::refund()`) — a permission grants the capability in general, the policy decides for that specific record. Check permissions by name, never by role (`if ($user->hasRole(...))` in business logic is wrong).
- **Query-scope by ownership at the builder, not just in the controller** — e.g. an attendee's own-resource queries should filter `attendee_id = auth()->id()` in the query itself, so a missed controller check can't leak another attendee's data.
- **Rate limits are part of the spec, not an afterthought** — see `docs/06-security-architecture.md` §6.7 for the per-route-class limits (OTP/magic-link requests are throttled primarily to control SMS/email cost, not just abuse).
- **File uploads are never trusted on extension or client `Content-Type`** — validate by magic bytes, re-encode images (strips both malicious payloads and EXIF/GPS), store private with a randomized filename, serve only via short-TTL signed URLs.
- **Seed data belongs in versioned config/seeders, not ad hoc.** RBAC roles/permissions are seeded from `config/rbac.php` via `RbacSeeder` — add new permissions there, not directly through Spatie's API. `EventSettingSeeder` / `TicketTypeSeeder` follow the same pattern; extend them rather than creating rows manually in migrations.
- **Test every permission with the two-case pattern**: one authorized actor succeeds, one unauthorized actor gets denied (see `tests/Feature/Rbac/PermissionCatalogueTest.php`). New permissions in `config/rbac.php` should get a matching pair of assertions. Target ≥80% coverage on domain logic (`app/Domain/**`).
- **Module boundary discipline**: don't import another domain's Eloquent model directly from outside its module — go through its published events or a service interface. If a new feature needs data from another module, that's a signal to add an event/listener, not a cross-module query.

## Admin dashboard conventions (`resources/js`)

Vite + React 19 + TypeScript strict, TanStack Query for all server state, TanStack Table for data grids, Tailwind 4, React Router 7. Match what's there:

- **Feature-folder structure.** Each module is `features/<name>/` with `api.ts` (typed request functions), `types.ts` (response shapes), and `<Name>Page.tsx`. Shared primitives live in `components/` and `lib/`; don't add a second UI kit.
- **All requests go through `lib/api.ts`.** It attaches the Sanctum bearer token, and a 401 anywhere clears the session via the registered handler. Never call `axios` directly from a feature, and never read the token from `localStorage` yourself.
- **Errors normalise through `toApiError()`** into the API's `{ code, message, errors?, request_id }` envelope. Surface `errors` as field-level messages and `message` as the toast; a 403 should say which permission is missing.
- **Server-side pagination, sorting, and filtering only.** The attendee table targets 20,000+ rows — client-side sorting of a full table will not survive real data. Use `lib/pagination.ts` (`PaginatedResponse`, `unwrap`).
- **Address resources by `ulid`, not `id`.** Some existing filters still pass numeric `ticket_type_id`, which leaks an internal primary key across the API boundary — don't extend that pattern; new filters take ULIDs.
- **Navigation and actions render from the permission set returned at login**, never from role names — same rule as the backend. A user must not see a control they cannot use.
- **Types are hand-written today and will drift.** The roadmap's Phase 3 exit criterion requires no drift between client and server validation. Until the client is generated from `public/docs/openapi.json`, treat `types.ts` as needing a manual check whenever you change an API Resource.
- **Run `npm run typecheck` before committing** — TypeScript is strict and CI does not currently catch SPA type errors.
- Two pages are unbacked placeholders: **Check-in** and **Notifications** (D10). Check-in admin endpoints were a Phase 2 deliverable and are missing; the notifications dashboard arrives with Phase 5. Don't stub fake data into either — leave the placeholder and flag it.
