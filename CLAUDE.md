# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Current Phase Status

**Phase 2 — Backend API Development (Week 2 of 6 weeks) — D1–D4 closed 2026-08-04, sign-off still pending frontend-lead OpenAPI review**
**Phase 3.5 — CMS: closed 2026-08-04. Backend foundation (schema + public read API) and admin half (CRUD with revision capture/restore, media upload, SPA screens, ISR revalidation hook) both landed — see [§Phase 3.5 below](#-phase-35-cms--closed-2026-08-04).**
**Phase 5 — Email/SMS/WhatsApp: buildable-now slice landed 2026-08-04 (outbox, dispatcher, real email, admin dashboard). Real SMS/WhatsApp drivers and DLR webhooks stay deferred — no vendor is chosen and Meta hasn't approved templates. See [§Phase 5 below](#-phase-5-emailsmswhatsapp--buildable-now-slice-closed-2026-08-04).**
**Phase 4A — SSLCommerz Sandbox: buildable-now slice landed 2026-08-04 (real `SslCommerzClient`, expiry sweeper closing D5, nightly reconciliation, refund-to-gateway wiring). Not yet smoke-tested against a live sandbox transaction — no `SSLCOMMERZ_STORE_PASSWORD` has been provisioned in this environment. See [§Phase 4A below](#-phase-4a-sslcommerz-sandbox--buildable-now-slice-closed-2026-08-04).**
**Phase 6 — QR & PDF Tickets: buildable-now slice landed 2026-08-04; the remaining non-hardware items closed 2026-08-21 — manifest streaming at a 12,000-ticket cold start, key rotation as a server-enforced staged procedure, and the Bangla PDF text-layer defect fixed by moving rendering to headless Chrome. Only physical print/scan testing is still outstanding, and it needs a real printer and real devices. See [§Phase 6 close-out](#-phase-6-closed-out--manifest-at-scale-real-key-rotation-and-the-bangla-pdf-defect-fixed--2026-08-21).**
**Phase 7 — Mobile Verification App: the React Native scanner is a separate repo (`decent-event-scanner`, sibling to this one — see "Three frontends, two repos" below), so nothing here changed except one small backend addition: `POST /scanner/v1/enrol` now also returns the volunteer's assigned gates. Core offline scan loop (enrolment, manifest delta sync, local Ed25519 verification, local admission policy, offline scan queue with batched idempotent upload) landed there 2026-08-04. Manual lookup, override-request routing, crash reporting, and all physical-device testing are deferred — see that repo's README.**
**Phase 8 — Testing & Security Audit: a narrow buildable-now slice landed 2026-08-04 — this is a small fraction of the phase, not a close-out. Fixed two exit-criteria concurrency tests that were never actually concurrent (docs/08 R12), added the missing sponsor/couple/VIP E2E scenarios, and found (and partially fixed) a real Bangla-PDF text-layer defect. Load testing at real scale, the third-party pentest, the gate rehearsal, DB failover/backup drills, and UAT are all still outstanding and need real infrastructure, hired testers, or real users this environment doesn't have. See [§Phase 8 below](#-phase-8-testing--security-audit--buildable-now-slice-landed-2026-08-04).**
**Phase 9 — Production Deployment: started ahead of its formal dependency (Phase 8 sign-off is still outstanding — see above) at explicit user request, because almost all of Phase 9 is real infrastructure this environment cannot provide, so the "buildable-now slice" split needed applying regardless of when it started. Landed 2026-08-04: the docs/06 §6.7 security-header set (nonce-based CSP wired through Vite's previously-unused nonce plumbing), a deep `/up` health check, backup/verified-restore tooling proven against real MySQL, a provider-agnostic production Dockerfile + compose topology (unverified — no Docker daemon in this environment), and a release-image CI pipeline. No hosting provider is chosen, so there is no live cutover, CDN/WAF, on-call, or event-day operations here — see [§Phase 9 below](#-phase-9-production-deployment--buildable-now-slice-landed-2026-08-04).**

> Reviewed 2026-08-03, defects closed 2026-08-04. The 2026-08-03 architecture/code review found four real defects (D1–D4) plus six doc-vs-code drift items (D5–D10); **D1–D4 are now closed** (see below) with a gateway-path regression test added. Full original detail, evidence, and file references live in [docs/08-development-roadmap.md §"Phase 2 review findings"](docs/08-development-roadmap.md). D5 closed 2026-08-04 as part of Phase 4A; D6–D10 remain as tracked drift — none of them block sign-off, which now rests solely on the frontend-lead OpenAPI review. The roadmap was revised in the 2026-08-03 pass — Phase 4 split into 4A (SSLCommerz sandbox, unblocked) and 4B (live cutover), and Phase 3.5 (CMS) added.

### ✅ D1–D4 closed 2026-08-04

- **D1 — fixed.** `VerifyPayment::markSucceeded()` now dispatches `App\Domain\Payment\Events\PaymentSucceeded`, handled by `App\Domain\Ticketing\Listeners\IssueTicketForSucceededPayment` (registered in `AppServiceProvider::boot()`), which issues the ticket. Deliberately built as an event/listener rather than a direct `IssueTicket` call, per the module-boundary rule below — this does not touch `VerifyManualPayment`'s existing direct call, which is still tracked under D6.
- **D2 — fixed.** `tests/Feature/Payment/VerifyPaymentTest.php` and `tests/Feature/Payment/WebhookTest.php` now assert a `tickets` row after gateway verification; `tests/Feature/EndToEndTest.php` gained `test_complete_registration_to_admission_flow_via_gateway()`, a full registration → initiate → IPN → ticket → gate-admission run through the actual gateway path (no manual-verification shortcut).
- **D3 — fixed.** `POST /api/v1/public/registrations/{registration}/payment/initiate` (`App\Http\Controllers\Api\Public\PaymentController::initiate`) calls `InitiatePayment` against the registration's pending payment and returns a `redirect_url`. The callback/return URL is built server-side from `services.frontend.url` (`FRONTEND_URL` env), never accepted from the client, to avoid an open-redirect. `success`/`fail`/`cancel` browser-return *handlers* were **not** added — the existing `GET /api/v1/public/registrations/{registration}` already exposes live payment status for the frontend to poll after redirect, and per docs/06 §6.6 the browser redirect must never itself mutate a payment, so there is nothing for a dedicated handler to do yet.
- **D4 — fixed.** `idempotent:registration.create` is now attached to `POST /public/registrations` and `idempotent:payment.initiate` to the new initiate route (both require an `Idempotency-Key` header). Existing tests updated to send it; a replay-produces-cached-response regression test was added for both routes.

### ⚠️ Doc-vs-code drift (D6–D10) — fix the code or fix the docs, don't leave both

D5 closed 2026-08-04 — see [§Phase 4A](#-phase-4a-sslcommerz-sandbox--buildable-now-slice-closed-2026-08-04).

- **D6** The event-driven module boundary described below **still only partially exists** — `PaymentSucceeded`/`IssueTicketForSucceededPayment` (added closing D1) is the first real instance; `VerifyManualPayment` still calls `IssueTicket` directly, `CreateRegistration` still creates `Payment` directly, and the other nine `Events/`/`Listeners/` dirs are still empty. Write further cross-module code the right way rather than copying the remaining direct calls.
- **D7** `payment_method` reaches the DB unvalidated (defaults to `bkash`); no validation of `max_admits`, `allowed_participant_types`, `is_active`/`is_public`, or the sale window.
- **D8** `ActivityLog::create()` lives in five admin controllers, not in the actions — non-HTTP callers skip the audit trail.
- **D9** *Partially closed 2026-08-04, two more items closed 2026-08-04 (CORS) and corrected 2026-08-04 (sanctum, see below).* The CMS media upload endpoint now exists (`POST /admin/content/media`, Phase 3.5) with magic-byte validation and image re-encoding — but it is **public-disk, CMS-collections only** and deliberately refuses anything else, so **manual payment proof is still unusable**: that needs a private-disk upload path with short-TTL signed URLs, which is Phase 4A's to build (reuse `UploadContentMedia`'s validation, not its storage settings). `config/cors.php` now exists (see the "Add CORS config for the frontend origin, closing Gap G2" commit) — allowlists `FRONTEND_URL` explicitly, no credentialed cookies, matching docs/01 §5. **Corrected 2026-08-04:** the claim that `config/sanctum.php`'s `'expiration' => null` means staff tokens never expire was never actually true — every `createToken()` call site (`Admin\AuthController`, `Admin\TwoFactorController`, `Attendee\AuthController`, `Scanner\DeviceEnrolmentController`) already passes an explicit `expires_at` (8h for a fully-authenticated staff session, matching docs/06's "8h TTL"), and Sanctum's `Guard::__invoke()` (`vendor/laravel/sanctum/src/Guard.php`) checks the token's own `expires_at` independently of the global config value — the global setting only adds an *additional* cap on top, it is not the only enforcement path. This was stale documentation, not a code gap; no code change was needed. Still open: no observers exist.
- **D10** `routes/api/admin.php` has **no check-in endpoints**, though Phase 2's deliverable list names them — so the SPA's Check-in page has no backend. Also missing: users/roles, gates, devices, and volunteer CRUD. **Explicitly rescheduled**, not silently dropped: this is a multi-endpoint slice of its own (check-in, gates, devices, users/roles, volunteer CRUD) rather than a same-day fix like D1–D4, and is tracked as the next follow-up after this close-out, ahead of Phase 3 needing the SPA's Check-in page un-stubbed. The notifications dashboard is correctly deferred to Phase 5.

### 🚧 Deferred by design — do NOT report these as bugs

Phase 6's remaining engineering closed 2026-08-21 (see the close-out section below). **What's still genuinely deferred from Phase 6 is physical print/scan testing only** — a real printer and real devices, per docs/08's note that it cannot be simulated. The "wait for confirmed device sync" step of key rotation is no longer deferred: the server now refuses to activate a key until every active device's `last_sync_at` confirms it, so that ordering is enforced rather than remembered. What remains unrehearsed is the drill itself, for want of a staging environment.

Phase 4A landed the expiry sweeper, the reconciliation job, and a real `SslCommerzClient` (see below) — `bkash`/`nagad`/`rocket` still resolve to `FakeGateway` pending their merchant applications (Phase 4B).

Vendor-blocked pieces of Phase 5 — **the SMS half is no longer among them.** A vendor was named (REVE Systems) and the real `SmsDriver`, its DLR ingestion and its delivery-receipt poller all landed 2026-08-22; see [§SMS is real](#-sms-is-real-reve-systems--2026-08-22). What remains blocked is WhatsApp only: a real `WhatsAppDriver` (Meta has not approved the templates — unchecked in External Dependencies below) and a WhatsApp delivery-status webhook, whose payload and signature shape are Meta-specific and unknown until the templates exist.

**Security constraint, resolved 2026-08-04:** QR admission used to be unauthenticated — `ProcessCheckIn.php:176` hardcoded `signature_valid => true` and verified nothing. `ProcessCheckIn` now parses and verifies the Ed25519 signature (and payload expiry) via `QrSigner` before a ticket ever reaches `AdmissionPolicy`, matching the AdmissionPolicy docblock's own forward-reference to this. A ticket PDF may now be generated and delivered — see [§Phase 6](#-phase-6-qr--pdf-tickets--buildable-now-slice-closed-2026-08-04) for what "buildable-now" still leaves out (physical testing, live rotation rehearsal).

### ✅ Genuinely complete

- Seven-module domain structure (Registration, Payment, Ticketing, Notification, CheckIn, Reporting, Content, Shared)
- All 38 migrations with seeders and realistic factories (`DummyDataSeeder` for demo, `LoadTestSeeder` for volume, `ContentSeeder` for bilingual CMS baseline)
- Eloquent models with relationships, casts, and `HasUlid`/`HasStateMachine`/`HasImmutableCreatedAt` traits applied
- RBAC with roles, permissions, and policies (spatie/laravel-permission), seeded from `config/rbac.php`
- Sanctum authentication for all three guards (web-admin, attendee, scanner) + TOTP 2FA for staff
- REST API v1 route structure (public, attendee, admin, scanner, webhooks)
- API Resources for all major entities
- Horizon configuration with four queue lanes — `notifications` (Phase 5) and `tickets` (Phase 6) are live and draining; `payments`/`reports` are configured but nothing dispatches to them yet
- CI pipeline (Pint, PHPStan level 8, tests, `composer audit`)
- OpenAPI attributes on all documented endpoints; spec generates cleanly (104 paths)
- State machine (`HasStateMachine`) integrated across all models
- Real `MailDriver` (Phase 5), real `SslCommerzClient` (Phase 4A) and real `SmsDriver`/`ReveSmsClient` (2026-08-22, REVE Systems — falls back to `FakeSmsDriver` when no credentials are configured), plus fake drivers for what is still vendor-blocked: `FakeGateway` (still resolved for `bkash`/`nagad`/`rocket`) and `FakeWhatsAppDriver`
- Atomic reservation (`tryReserve`/`confirmSale`/`releaseReservation`) and atomic admission (`tryAdmit`) — verified under concurrency
- A gateway-verified payment issues a ticket (D1) and idempotency is enforced on registration creation and payment initiation (D4)
- The notification outbox is real: 6 domain events write outbox rows via `Notification\Actions\QueueNotification`, `app/Jobs/SendNotificationJob` drains them with the ADR-07 backoff schedule, per-channel kill switches are enforced at send-time (Phase 5)
- The CMS is editable end to end (Phase 3.5): 22 admin content endpoints, versioned page saves with restore, a validating media upload, and the SPA screens at `/cms`
- QR tickets are genuinely Ed25519-signed and verified, PDFs are real (Phase 6): see [§Phase 6](#-phase-6-qr--pdf-tickets--buildable-now-slice-closed-2026-08-04)
- 334 tests passing, Pint clean, PHPStan level 8 clean, `composer audit` clean

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
- Closed D5 (2026-08-04, Phase 4A): abandoned checkouts release reserved capacity via a gateway-pre-checked sweeper instead of leaking it forever

### 📋 Exit Criteria (from docs/08-development-roadmap.md)
- [x] Registration → payment → ticketing → admission flow works end-to-end in tests — **met 2026-08-04**: `EndToEndTest::test_complete_registration_to_admission_flow_via_gateway()` proves the actual gateway path (initiate → IPN → verify → ticket → admission), not just manual verification
- [x] Concurrency tests pass (300 purchases against 100 capacity, 20 concurrent scans)
- [x] Every permission has passing allow-case and deny-case tests
- [~] OpenAPI spec published (`public/docs/openapi.json`, regenerate via `php artisan app:generate-open-api-spec`) — **still needs review by the frontend lead**, which is a human sign-off step outside what this session can complete. Phase 3 formally depends on it.
- [x] D1–D4 closed, with a regression test asserting a `tickets` row after gateway verification
- [ ] D10's admin check-in endpoints delivered, or explicitly rescheduled — **rescheduled** (see D10 above), not delivered
- [x] `composer test` green with the new assertions (189 passing), Pint and PHPStan level 8 clean
- [ ] D1–D4 closed, with a regression test asserting a `tickets` row after **gateway** verification

### ✅ Phase 3.5 (CMS) — closed 2026-08-04

The **backend foundation slice** (schema + public read API) landed first, sequenced per docs/08's own note ("schema and public read API in weeks 1–2") so Phase 3's marketing pages build against real content rather than fixtures. The **admin half landed the same day** — the CMS is now editable end to end.

**Shipped — backend foundation:**

- Seventh module `app/Domain/Content/` — `ContentPage`, `ContentBlock`, `ContentPageRevision`, `Menu`, `MenuItem`, `Sponsor`, `ScheduleItem`, `Faq`, `GalleryAlbum`, `GalleryItem`
- Ten migrations (`2026_08_04_1000*`), factories for every model, and `ContentSeeder` with real bilingual copy — wired into `DatabaseSeeder`
- `content.*` permissions in `config/rbac.php`, granted to Event Manager except `content.delete` (Super Admin only, matching every other `*.delete`)
- Public read API under `/api/v1/public/content/` — `pages`, `pages/{slug}`, `menus`, `menus/{code}`, `sponsors`, `schedule`, `faqs`, `gallery`, `gallery/{slug}`
- 45 new tests (`tests/Feature/Public/ContentApiTest.php`, `tests/Unit/Domain/Content/ContentLocaleTest.php`) plus two `content.*` RBAC pairs

**Shipped — admin half:**

- Six Content Actions carrying the audit trail themselves (D8 discipline — new code logs from the Action, never the controller): `SaveContentPage` (block-tree sync + revision capture), `ChangeContentPageStatus`, `RestoreContentPageRevision`, `IssuePagePreviewToken`, `DeleteContentPage`, `UploadContentMedia`, plus `SaveContentResource` as the shared create/update/delete choke point for the simple collections.
- 22 admin endpoints under `/api/v1/admin/content/` — pages (CRUD + `/status` + `/preview-token` + `/revisions` + `/revisions/{revision}/restore`), menus and menu items, sponsors, schedule, FAQs, gallery albums and items, and the media library. All OpenAPI-annotated; the spec is now **104 paths** (was 81).
- **The media upload endpoint exists** (`POST /admin/content/media`) — magic-byte type detection, full GD re-encode (strips EXIF/GPS and anything smuggled into the container), randomised filename, JPEG/PNG/WebP only. SVG is refused outright: it is a script-bearing XML document and no re-encode makes it safe to serve from our own origin. This is the CMS half of D9 only — see D9 above for what it does *not* cover.
- ISR revalidation hook: `Content\Events\ContentChanged` → `Content\Listeners\RevalidateFrontendContent` → `app/Jobs/RevalidateFrontendContentJob` on the `notifications` lane. A no-op until `CONTENT_REVALIDATE_URL` is set, so the CMS is fully usable before the Next.js repo exposes the route. Content publishes the event and knows nothing about a frontend; only the listener does.
- SPA `resources/js/features/cms/` — tabbed Pages / Navigation / Sponsors / Schedule / FAQs / Gallery / Media, plus a full page editor at `/cms/pages/:ulid` with the block editor, publish controls, preview-link minting, and revision restore. Every string field is a paired EN/বাংলা control driven by one page-level locale toggle.
- 51 new tests (`tests/Feature/Admin/Content{PageAdmin,LibraryAdmin,MediaUpload,Revalidation}Test.php`) plus 7 `content.*` HTTP permission round-trips in `ComprehensivePermissionTest`.

**Invariants the admin half establishes — the tests exist to stop these regressing:**

- **Saving never publishes.** `PATCH /pages/{page}` ignores a `status` field entirely; workflow moves go through `POST /pages/{page}/status`, which needs `content.publish` — a permission an Event Manager holds and a copy editor need not. `SaveContentPage` is the only writer of the body, `ChangeContentPageStatus` the only writer of `status`.
- **History is append-only.** Every save captures a `content_page_revisions` row; a *restore* replays the snapshot as a new save rather than rewinding, so who restored what stays recoverable. Snapshots store media by ULID, never internal id.
- **The preview token is returned exactly once**, by the endpoint that mints it. It is `$hidden`, outside `$fillable`, and absent from every resource; the admin view exposes only `has_preview_token`. Rotating invalidates every previously shared link.
- **`FormRequest::validated()` does not preserve nested array order.** It rebuilds data rule by rule, so an entry carrying an optional key can come back first — `array_values()` on it silently scrambles block order. `SaveContentPage` `ksort`s before reindexing; do the same anywhere else a positional array arrives through validation.
- **Block field shapes live in `resources/js/features/cms/blocks.ts`.** The server validates the block *type* against `ContentBlock::TYPES` and keeps `data` as free JSON, deliberately, so a copy change never needs a migration. That makes the SPA schema the field contract in practice: a new block type means touching `ContentBlock::TYPES`, `blocks.ts`, and the public site's renderer.

**Conventions this module establishes — follow them, don't re-litigate:**

- **Bilingual is paired `_bn` columns, not a `content_translations` table.** `title`/`title_bn`, `data`/`data_bn`. Matches `ticket_types.name`/`name_bn`, stays type-safe at PHPStan level 8, and keeps a page read to one row with no join — which matters because every content response is ETagged and CDN-cached. Locale resolution and the per-field fallback to English live in `App\Domain\Content\Support\ContentLocale` — never re-implement that fallback in a resource.
- **Two visibility models, deliberately.** `ContentPage` has the full `draft → in_review → published → archived` state machine plus `published_at` scheduling (`scopeLive()`/`isLive()` are the single definition of "the public may see this"). Every other content type carries a plain `is_published` boolean (`scopePublished()` via `IsPublishableContent`). Don't add a status column to a sponsor or an FAQ.
- **Unpublished content answers 404, never 403**, and a draft slug's body is byte-identical to a nonexistent one — the response shape must not become a probe for draft slugs. Preview tokens are compared with `hash_equals` and preview responses are `no-store` + `noindex`.
- **Pages are at `/public/content/pages/{slug}`**, not `/public/content/{slug}` — the latter collides the moment an editor slugs a page `faqs` or `sponsors`.
- `ScheduleItem::event_session_code` is a **soft** reference to CheckIn's `event_sessions.code`, intentionally not a foreign key — the module boundary forbids Content reaching into another module's tables, and published copy must survive a session being renamed.

### ✅ Phase 5 (Email/SMS/WhatsApp) — buildable-now slice closed 2026-08-04

Split the same way Phase 4A split off SSLCommerz sandbox work from live merchant onboarding: build everything that's pure engineering now, flag what needs a vendor pick or Meta approval rather than guessing at it. None of Phase 5's three external dependencies (WhatsApp template approval, SMS vendor contract, verified email domain) are secured yet — see External Dependencies below — so this is deliberately a subset of the full phase, not a claim that Phase 5 is done.

**Shipped:**

- Six domain events (`Registration\Events\RegistrationCreated`, `Payment\Events\{PaymentFailed,ManualPaymentVerified}`, `Payment\Events\RefundIssued`, `Ticketing\Events\TicketIssued`, plus the existing `Payment\Events\PaymentSucceeded`) each wired to a thin `Notification\Listeners\Queue*Notification` that delegates to `Notification\Actions\QueueNotification` — the outbox-write choke point for every trigger in docs/01 §1.6's channel matrix. `TicketIssued` fires from `IssueTicket::execute()` itself, so it covers both the gateway and manual-verification payment paths without touching D6's module-boundary debt.
- `app/Jobs/SendNotificationJob` (the first job in the codebase) drains the outbox: checks the per-channel kill switch at send-time (not enqueue-time, so a flip cancels anything already queued), resolves the driver via the existing `NotificationChannelResolver`, and retries on the exact ADR-07 schedule (`60,300,900,3600,21600`s, 5 attempts) already configured in `config/horizon.php`'s `supervisor-notifications` lane.
- Real `Notification\Channels\MailDriver` — Laravel `Mail`, works today against the `log` driver and needs no code change to point at Postmark/SES/Resend (already scaffolded in `config/services.php`) once real keys land, same pattern as `SslCommerzClient` only needing credentials. `NotificationChannelResolver` resolves `email` → `MailDriver`; `sms`/`whatsapp` stay on their `Fake*Driver`.
- `App\Domain\Notification\Support\SmsSegmentCalculator` — real GSM-7/Unicode segment budgeting (160 vs 70 chars/segment) per docs/01 §1.6; `FakeSmsDriver` uses it instead of a hardcoded rate, so cost-tracking code is tested against the real rule.
- `app/Console/Commands/QueueEventReminders`, scheduled daily in `routes/console.php`, queues T-7/T-1/T-0 reminders per `EventSession`; each window is its own `template_key` so the outbox's dedupe constraint makes a same-day re-run a no-op.
- Bilingual (EN/BN) draft templates seeded for all 9 (event, channel) combos in the channel matrix via `NotificationTemplateSeeder`; three kill-switch rows (`notification.{email,sms,whatsapp}_enabled`) in `EventSettingSeeder`.
- Admin `NotificationController` (7 endpoints: delivery log, detail, resend, costs, kill-switches get/patch, templates) — `Notification\Actions\{ResendNotification,SetChannelKillSwitch}` write their own `ActivityLog` entry (D8 discipline: new code logs from the Action, not the controller). SPA `resources/js/features/notifications/` replaces the placeholder with delivery-log/costs/kill-switches/templates tabs, verified against a real login in a browser.
- 31 new backend tests (outbox dedupe and per-event queuing, job success/retry/exhaustion/kill-switch-cancel, `MailDriver` via `Mail::fake()`, reminder dedupe) plus 6 new `notification.*` HTTP permission round-trips.

**Still deferred, not silently dropped** — the same vendor-blocked items are listed under "Deferred by design" above:

- Real `SmsDriver` (no Bangladesh vendor named in any doc) and real `WhatsAppDriver` (Meta template approval still pending) — `NotificationChannelResolver` keeps both on `Fake*Driver`.
- DLR/delivery-status webhook endpoints — the signature/payload shape depends on which SMS/WhatsApp vendor gets picked; building one now would be guessing. The `NotificationEvent` model and delivery-timeline UI exist and are ready to receive real receipts once a webhook lands.
- Automated bounce/opt-out handling via provider webhooks — same reason. The `sent → bounced` state machine transition and `FakeEmailDriver`'s simulated bounce already exercise the state, just not from a real provider yet.
- Live cross-carrier delivery verification (GP/Robi/Banglalink/Teletalk) — inherently unmeetable without a live SMS vendor.

### ✅ Phase 4A (SSLCommerz Sandbox) — buildable-now slice closed 2026-08-04

Same split as Phase 3.5/5: build everything that's pure engineering now, flag what a real sandbox call or a real merchant account is needed for rather than guessing at it. SSLCommerz's sandbox credentials are self-service, so unlike bKash/Nagad/Rocket (still blocked on merchant applications — see External Dependencies below), the whole SSLCommerz money path is buildable today. It has **not** been proven against a live sandbox call in this environment, because no `SSLCOMMERZ_STORE_PASSWORD` was available — that first live smoke test is still outstanding and should happen before this is treated as production-ready.

**Shipped:**

- `App\Domain\Payment\Gateways\SslCommerzClient` implementing `PaymentGatewayInterface` — `createIntent` (session init), `verify` (val_id lookup, with a tran_id-lookup fallback for a lost/delayed IPN), `refund`, `parseWebhook` (IPN `verify_sign`/`verify_key` check, following the documented algorithm). `PaymentGatewayResolver::forMethod('sslcommerz')` now resolves to it; `bkash`/`nagad`/`rocket` stay on `FakeGateway`.
- `App\Http\Controllers\Api\Public\SslCommerzReturnController` — the `success`/`fail`/`cancel` browser-return legs SSLCommerz needs as real backend URLs (it POSTs to them, so they can't be the Next.js frontend directly). It reads and writes nothing; it only redirects to `FRONTEND_URL`, and only when the `next` query param's host matches — a hand-crafted request can't turn this into an open redirect.
- `App\Http\Middleware\EnsureIpnFromAllowlistedIp` (`ipn.allowlist:sslcommerz`, applied to `routes/webhooks.php`'s sslcommerz route) — source-IP allowlisting on top of signature verification. Deliberately a no-op when `SSLCOMMERZ_IPN_IP_ALLOWLIST` is unset: SSLCommerz's actual published IPN ranges aren't in any doc here, and a wrong guess would silently drop real payment notifications, which is worse than skipping this layer until someone pastes the real ranges in.
- `App\Domain\Payment\Actions\ExpirePaymentIntents`, scheduled every 5 minutes (`routes/console.php`) — closes D5. `CreateRegistration` now writes `payments.expires_at` from `payment.intent_ttl_minutes`; the sweeper re-verifies each expired-eligible payment against its gateway before expiring, so a delayed IPN is recovered instead of the registration being wrongly killed. `Payment::TRANSITIONS` (and docs/04-erd.md's diagram) gained `pending → expired` — the sweeper's own query always covered `pending`, the diagram just hadn't caught up.
- `App\Domain\Payment\Actions\RefundPayment` now actually calls `PaymentGatewayInterface::refund()` before touching any local state, and records the attempt as a `payment_transactions` row (`type=refund`) with the gateway's reference. A manual (personal-wallet) payment skips the gateway call entirely, since no money ever moved through one. Fixed in passing: `Refund::create()` was missing the NOT NULL `method` column — untested until now, since no prior test exercised a successful refund.
- `App\Domain\Payment\Actions\ReconcilePayments`, scheduled nightly (`routes/console.php`) — re-verifies every unreconciled `succeeded` payment against its gateway and classifies `matched` / `amount_mismatch` / `missing_at_gateway`. **`missing_locally` is not implemented** — it needs a gateway settlement-report enumerated by date, and `PaymentGatewayInterface` only supports looking up a transaction this system already knows about; building that generically across four gateways with different (and mostly unbuilt) settlement-export APIs would be guessing at a Phase 4B shape. Flagged here rather than silently skipped.
- 32 new tests: `SslCommerzClientTest` (session creation, minimum-amount guard, val_id and tran_id-fallback verification, signature validation, refund success/failure) via `Http::fake()`; `ExpirePaymentIntentsTest` (expires an abandoned intent, recovers one with a late/lost IPN, leaves manual-channel and not-yet-due payments alone); `ReconcilePaymentsTest` (all three implemented classes, manual-channel exclusion, no re-checking an already-reconciled row); `RefundPaymentTest` (gateway call, manual skip, SSLCommerz fails closed with no recorded bank transaction id); `SslCommerzReturnTest` (redirect allowlisting); `WebhookIpAllowlistTest` (no-op when unset, rejects/admits by configured IP).

**Still open:**

- A first live call against the real SSLCommerz sandbox (needs `SSLCOMMERZ_STORE_PASSWORD` provisioned) — the field names in `SslCommerzClient` follow the published v4 docs but are unverified against an actual response.
- The manual verification workflow's proof-upload half (duplicate-TrxID detection and the approval queue already exist from Phase 2/D-review era). Phase 3.5's `UploadContentMedia` is **not** it: that endpoint stores public, CDN-served CMS images, and a payment proof must be private with a short-TTL signed URL. Reuse its magic-byte validation and re-encode step; do not reuse its disk, `is_public`, or route. The private-disk-plus-signed-URL *serving* primitive this needs now exists — `MediaFile::temporarySignedUrl()` + `SignedMediaController` (`GET /api/v1/media/{mediaFile:ulid}`), built for ticket PDFs/QR images in Phase 6 (see below) — reuse it rather than building a second one; only the upload/validation half is still missing.
- `missing_locally` reconciliation (see above) and bKash/Nagad/Rocket real adapters — both wait on Phase 4B's merchant applications.

### ✅ Phase 6 (QR & PDF Tickets) — buildable-now slice closed 2026-08-04

Same split as Phase 3.5/4A/5: build everything that's pure engineering now, flag what needs real hardware, a printer, or a human-gated ops procedure rather than guessing at it. The critical path is Phase 2 → Phase 4A → **Phase 6** → Phase 7 → Phase 8 → Phase 9 (docs/08 §9.0), so this was the next unblocked slice once Phase 4A closed.

**Shipped:**

- `App\Domain\Ticketing\Support\QrPayload` — parses/encodes the `DTM1.<ulid>.<admits_total>.<exp_unix>.<key_id>.<sig>` format (docs/06 §6.5), replacing the ad hoc `explode('.', ...)` calls previously duplicated in `IssueTicket` and `ProcessCheckIn`.
- `App\Domain\Ticketing\Services\QrSigner` — the only code path that touches the private key. Ed25519 via libsodium (`sodium_crypto_sign_detached`/`_verify_detached`), config-driven (`services.qr_signing`, sourced from `QR_SIGNING_PRIVATE_KEY`/`QR_SIGNING_KEY_ID`/`QR_SIGNING_PUBLIC_KEYS`), supports multiple simultaneously-valid keys so a rotation doesn't invalidate previously-issued tickets. `IssueTicket::execute()` now signs for real instead of writing `'placeholder_sig'`.
- `ProcessCheckIn` now parses and verifies the signature and payload expiry via `QrSigner`/`QrPayload` **before** a ticket ever reaches `AdmissionPolicy` — closing the gap where knowing a ticket's ULID alone (a public-facing identifier, per this file's own ULID convention) was sufficient to gain admission. A forged or tampered payload gets the new `invalid_signature` check-in result; `signature_valid` is a real boolean now, not a hardcoded `true`. A manual override is the one path that legitimately skips verification, since `CheckInController` builds a synthetic marker payload for it, not a real scanned one.
- `App\Domain\Ticketing\Services\TicketNumberGenerator` + `ticket_number_sequences` table — replaces `IssueTicket.php`'s `Ticket::…->lockForUpdate()->count() + 1` (a full-table scan and lock on every issuance). One narrow row per (ticket type, batch label), incremented under `SELECT … FOR UPDATE` inside a transaction — proven race-free under real concurrent-process load (20 concurrent callers → exactly 1..20, no collisions). Note: an earlier attempt using MySQL's `LAST_INSERT_ID(expr)` trick to avoid the extra round trip **does not work reliably** for a genuinely new row — empirically, both PDO's `lastInsertId()` and a same-session follow-up `SELECT LAST_INSERT_ID()` return the table's own auto-increment id in that case, not the overridden value, despite what MySQL's docs imply. Don't reintroduce that pattern without re-verifying it against this MySQL version first.
- `App\Domain\Ticketing\Services\RenderTicketQrImage` (bacon/bacon-qr-code, `GDLibRenderer`, ECC level M, 512px) and `App\Domain\Ticketing\Services\GenerateTicketPdf` (mpdf) — a real bilingual (EN/Bangla) A5 ticket PDF with the QR embedded, event/session details, and the attendee's profile photo when one exists. Uses mpdf's bundled `freeserif` (GNU FreeFont) rather than a downloaded Noto Sans Bengali build: current Noto Bengali releases use an OpenType GPOS table (`Lookup Type 5, Format 3`) that mpdf's font engine can't parse, which — worse than an outright crash — silently drops complex-script shaping if OTL is disabled to work around it (conjuncts like `ক্ষ`/`প্র` render as broken base+virama sequences instead of the correct ligature). FreeSerif is mpdf's own verified choice for Indic scripts (`vendor/mpdf/mpdf-examples/example32_indic.php`) and needs no custom font registration.
- `App\Jobs\GenerateTicketAssetsJob` — the first job on the `tickets` Horizon lane, dispatched `->afterCommit()` from the new `Ticketing\Listeners\GenerateTicketAssets` on the existing `TicketIssued` event. Renders and stores the QR PNG and PDF as private `media_files` rows (`qr_codes.image_media_id`, `tickets.pdf_media_id`); idempotent by construction (no-ops once those ids are set), so a retry never creates duplicate media. Note for future work on this job: `image_media_id`/`pdf_media_id` are deliberately outside both models' `$fillable` (nothing else should mass-assign them), so setting them needs `forceFill()`, not `update()`/`fill()` — the latter silently drops the attribute with no error, which is easy to lose an afternoon to.
- Signed private-media serving: `MediaFile::temporarySignedUrl()` + `App\Http\Controllers\Api\SignedMediaController` (`GET /api/v1/media/{mediaFile:ulid}`, `signed` route middleware, `Storage::download()` for a real `Content-Disposition: attachment` + `X-Content-Type-Options: nosniff`) — a generic primitive, not ticket-specific, reusable by the still-open manual-payment-proof work (see Phase 4A above). `Attendee\TicketController::downloadPdf()` — previously returning the bare private storage `path`, a real gap since that endpoint predated this primitive — now returns an actual signed URL. `TicketResource` gained `qr_code_image_url` alongside the existing `qr_code_payload`.
- `ManifestController` gained real delta sync and published keys: a cold-start fetch (no `since`) still returns every currently-admissible ticket with an ETag for the 304 fast path; passing `?since=<version>` returns only tickets with a higher `manifest_version` — **without** the admissible-status filter, so a ticket that got voided/refunded/expired after the device's last sync still reaches it and the revocation-manifest layer (docs/06 §6.5) actually propagates. The response also publishes `meta.active_key_id` and `meta.keys` (every currently-known public key, including retired ones) so a scanner can verify a QR fully offline without a second endpoint. A successful fetch updates `check_in_devices.manifest_version`/`last_sync_at` via `forceFill()` (same non-fillable-column caveat as above).
- `php artisan qr-signing:generate-key` — prints a fresh Ed25519 keypair and the rotation checklist (docs/06 §6.5's publish → confirm device sync → switch-active ordering); `--if-missing` writes a keypair straight into `.env` and is wired into `composer setup` (right after `key:generate`) so a fresh checkout gets its own key instead of a secret shared across every clone.
- 26 new tests: `QrSignerTest` (sign/verify round trip, single-bit tamper detection, a mutated `admits_total` invalidating the signature, unknown key id, retired-key verification after rotation), `TicketNumberGeneratorTest`, `ProcessCheckInSignatureTest` (genuine signature admits; forged signature and a hand-crafted "valid-looking" payload with a real ticket ulid are both rejected; expired payload rejected even with a valid signature; malformed payload; manual override bypass), manifest delta/keys/device-version cases added to `ScannerFlowTest`, `GenerateTicketAssetsJobTest`, `TicketPdfDownloadTest` (owner download, cross-attendee 404, not-yet-generated 404, tampered signed URL 403).

**Still open — flagged, not silently dropped:**

- **Physical print/scan testing** — docs/08 Phase 6 notes this "is not optional and cannot be simulated": a cracked screen, 40% brightness, laser/inkjet print, and a photocopy all need a real device and a real printer. Nothing in this environment can exercise that; it stays a Phase 8 gate-rehearsal-adjacent task.
- **The live device-rotation rehearsal.** The crypto and tooling for rotation exist end-to-end (multi-key `QrSigner`, `qr-signing:generate-key`, retired keys published in the manifest), but the *procedure* — Super Admin, re-auth, publish → wait for confirmed device sync → only then switch the active key, notify Event Managers (docs/06 §6.5) — is a staged human/ops workflow with no admin-API endpoint or SPA screen built for it yet. Rotating today means manually editing `.env` in the right order.
- **Manifest delta sync has not been load-tested against a 12,000-ticket cold start** (docs/08 Phase 6 exit criterion) — the query shape (`whereIn('status', …)` with no pagination) is the same one that existed before this slice; making it paginate/stream is a Phase 8 load-testing concern, not something this slice changed.

### ✅ Phase 7 (Mobile Verification App) — core scan loop closed 2026-08-04

Unlike every other phase, this one's actual deliverable lives **outside this repo** — `decent-event-scanner`, a sibling Expo/React Native/TypeScript project, per this file's own "Three frontends, two repos" split. This section tracks only what changed *here*; see that repo's README for the full scope, architecture notes, and deferred list.

**Shipped here (backend):**

- `POST /scanner/v1/enrol` (`App\Http\Controllers\Api\Scanner\DeviceEnrolmentController`) now also returns `gates: [{ulid, code, name, event_session_id, allowed_ticket_type_ids}]` — the volunteer's active assigned gates. This closes a real gap: nothing previously told a scanner device which `gate_id` it's allowed to submit scans under (`EnsureGateAssigned` middleware enforces the assignment, but there was no way to *discover* it). Filters to `is_active` gates only; a revoked/deactivated gate silently drops out rather than erroring.
- New test in `tests/Feature/Scanner/DeviceEnrolmentTest.php` asserting only the volunteer's active assigned gates come back, with `allowed_ticket_type_ids` intact (the scanner app needs it to run the same wrong-gate check offline that `AdmissionPolicy` runs server-side). Pint and PHPStan level 8 clean; OpenAPI spec regenerated (still 104 paths — this is a response-shape addition to an existing documented endpoint, not a new one).

**Shipped in `decent-event-scanner`** (see that repo for detail):

- Enrolment, manifest cold-start + delta sync into local SQLite, fully-offline Ed25519 QR verification (`tweetnacl`, a deliberate port of `QrPayload.php`/`QrSigner.php`), a local admission policy (a port of `AdmissionPolicy.php`) that runs against the synced manifest with no network call, a local admission counter so a second offline scan of the same ticket is caught as a duplicate before any sync, an idempotent batched scan-upload queue with exponential backoff, and a camera scan screen with a party-size confirmation step and a green/red result banner.
- 38 Jest unit tests covering the ported QR-parsing and admission-policy logic, including adversarial cases: forged signature, single-bit tamper of `admits_total`, a hand-crafted payload naming a real ticket ulid but without the real private key, expired-but-validly-signed payload, unknown/retired key ids.

**Deferred, not silently dropped** (full list and reasoning in `decent-event-scanner`'s README):

- Manual lookup by mobile last-4/ticket number, and the override-request-to-Event-Manager flow — both need backend endpoints that don't exist yet.
- Crash reporting, an offline diagnostic log, and sync-status UI polish (battery, last-sync detail).
- A conflict-resolution UI for the two-devices-scanned-the-same-ticket-offline case — the data (`conflict_flag` from `POST /scanner/v1/scans`) is captured, but nothing surfaces it to the volunteer yet; the next manifest sync silently reconciles to the server's count.
- **All physical-device testing** — autofocus, haptics, battery life, and the roadmap's own exit criteria (500 consecutive offline scans, 12,000-ticket sync under 90s on 4G, 8-hour battery, an untrained volunteer completing 20 correct scans) need real hardware this environment doesn't have. Matches this file's own standing rule for Phase 6 that physical testing "is not optional and cannot be simulated."

### ✅ Phase 8 (Testing & Security Audit) — buildable-now slice landed 2026-08-04

Same split as every prior phase: build what's pure engineering now, flag what needs a pentester, real hardware, or real users rather than guessing at it. Unlike Phases 3.5–7, this is a genuinely small slice of a four-week phase — most of Phase 8's deliverable list (load testing at real scale, third-party pentest, OWASP ZAP scan, full-scale gate rehearsal, DB failover drill, backup restore drill, UAT with 50 real alumni) needs infrastructure, hired specialists, or real people this environment cannot produce. Do not read this section as Phase 8 closing.

**Shipped:**

- **R12 fixed for the two tests it names.** `PurchaseConcurrencyTest` and `CheckInConcurrencyTest` previously carried Phase 2's "300 concurrent purchases sell exactly 100" / "20 concurrent scans admit exactly once" exit-criteria names but only ever called `tryReserve()`/`confirmSale()`/`tryAdmit()` sequentially in one process — a `writeConcurrencyScript()` helper that would have proven the real thing existed but was never invoked. Neither test could have caught a genuine race condition; this is exactly the docs/08 R12 failure mode ("a phase is marked done on the strength of a test that exercises the wrong code path"), already real once (D1/D2) before this. Both now spawn real OS subprocesses via `tests/Support/concurrency_worker.php`, each its own MySQL connection, racing the same row through the exact production methods. `PurchaseConcurrencyTest` batches its 300 workers in groups of 60 — 300 simultaneous connections reliably exhausts a default MySQL install's `max_connections=151` (confirmed: exactly 149 of 300 errored before batching), so uncapped concurrency was silently turning "worker errored" into passing-test noise rather than a real signal.
- **E2E scenario coverage completed.** docs/08's Phase 8 deliverable list names five registration paths — single, couple, family, sponsor, VIP. Single and family already existed in `EndToEndTest`; couple, sponsor, and VIP did not. Adding the sponsor/VIP cases surfaced a real, independent bug (not a test gap): `TicketTypeSeeder` seeds Sponsor/VIP ticket types with `allowed_participant_types` of `sponsor`/`guest`, but `participant_type` validation in `StoreRegistrationRequest`, `UpdateAttendeeRequest`, and both ticket-type admin requests only ever accepted the six alumni-facing values — nobody could actually register for those ticket types through the public API. Fixed by adding `guest`/`sponsor` to the validated enum in all four places (and the SPA's `ParticipantType`/`PARTICIPANT_TYPES` in `resources/js/features/attendees/types.ts`); `test_flow_with_sponsor_ticket_registration`/`test_flow_with_vip_ticket_registration` prove the full path now issues a ticket.
- **Bangla text correctness, PDF stage — one real bug fixed, one real bug found and flagged, not silently dropped.** Added `holder_name_bn` to `tickets` (previously only the Latin name was snapshotted, so the PDF had no Bangla name to render at all) and a `pdftotext`-based regression test. That surfaced two distinct defects in the Phase 6 PDF pipeline, both confirmed with `hb-shape`/`pdftotext` against the actual rendering path, not guessed at:
  1. **Fixed.** `FreeSerifBold.ttf` (mpdf's bundled bold weight) has zero Bengali glyph coverage — every Bengali codepoint maps to `.notdef`. The ticket template's `.value { font-weight: bold; }` didn't degrade Bangla text, it made it disappear from the page outright. `resources/views/tickets/pdf.blade.php`'s new `.bn-value` class opts the one dynamic Bangla field (`holder_name_bn`) back out of bold. Any *other* template that puts Bangla text in a bold context needs the same treatment — this is a font-file limitation, not something scoped to tickets.
  2. **Found, not fixed.** Independent of bold: mpdf's built-in Bengali OTL engine does not emit a correct ToUnicode CMap entry for consonant-conjunct clusters (e.g. `দ্দ`) — the PDF's extractable text layer gets a private-use-area codepoint in place of the conjunct, and pre-base vowel signs (ি/ে/ৈ) extract in visual rather than logical order. This directly contradicts this file's own prior Phase 6 claim that FreeSerif "needs no custom font registration" and is a verified Indic choice — that claim was aspirational, not tested against a conjunct-bearing name until now. Visually the print may still look approximately right (still unverified — physical print testing stays out of scope per below); what's proven broken is text-layer fidelity, which affects copy-paste, search, and accessibility tooling for a large share of real Bengali names. No fix is implemented: it needs either a HarfBuzz-based pre-shaping pass feeding mpdf pre-shaped glyph runs, or a different PDF rendering engine. See `GenerateTicketPdf.php`'s docblock for full reproduction detail, and `GenerateTicketAssetsJobTest`'s Bangla test for exactly what is and is not asserted as a result — it deliberately does not assert the attendee's name survives byte-for-byte, only that Bangla text is not silently dropped (the bold regression) and that a conjunct-free label round-trips exactly.
- 5 new/rewritten tests (2 rewritten concurrency tests using real subprocess workers, `test_flow_with_couple_registration`, `test_flow_with_sponsor_ticket_registration`, `test_flow_with_vip_ticket_registration`); Pint and PHPStan level 8 clean; full suite green (339 tests).

**Still open — this is most of the phase, not a residual:**

- Load testing at the scale docs/07 §7.8 specifies (registration spike, payment concurrency, offline sync burst, notification blast, export under load, read-heavy browse) — only the capacity race and duplicate-scan race got a real concurrency test this pass.
- Third-party penetration test, OWASP ZAP scan, explicit T1–T12 threat-model verification, full git history secret scan — need a hired security firm or tooling not run here.
- Full-scale gate rehearsal (20+ devices, 500+ tickets, simulated network failure), DB failover drill, backup restore drill, every kill switch exercised, incident response runbook walkthrough — all need real infrastructure and named responders.
- UAT: client acceptance across admin workflows, and a 50-real-alumni pilot registration (10+ over age 60) — needs real users, not something this environment can simulate.
- The Bangla PDF conjunct/ToUnicode defect above — flagged, not fixed.

### ✅ Phase 9 (Production Deployment) — buildable-now slice landed 2026-08-04

Same split as every prior phase, applied to a phase where it matters more than usual: docs/09's own dependency ("Depends on: Phase 8 sign-off") is not met — Phase 8 above is a narrow slice, not closed — and unlike Phases 3.5–8, most of what's left in this phase (production infrastructure, CDN/WAF, live gateway cutover, on-call, event-day operations) is not achievable in any dev sandbox, ever, regardless of how much engineering time is spent — it needs a real hosting account and real people. Started ahead of the formal dependency at explicit user request; do not read this section as Phase 9 — or Phase 8 — closing.

**Shipped:**

- **The docs/06 §6.7 security header set, applied globally.** `App\Http\Middleware\SetSecurityHeaders` (registered via `$middleware->append()` in `bootstrap/app.php`, so it covers `web`, `api`, `scanner`, and `webhooks` alike) sends `Strict-Transport-Security`, `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: strict-origin-when-cross-origin`, and a nonce-based `Content-Security-Policy` with no `unsafe-inline` on `script-src`. This closes a real half-built mechanism, not a from-scratch feature: `resources/views/app.blade.php` has called `Vite::cspNonce()` on its inline theme-flash script since the Phase 3 SPA scaffold, but nothing had ever called `Vite::useCspNonce()` to generate one, so that call was silently rendering `nonce=""` with no CSP header to make it mean anything. The middleware generates the nonce before `$next()` runs, so the same value reaches the blade view, every `@vite`-emitted tag, and the CSP header. `style-src` keeps `'unsafe-inline'` deliberately — CSP nonces don't apply to inline `style` attributes in any shipping browser, only `<style>` elements, so enforcing strict `style-src` against a React/Radix-style component tree would need a runtime-regenerated hash allowlist; `script-src` — the control that actually stops XSS — has no such exception. CSP is suppressed only in `local` (Vite's dev-server HMR injects unnonced style/script tags no strict policy survives, and no third party ever reaches a local dev box), matching this codebase's one prior precedent for an environment-gated exception (`AuthController`'s local-only 2FA bypass). `Permissions-Policy` denies `camera` outright rather than restricting it to "the scanner origin" as docs/06 literally reads — the scanner is a separate React Native app (see "Three frontends, two repos" below), never a web origin this app serves, so there is no camera grant to carve out here. 5 tests in `SecurityHeadersTest`, including one that parses the actual `/login` HTML response and asserts its inline script's `nonce="..."` attribute matches the CSP header's `'nonce-...'` token byte-for-byte — proving the wiring end-to-end, not just that headers exist.
- **A real `/up` health check.** Laravel's built-in `health: '/up'` route (`bootstrap/app.php`) only ever proved the app booted; `App\Listeners\CheckApplicationHealth` (registered on the framework's own `DiagnosingHealth` event in `AppServiceProvider::boot()`) now checks the primary database, the default filesystem disk actually being writable, and Redis, running every check even if an earlier one fails so one hit surfaces every broken dependency rather than one per retry. This is what docs/07 §7.3's load balancer and uptime/synthetic monitoring are meant to poll. **The Redis probe is conditional as of 2026-08-22** — it runs only when the active cache store, queue connection or session driver resolves to redis (`CheckApplicationHealth::usesRedis()`), because a deployment keeping all three in MySQL never opens a Redis connection and probing one anyway reported `down` on a host serving every request. A permanently-red health check is worse than none: it is ignored by the time it means something. `config/horizon.php` is deliberately not consulted — it names the redis connection on all four supervisors unconditionally, so reading it would make the probe unskippable again; Horizon only has work when the queue connection is redis, which is already covered. `HealthCheckTest` proves the healthy 200, a real unreachable-database 500, the skip, and that any one of the three drivers alone is enough to make Redis a dependency.
- **Backup + verified-restore tooling, proven against real MySQL — the provable half of "encrypted backups with verified restore."** `db:backup` (`mysqldump --single-transaction`, credentials passed via a `chmod 600` `--defaults-extra-file` temp file so they never appear in `ps aux`, never `MYSQL_PWD`) writes a gzip dump plus a `.meta.json` sidecar recording a per-table row count at backup time. `db:restore --verify` restores the dump into a disposable scratch database (`{db}_verify_<random>`), diffs its table row counts against exactly that manifest — not against whatever the live database happens to contain when the restore runs, which could be a materially different day — then drops the scratch database; the live database is never opened for writing in this mode. A real restore (no `--verify`) is gated behind `--force` specifically so it can never be a flag-order slip during an incident — there is no confirmation prompt to click through by muscle memory. `db:backup` is scheduled nightly (`routes/console.php`). **What this does not close:** the dump is gzip-compressed, not encrypted — encryption-at-rest and offsite replication need a real object store and a key-management decision this environment doesn't have, so ship the gzip through a storage provider's server-side encryption (or `gpg --encrypt` it) before it leaves the box. 3 tests in `DatabaseBackupRestoreTest`, using `DatabaseMigrations` rather than `RefreshDatabase` for the same reason `PurchaseConcurrencyTest` does (docs/08 Phase 8 section): `mysqldump`/`mysql` are real separate OS processes with their own MySQL connections, so they cannot see rows created inside `RefreshDatabase`'s uncommitted wrapping transaction.
- **A provider-agnostic production Dockerfile + `docker-compose.prod.yml` — NOT build- or run-tested.** This development environment has no Docker daemon at all (`which docker` finds nothing), so `docker build` has never actually been run against this image; every extension choice (sodium, gd, bcmath, zip, intl, pdo_mysql, redis via PECL) matches what this checkout's own `php -m` reports loaded rather than being guessed, but the build itself needs a first real run — the same category of gap as `SslCommerzClient` shipping unverified against a live sandbox call, and for the same reason: nothing here can provision the missing piece. Multi-stage (`composer:2` → `node:22-alpine` → `php:8.3-fpm-bookworm`); nginx and php-fpm are bundled into one image via `supervisord` rather than split across two containers sharing a volume — nginx's `fastcgi_pass` hands PHP-FPM a bare filesystem path, so the two processes must see identical files at identical paths, trivial in one container and a well-known footgun across two synchronized only by a volume something has to remember to populate. `docker-compose.prod.yml` is a reference topology (`app`, `horizon`, `scheduler` as separate containers — matching docs/07 §7.3's "workers dedicated, not co-located with app instances" — plus single-box `mysql`/`redis`, which is not what docs/07 §7.3's sizing assumes: a managed database with a replica and a hot standby), meant to be translated into whatever orchestrator a hosting decision lands on, not a deploy target itself.
- **Release + deploy pipeline** (`.github/workflows/deploy.yml`, renamed from `deploy-image.yml` when the deploy jobs landed 2026-08-22 — see [§Deploy](#-deploy-jobs-added-following-decentedu--2026-08-22)): on push to `main`, gates on the full migration set applying cleanly to a fresh database (`migrate --pretend` then `migrate --force` against an ephemeral MySQL service, mirroring `backend-ci.yml`'s already-proven service-container pattern), then builds the image, boot-checks it (`php artisan --version` inside the built container — proves vendor autoloading and PHP extensions don't fail outright, deliberately short of a full DB/Redis smoke test, which is a second unverified surface this slice didn't take on), and publishes to `ghcr.io` — chosen specifically because it needs no new secret beyond the `GITHUB_TOKEN` every workflow already gets, so this doesn't block on provisioning anything either. This is genuinely this Dockerfile's first real build: GitHub Actions runners have a real Docker daemon, this dev sandbox does not. **No longer stops at "publish a tagged image"** — staging and production deploy jobs were added 2026-08-22, modelled on the sibling `decentedu` project. They are still a no-op until a host is configured, because no hosting provider is chosen (External Dependencies below); what changed is that picking one is now a repo-variable away rather than a workflow to write.
- 8 new tests total (5 `SecurityHeadersTest`, 2 `HealthCheckTest`, 3 `DatabaseBackupRestoreTest` — note: 8 test *methods*, not double-counted against the numbers above); Pint and PHPStan level 8 clean on every new file.

**Still open — this is nearly all of the phase, more so than Phase 8's "most":**

- **A hosting provider decision.** Nothing in docs/07 names one (§7.3 specs sizing and topology, not a vendor) — this is now the load-bearing blocker for everything else in this list, structurally identical to the unpicked SMS vendor blocking Phase 5's real driver.
- Production infrastructure itself: real servers/containers running the image above, CDN, WAF, TLS/HSTS preload registration, a managed database with a read replica and hot standby, log aggregation, APM, an on-call rotation — all need the provider decision first.
- The actual `deploy` step: rolling the published image out to a live host with zero downtime and a tested rollback. The image and the CI gate that publishes it exist; the step that points them at a target does not.
- Live gateway credentials (blocked on Phase 4B's merchant applications, tracked separately below) and a production QR signing key generated via the existing `qr-signing:generate-key` — mechanically ready, just not run against a production environment that doesn't exist yet.
- Registration launch, first-48-hours heightened monitoring, daily reconciliation review during the live window — all presuppose the site is actually live.
- Every event-day operational item in docs/07 §7.7 (T-7 scale-up, T-24 deployment freeze, T-2 volunteer briefing, live ops dashboard, on-site engineer, escalation path) — a staged human/ops procedure for a real event, not something a dev sandbox can rehearse, matching this file's standing rule for Phase 6/7's physical-device testing.

### ✅ Centennial ticket system merged into the real registration flow — 2026-08-14

The public site's `/tickets#register` page (repo `centennial-celebration`, sibling to this one) shipped as a **fully static** ticket system priced from a frontend constants file, with `confirm()` minting a local `CEN-{batch}-{XXXXX}` string and no network call anywhere. It is now backed by this system end to end.

**One ticket, family optional.** The page originally sold a single/family *pair*; that collapsed into one `CEN` ticket type on which family is optional for every participant type. A lone registrant is simply a party of one, so there is no ticket kind to pick and no way to pick the wrong one. The tiered pricing columns carry the whole rule with no new pricing path:

| | |
|---|---|
| registrant | `base_price_paisa` — ৳2,500 |
| each extra adult | `additional_adult_price_paisa` — ৳2,000 |
| each extra child | `additional_child_price_paisa` — ৳2,000 |
| child under 2 | free, **still admitted** |

A third tier was added 2026-08-21 — a current student pays `current_student_price_paisa` for their own seat, not `base_price_paisa`. See [§A third price tier](#-a-third-price-tier-what-a-current-student-pays--2026-08-21).

**The real schema gap this exposed.** A child under 2 attends free but still walks through the gate. `children_count` was read by two things at once — pricing in `CreateRegistration`, and `admits_total` in `IssueTicket` (`adults + children`) — so excluding a free infant to get the price right would have under-counted the admits and the gate would have turned the infant away. Migration `2026_08_14_100000_*` adds `registrations.infants_count` (never priced, always admitted; `admits_total = adults + children + infants`) and `ticket_types.child_free_under_age` (`NULL` on every pre-existing type, so their pricing is byte-identical).

**Infants are counted server-side from the guests' own ages**, never from a client-supplied count — otherwise a caller could mint free admits by declaring a party of infants. Contract: `children_count` is *every* child attending, infants included; `CreateRegistration::countFreeInfants()` splits it and stores `children_count` as the billable half. A child guest sent without an age is billed, not waved through.

**Participant type is now asked for and enforced.** The form's dropdown is built from the ticket type's own `allowed_participant_types`, and `CreateRegistration` enforces it — **closing part of D7**, which had been stored, published on `TicketTypeResource` and rendered by the public site since Phase 2 while nothing ever checked it (a Sponsor-only ticket would have sold to anyone naming its ULID). Enforcement runs *before* `tryReserve()`, so a refused registration never holds capacity. An empty list still means "open to everyone", matching how the frontend already read it. `ssc_batch_year` is asked for only from a current/former student, mirroring the API's own `required_if`.

**Shipped here:**

- `CEN` seeded in `TicketTypeSeeder` (`base_admits` 1, `max_admits` 9, `child_free_under_age` 2, 12,000 capacity). `guest`/`sponsor` are deliberately excluded from its audience — they have their own VIP/SPN types, which are `is_public=false`/`requires_approval=true` and must not become self-serve at the centennial price. The short-lived `CEN-SINGLE`/`CEN-FAMILY` pair is **retired, not deleted** (`is_active`/`is_public` false): `registrations.ticket_type_id` is `ON DELETE RESTRICT`, so an environment that sold one cannot drop the row without destroying that history.
- **Fixed in passing — a real bug:** `TicketTypeSeeder` never set `sale_starts_at`, and the public endpoint filters `sale_starts_at <= now()`. SQL's `NULL` comparison is not true, so **every seeded ticket type was invisible to the public API** regardless of `is_active`/`is_public`. Backfilled after the upsert so a re-seed never drags an admin-chosen opening date forward.
- `RegistrationRejectedException` — sold-out and participant-type rejections are caller error and now render as **422** in the uniform `{code, message, request_id}` envelope. They were `RuntimeException` → 500, which the form would have shown as "Something went wrong" instead of the actual reason. (Sold-out was pre-existing; it 500'd too.)
- `POST /public/registrations/{registration}/photo` + `Registration\Actions\UploadAttendeePhoto` — the badge photo the form collects. Deliberately **not** Content's `UploadContentMedia`: that writes the public CDN disk with `is_public=true`, right for a sponsor logo and wrong for a photograph of a private individual. Same validation discipline (magic bytes decide the type, full GD re-encode stripping EXIF/GPS, randomised name), but private disk + short-TTL signed URL, reusing Phase 6's `MediaFile::temporarySignedUrl()`/`SignedMediaController`. Downscales to 1024px. Scoped to a registration and accepted only while `pending_payment` — that scoping is what keeps it from being an open public file drop; `throttle:10,1` on top. `ActivityLog` written from the Action (D8 discipline).
- `child_free_under_age` on `TicketTypeResource`, `infants_count` on `RegistrationResource`, both admin ticket-type requests accept the new column. OpenAPI regenerated — **105 paths** (was 104).
- 15 tests (`tests/Feature/Public/CentennialTicketFlowTest.php`): party-of-one vs. family on the same ticket type, member rates, the free infant priced out *and* admitted (`admits_total == 4` for a party of 4 with one infant), the exactly-2 boundary, free infants unclaimable without matching guest rows, an ageless child billed, a no-rule ticket type unchanged, all six allowed participant types buying, a disallowed one refused 422 without reserving capacity, an unrestricted ticket selling to anyone, and the photo endpoint (private disk, signed URL, disguised-PHP-script rejected 422, refused once out of checkout).

**Shipped in `centennial-celebration`:**

- `features/ticket-system/config.ts` holds **no money** — only copy, FAQs and the `TICKET_CODE` join. Prices, admit limits, the free-infant age and the participant-type list all come from `/public/ticket-types` via `resolve.ts`. `/tickets` moved from `force-static` to `revalidate = 300`, so a price edit in the admin console reaches the page without a deploy; the form re-fetches on the client, so a stale card can't lead to a stale checkout.
- Four steps (was five): Your Details → Family (optional, starts empty, skippable) → Summary → Confirm. `participation_type` is **derived** from the party rather than asked. `pricing.ts` mirrors `CreateRegistration`'s formula exactly and is only ever an estimate — the confirmation screen renders the server's `total_paisa`, and no price is sent to the API.
- `confirm()` creates a real registration (idempotency key per submission), uploads the held photo, and shows the real `registration_number`. Payment is a deliberate second act (a button, not an auto-redirect) so a failed gateway session leaves a recoverable registration rather than stranding the reader with no reference. A failed *photo* upload is non-fatal by design — the seats are already held.
- `gender` is now collected (the API requires it). Guest `gender` is omitted rather than defaulted, so nothing guesses a person's gender to satisfy a type.
- **`/register` is a `permanentRedirect` to `/tickets#register`**; the six-step `RegistrationWizard`, its step components, wizard store, `fieldSteps.ts` and the already-dead `TicketBuyForm` are deleted. Two wizards over one endpoint meant two sets of client validation to keep in step with the server, and they had already drifted.
- **Fixed in passing — a second real bug:** the frontend `Registration` type declared `total_amount_paisa`, a field `RegistrationResource` has never returned (it returns `total_paisa`). Every reader guarded with `typeof … === "number"`, so `RegistrationSummaryCard` had silently never rendered a total. `ParticipantType` was likewise missing `guest`/`sponsor`, which the API has accepted since Phase 8. Exactly the hand-written-types drift this file's SPA conventions warn about.

**Verified against the running stack**, not just unit tests: a teacher registering alone with no batch year → ৳2,500; a guardian + spouse + 1-year-old → ৳4,500 with `infants_count: 1`; a sponsor → 422 `participant_type_not_allowed`; the photo endpoint downscaled 1600×1200 → 1024×768 behind a signed URL and rejected a PHP script named `evil.jpg`. Backend 363 passed / 1 skipped, Pint + PHPStan level 8 clean; frontend `tsc --noEmit`, ESLint and `next build` clean.

**Still open:**

- ~~The public flow initiates payment on the default `bkash` method.~~ **Closed 2026-08-14** — the default is now `sslcommerz` and the whole path is verified against the live sandbox; see [§SSLCommerz live-sandbox verification](#-sslcommerz-sandbox-verified-live-and-two-real-defects-fixed--2026-08-14).
- **The registrant pays ৳2,500 and each added member ৳2,000.** Under the superseded family ticket every head paid a flat ৳2,000, so a family of four now costs ৳8,500 rather than ৳8,000. This is a seeder-only change (`base_price_paisa`) if the flat reading was intended — no code depends on the three rates differing.
- The seat-hold copy says "while the payment window is open"; the real TTL is `payment.intent_ttl_minutes` (default **30**). Someone should set that deliberately.
- No bilingual (BN) rendering on the ticket page; `name_bn` is seeded but the page renders English only.

### ✅ Attendee mobile and email are both unique — 2026-08-15

An attendee's mobile number and email address each identify exactly one person, enforced at the database *and* at every write path.

`attendees.mobile` had carried `uk_attendees_mobile` since the table was created (ADR-08 dedupes attendees on it), but **nothing validated it** — so an admin editing an attendee onto a taken number hit the constraint raw and got a **500** with a SQL error in the log instead of a field-level 422. `attendees.email` had only a plain lookup index, so one address could be spread across any number of attendees, leaving every email notification, magic-link and export with no single person to point at.

**The rule cannot be enforced the same way at every entry point, and that is the load-bearing detail.** The public registration path matches a *returning* registrant on their mobile number, so there a repeated mobile is expected, not a conflict — a `unique` rule on `StoreRegistrationRequest` would break re-registration. Email is only a conflict when it is held by somebody *other* than the attendee the registration resolved to, which is a fact only `CreateRegistration` knows. So:

| Path | mobile | email |
|---|---|---|
| `POST /public/registrations` | dedupe key — repeats are the returning registrant | checked in `CreateRegistration::assertEmailAvailable()` against the *resolved* attendee → 422 `email_already_registered` |
| `PATCH /admin/attendees/{ulid}` | `Rule::unique` ignoring self | `Rule::unique` ignoring self |
| `PATCH /attendee/me` | not self-editable (it is the login channel) | `Rule::unique` ignoring self |

- **`App\Domain\Registration\Support\AttendeeIdentity`** is the single definition of how both identifiers are normalised before being compared or stored — `normaliseMobile()` (strip everything but digits and a leading `+`, extracted from `CreateRegistration`'s inline `preg_replace`) and `normaliseEmail()` (trim, lowercase, blank → null). Uniqueness is only as good as the normalisation behind it: `+880 1711-223344` and `+8801711223344` are one person to a human and two rows to a `UNIQUE` index, and the admin path did not normalise at all, so an admin edit could quietly create the duplicate the public path exists to prevent. Every write path goes through it. It deliberately does **not** canonicalise `01711…` into `+8801711…` — that guess is wrong for overseas alumni.
- **Blank-string email → NULL is load-bearing, not tidiness.** MySQL permits many NULLs under a unique index but only one `''`, so a form posting `""` for an omitted email would let the first attendee through and reject every one after.
- **The email refusal happens before `tryReserve()`**, alongside the participant-type check, so a registration that cannot be created never sits on a seat someone else could have bought. Test asserts `sold_count` is unmoved.
- **Both constraints cover soft-deleted rows**, exactly as `uk_attendees_mobile` already did — MySQL 8 has no partial index, and excluding them would mean a generated column and a *different* constraint shape on each of the two identifiers. So a soft-deleted attendee keeps hold of their email and mobile; releasing them means force-deleting or clearing the row. The validators deliberately do **not** use `withoutTrashed()`: if they disagreed with the index, the 422 would turn back into a 500 the moment the conflict was with a deleted attendee.
- **The migration refuses to run rather than pick a winner.** If any address is shared, it throws naming the duplicates and the query to find them. Choosing which attendee keeps a shared address is a merge decision — they have registrations, payments and issued tickets hanging off them, and `merged_into_attendee_id` exists so a human can record it. Nulling the losers silently at deploy time, with no audit trail, would destroy contact details for real ticket-holders.
- **Seeders had to change or they would start failing at volume:** `AttendeeFactory` now uses `fake()->unique()->safeEmail()`, and `LoadTestSeeder` derives the address from the row's own ULID — 22,000 rows is far past what faker's email pool can supply distinctly, and `unique()` throws an `OverflowException` when it gives up rather than silently repeating. ULID rather than loop index specifically so a second run on an already-seeded database doesn't collide on `attendee0@…` all over again. Verified: two full 22,000-attendee runs, 26,422 emails, zero collisions.
- 16 tests in `tests/Feature/Attendee/AttendeeUniquenessTest.php`. Three pre-existing tests were fixtures giving two *different* people one email (`EndToEndTest::test_flow_handles_idempotency_key`, two in `CentennialTicketFlowTest`) — fixed as fixtures, not by weakening the rule. Full suite 400 passing, Pint and PHPStan level 8 clean. OpenAPI regenerated (still 107 paths — the public registration 422 now documents its three `code` values).

**Found while verifying, not fixed — out of scope and unrelated to uniqueness:** `DummyDataSeeder` and `LoadTestSeeder` both number registrations `REG-100Y-%06d` from 1, so neither is re-runnable on a populated database and running both on one database collides on `registrations.uk_registrations_number`.

### ✅ Registration collects four more attendee details — 2026-08-16

The registration form now asks every registrant for their **name in Bangla, father's name, occupation, and current address**, and refuses a submission missing any of them. Two of the four are new columns; two already existed and were simply never required.

**The load-bearing asymmetry: required at the public form, nullable in the column.** `attendees.father_name` and `attendees.current_address` (migration `2026_08_16_100000_*`) are both nullable, and so are the two pre-existing columns. That is deliberate, not an oversight — "required" is a rule about what the *public form* may submit from now on, while the column only records whether the answer is known. Every attendee row that predates this has none of them and there is nothing truthful to backfill with, so a `NOT NULL` column would have forced a fabricated value onto 22,000 real people.

| Path | the four fields |
|---|---|
| `POST /public/registrations` | **required** — a submission missing any one is a 422 with that field named |
| `PATCH /admin/attendees/{ulid}` | `nullable` — an admin *corrects* records and creates none |
| `PATCH /attendee/me` | `nullable` — same reason; `father_name`/`current_address` added alongside the `occupation` it already accepted |

Tightening the admin path to match the public one is the tempting mistake: it would make every unrelated edit to a legacy attendee — adding a note, flagging them verified — impossible to save until someone invents a father's name for them. `test_an_admin_may_edit_a_legacy_attendee_that_has_none_of_them` exists to stop that.

- **`current_address` is one free-text `VARCHAR(255)` line**, beside the existing `address_district`/`country` rather than replacing them. A Bangladeshi address is written as prose (village/road, thana, district), nothing here parses it, and the alumni filling this in span five decades of age — a four-field structured address block is a form they abandon. `address_district` stays as the coarse column reports segment on.
- **Fixed in passing — a real 500:** `full_name`/`full_name_bn` were validated `max:200` in all three FormRequests while both columns are `VARCHAR(150)`, so a 151–200 character name reached MySQL and died there instead of returning a field-level 422. All three now validate `max:150`. Nothing previously exercised it because no fixture had a name that long.
- **`AttendeeFactory` now always sets all four** rather than leaving them to a `boolean(50)` coin flip — the public form requires them, so a factory attendee that routinely lacks one does not resemble a real registrant. `full_name_bn` draws from a fixed pool of real Bangla names (Faker ships no `bn_BD` name provider) deliberately including conjuncts (`ক্ষ`, `প্র`, `দ্দ`) and pre-base vowel signs — the exact shapes the ticket PDF's text layer still gets wrong (see `GenerateTicketPdf`'s docblock), so a fixture is never accidentally conjunct-free and quietly passing. `LoadTestSeeder`'s raw inserts got the same treatment.
- Admin SPA: the attendee dialog gained Father's name, Occupation and Current address controls (`resources/js/features/attendees/`); `types.ts` gained all four on `Attendee` and on `UpdateAttendeePayload`.
- 7 tests in `tests/Feature/Public/AttendeeProfileFieldsTest.php` (stored on create, each field required, blank string ≠ present, a returning registrant updates all four, the public response exposes them, both admin-edit cases). Ten pre-existing registration payload fixtures across `EndToEndTest`, `PublicFlowTest`, `AttendeeUniquenessTest` and `CentennialTicketFlowTest` were updated. Full suite **407 passing**, Pint and PHPStan level 8 clean, SPA `typecheck` + `build` clean. OpenAPI regenerated (still 107 paths — a request/response shape change to documented endpoints, not a new one). Verified against the running app: a full submission stores and returns all four; omitting them returns 422 naming exactly those four.

**Shipped in `centennial-celebration` the same day** — the public ticket page collects all four, so no submission is left failing validation:

- `StepAttendee` gained the four controls: the Bangla name full-width with `lang="bn"` (a Bangla name set at the same size as its Latin sibling reads as a translation field people skip, and this is what the ticket PDF prints), father's name and occupation paired in a two-column row, and current address as a **`Textarea`, not an `Input`** — an address runs to two or three lines and a single-line box that scrolls sideways hides what the reader already typed.
- `makeBaseAttendeeSchema` mirrors `StoreRegistrationRequest`'s rules and its lengths (150/150/100/255 — the widths of the columns behind them). Each `.trim()`s before `.min()`: Laravel's `TrimStrings` middleware refuses a whitespace-only answer server-side, so accepting one client-side would only move the refusal to the last step.
- `AttendeeDetails` and `CreateRegistrationInput` carry all four as **required, not optional** — the API 422s naming any that is missing — and `draftToRegistrationInput()` sends them unconditionally rather than through the `...(x ? {x} : {})` spread the genuinely optional fields use. `StepReview` renders them, with the address spanning both columns so it doesn't set the row height for whatever sits beside it.
- The self-service profile (`ProfileForm`, `features/attendee/{types,schema}.ts`) gained `father_name` and `current_address` too, and its `full_name`/`full_name_bn` caps dropped 200 → 150 to match the tightened server rule. Both new fields count toward the profile-completeness meter.
- All copy is bilingual EN/বাংলা in `lib/i18n/copy.ts` (`ticketForm.attendee`, `ticketForm.errors`, `ticketForm.review`, `profile`), matching the existing per-form locale toggle. `tsc --noEmit`, ESLint (0 errors) and `next build` clean.
- **Verified end to end against the running backend**, not just types: the exact body `draftToRegistrationInput()` now builds returns 201 with all four round-tripping, Bangla included (`রহিম উদ্দিন`, `আব্দুল করিম`).

### ✅ Attendee photos are shown in the admin console, and images have thumbnails — 2026-08-16

The admin attendee list and detail dialog now show the attendee's photo. Doing that naively is what surfaced the real work: the only rendition that existed was the ~1024px badge photo sized for the A5 ticket PDF (a real one measured **231 KB**), and a 20-row list would have pulled 4.6 MB to fill twenty 36px circles.

- **`media_files.thumbnail_media_id`** (migration `2026_08_16_110000_*`) — a nullable self-FK, parent → derivative. The variant relationship belongs to the media, not to each consumer: hanging a second FK off `attendees` would have repeated the idea once per table that references an image. The child is marked by `collection = 'thumbnail'`, which keeps it out of every existing `collection`-scoped listing (the CMS library filters on `UploadContentMedia::COLLECTIONS`) without those queries learning about variants. Outside `$fillable` and written with `forceFill()`, same discipline as `qr_codes.image_media_id`.
- **`App\Domain\Shared\Services\GenerateMediaThumbnail`** — 128px longest side (covers the 36px list avatar and the 72px dialog one at 2x), idempotent (returns early when `thumbnail_media_id` is set), and **shared rather than copied**. That is a deliberate departure from the convention `UploadAttendeePhoto`/`UpdateAttendeeProfilePhoto` follow of duplicating their re-encode step: this one has a caller that is not an upload path at all (the backfill command), and a third copy living in a console command is precisely how the two upload paths would drift.
- **Output is WebP whatever went in.** Not cosmetic — the same 128px pixels measured **20 KB as PNG against 1.8 KB as WebP** (99.2% off the 231 KB original), and the list renders twenty at once, so the format is most of the win rather than the smaller dimensions. WebP also keeps the alpha that switching to JPEG would flatten. Falls back to the source format when GD is built without WebP, since the row records its own `mime_type` and a mixed estate stays self-describing.
- **An image already inside the budget gets no derivative at all** and is left unlinked, rather than pointed at itself — `MediaFile::smallest()` falls back to the original. That same fallback is what makes the API safe before the backfill has run.
- **`php artisan media:backfill-thumbnails`** — `--collection` (default `profile_photo`, because that is the only collection anything reads a thumbnail for today), `--chunk`, `--dry-run`. Uses `chunkById`, not `chunk`: generating a thumbnail sets the very column the query filters on, so an offset walk would skip a row at every page boundary as the result set shrank beneath it. Re-runnable, reports skips individually (a bare count makes "nothing left to do" and "nothing worked" look identical), exits non-zero on a real failure.
- Replacing or removing a photo now soft-deletes **its thumbnail with it** — it is orphaned by the same FK move and would otherwise stay servable through its own signed URL for a full 15 minutes.
- `AttendeeResource` gained `profile_photo_thumb_url` alongside the existing full-size `profile_photo_url` (the ticket PDF and detail paths still want the original); admin index/show eager-load `profilePhoto.thumbnail`.
- **The signed-media route got its own rate-limit bucket.** `GET /api/v1/media/{ulid}` shared the global `api` limiter (60/min per user, `AppServiceProvider`), which was fine when the only consumer was a one-at-a-time ticket-PDF download; one list render of 20 avatars ate a third of a staff member's minute. It now uses a `media` limiter at 300/min and `withoutMiddleware('throttle:api')`, so asset fetches cannot starve the SPA's real API calls. docs/06 §6.7 already allows 300/min for admin traffic.
- SPA: shared `Avatar` in `components/ui.tsx` (photo with an initials fallback, and an `onError` fallback for the moment a 15-minute signed URL expires under a page left open), used in the list's Name column and in a summary strip at the top of the attendee dialog. That strip reads from `form`, not `data`, so it tracks edits in progress instead of contradicting the controls directly beneath it.
- 11 tests in `tests/Feature/Media/MediaThumbnailTest.php`. Full suite **418 passing**, Pint and PHPStan level 8 clean, SPA `typecheck` + `build` clean. OpenAPI regenerated (still 107 paths — a response-shape change to documented endpoints). Verified against the running app: the admin list returns a thumb URL distinct from the full-size one, serving 1,784 bytes of `image/webp`, and a real browser renders it in an `<img>` despite the route's `Content-Disposition: attachment` (disposition does not apply to subresource loads).

**Still open:** a signed URL is minted fresh on every response (`now()->addMinutes(15)`), so each list refetch produces new URLs and the browser cannot reuse its cache. At ~1.8 KB per avatar that is now minor; if it ever matters, round the expiry to a stable bucket so repeat renders hit the cache.

### ✅ The attendee list exports to Excel and PDF — 2026-08-16

`GET /api/v1/admin/attendees/export?format=xlsx|pdf` downloads the attendee roster — profile photo, name, father's name, address, occupation, organization and mobile. Buttons sit in the attendee page's filter row, gated on the new `attendee.export` permission.

**The two formats are deliberately different documents, not one document twice.**

- **`.xlsx` is the machine-readable half** — seven columns, one row per attendee, photo embedded in column A. Real UTF-8 throughout, so Bangla copy-pastes and searches correctly.
- **`.pdf` is a printed alumni directory**, three entries across, each a bordered 3:4 portrait beside Bangla-labelled details: `নাম` / `পিতার নাম` / `পেশা` / `পদবীসহ বর্তমান ঠিকানা` / `ফোন/মোবাইল`. Built to a printed reference the client supplied. It shows `full_name_bn` in preference to the Latin name, and folds `designation` + `organization` into the address line — that is what "পদবীসহ" (with designation) means, and three separate labelled lines do not fit a one-third-page card.

**Deployment note: this needs `php artisan db:seed --class=RbacSeeder`.** `attendee.export` is a new entry in `config/rbac.php`, and the catalogue only exists in a database once the seeder has run — without it every caller including Super Admin gets a 403 (observed here for real before seeding).

**The filters are shared code, not a second implementation.** `App\Domain\Registration\Support\AttendeeListFilters::apply()` is now the single definition of which attendees a filter set selects *and in what order*, used by both `AttendeeController::index()` and the export. An export that quietly disagrees with the screen it was launched from is worse than no export — the operator sees 40 rows and downloads a different 40 with no way to tell. It also carries `PARTICIPANT_TYPES`, so an export filter can never be narrower than what the table actually stores (the drift that made sponsor/guest unusable in Phase 8).

**Fixed in passing — a real pagination bug:** `index()` had no `ORDER BY` at all. MySQL is free to answer successive `LIMIT/OFFSET` pages inconsistently, so the same attendee could appear on page 2 and page 3 while another never appeared. `AttendeeListFilters` orders by `full_name` then `id` — `id` breaks the tie so the order is total, not merely "by name".

**Missing photos get a drawn placeholder, not a hole.** `AttendeeExportPhoto::placeholder()` renders a low-contrast head-and-shoulders silhouette with GD, sized and shaped to whatever slot it is filling, memoised per size (it is the *common* case on a roster that predates photo upload, so this is one render reused thousands of times). Drawn rather than shipped as an asset so it needs no `storage:link` and cannot go missing from a deploy. An empty cell was the wrong answer twice over: in the PDF it collapses the row height and the grid stops lining up, and in either format it is indistinguishable from "the images failed to load".

**Four defects the implementation had, each found by benchmarking rather than by the unit tests. The first two are pinned by regression tests confirmed to fail against the old code:**

1. **The PDF could not render a large export at all.** mpdf parses each `WriteHTML()` string with PCRE and throws outright past `pcre.backtrack_limit` (1,000,000 bytes). Inlining photos as `data:` URIs hit it at ~180 entries; switching to temp-file paths bought room but the directory's richer card markup (~1KB each) hit the same wall at ~1,000. The real fix is what mpdf's own error message says: the body is now written in chunks of 20 grid rows. That is invisible in the output *because of how this grid is built* — each chunk is its own `<table>` and the grid has no repeating header for a table boundary to duplicate. **Do not add a `<thead>` to it.**
2. **The spreadsheet's photo overflowed its column.** Column A was width 12 (89px) against a 96px image. Excel does not clip an oversized drawing, it floats it over the neighbouring column, so the photo silently covered the name beside it. Excel's two unit conversions (`px = round(width × 7) + 5`, `px = points × 96 / 72`) are now written down in the writer and asserted by `test_the_embedded_photo_fits_inside_its_cell`.
3. **PNG is the wrong codec for a photograph, and it cost 77MB.** A 5,000-row spreadsheet of 96px PNG portraits measured **90.2MB**; the same images as JPEG measured **12.9MB**, in half the time and a third of the peak memory. Earlier measurements missed this entirely because the fixtures were flat-colour images, which PNG compresses to almost nothing and a real face does not. Photographs are now JPEG (flattened onto white first — JPEG has no alpha, so a transparent PNG upload would otherwise composite against black); the placeholder stays PNG, because two flat colours is exactly the case PNG wins. The codec is chosen per kind of image, not per format of file.
4. **The PDF asked for a bigger image than the thumbnail holds.** `AttendeeExportPhoto::source()` now takes the 128px thumbnail only when it is genuinely large enough for the size requested, and the full-size original otherwise — a 20mm printed portrait upscaled from 128px is visibly soft.

**Generated synchronously, with measured ceilings.** Clicking Export produces a file rather than a job the operator has to go and find. `config/exports.php` records the measurement, against a deliberately adversarial fixture (every entry carrying a full-size 900×1200 photograph of incompressible noise; a real roster is far cheaper):

| | time | peak | file |
|---|---|---|---|
| xlsx, 5,000 entries | 8.3s | ~145MB | 12.9MB |
| pdf, 500 entries | 8.1s | ~109MB | 8.1MB |
| pdf, 1,000 entries | 16.5s | ~137MB | 16.1MB |

The PDF cap is **500**, not 1,000: 16s inside a synchronous request sits uncomfortably close to a stock 30s `max_execution_time`, and the failure mode there is a truncated download with no explanation. Past a cap the request is refused with a 422 `export_too_large` naming both numbers. Raising either ceiling materially means moving the export onto the `reports` Horizon lane, not editing the number.

**Bangla follows `GenerateTicketPdf`'s rules and inherits its unfixed defect.** *Nothing* in the directory is bold — not the labels, not the name — because `FreeSerifBold.ttf` has zero Bengali coverage and bold Bangla does not degrade, it vanishes; hierarchy is font size and colour only. The conjunct/ToUnicode text-layer defect still applies, and the tests say so explicitly: `test_pdf_directory_uses_bangla_labels_and_prefers_the_bangla_name` asserts only `নাম` and `পদবীসহ`, the two labels with neither a pre-base vowel sign nor a conjunct, because the others print correctly but *extract* as reordered or private-use codepoints. Asserting the mangled bytes would pin the bug in place rather than the behaviour.

- **Mobile numbers are written as explicit strings** in the spreadsheet (`setCellValueExplicit`). A leading `0` on a local-format number is silently eaten by numeric coercion, and this is the field most likely to be dialled straight from the sheet.
- **The audit trail is written from the Action** (D8 discipline) — an export lifts every matching attendee's contact details out of the system in one file. It records the filters and the row count, never the rows: the point is to know who took what, not to duplicate the personal data into the audit table. A refused (too-large) export writes nothing.
- **`attendee.export` is separate from `attendee.view_any` on purpose**, and granted to Event Manager. Listing shows a page at a time behind an audited session; exporting takes the whole filtered set out of the system.
- New dependency: **`phpoffice/phpspreadsheet ^5.9`** — the only realistic way to embed images in an .xlsx. mpdf was already present.
- 25 tests in `tests/Feature/Admin/AttendeeExportTest.php` plus an `attendee.export` HTTP round-trip in `ComprehensivePermissionTest`. Full suite **448 passing**, Pint and PHPStan level 8 clean, SPA `typecheck` + `build` clean. OpenAPI regenerated — **108 paths** (was 107).
- **Verified against the running app**, not only tests: both formats downloaded over HTTP with correct `Content-Type`/`Content-Disposition`/`no-store`; the PDF's rendered pages inspected visually with real Bangla directory data (conjunct-bearing names, designations and addresses all shaping correctly on the page); `pdfimages` confirming 49 portraits at 150×200, 3 JPEG photographs and 46 PNG placeholders; a `participant_type=teacher` filter returning exactly 8 rows; and the `activity_logs` rows checked.

**Still open:** the row ceilings mean "export everything" is unavailable on a full 20,000-attendee database — that needs the queued `reports`-lane export the stubbed `ReportExport` model was always meant for (`ReportController::export()` still creates a row and dispatches no job). Nothing here changed that stub. The PDF also renders mobile numbers in Latin digits where the printed reference uses Bangla numerals; that is a deliberate fidelity choice (numbers are dialled and searched) and a one-line change if the client wants otherwise.


### ✅ Phase 6 closed out — manifest at scale, real key rotation, and the Bangla PDF defect fixed — 2026-08-21

The three items Phase 6 left open that were not physical-hardware tests. What is *still* open from Phase 6 is only what needs a real printer and real devices: physical print/scan testing under damage, brightness and photocopy, per docs/08's own note that it "is not optional and cannot be simulated".

**1. The manifest handles a 12,000-ticket cold start — the unmet exit criterion.**

`ManifestController::show()` called `->get()`, hydrating every ticket as an Eloquent model plus a Resource object before writing a byte. Measured before the fix at 12,000 tickets: 0.39s but **42 MB of PHP memory for one request**, on a stock 128M php-fpm worker. Time was never the problem; memory was, because docs/08's own gate rehearsal has 20+ devices cold-starting at once — ~840 MB of PHP heap, i.e. an outage on the morning of the event.

- Streams via an unbuffered `cursor()`, selecting only the 11 columns `ManifestEntryResource` reads. **42 MB → ~0 MB** of row-holding memory; the response body shape is byte-identical, so a scanner client needs no change.
- **Opt-in pagination** (`?limit=`, `meta.next_cursor`, `?after=`) for resumable sync, cursored on ticket ULID rather than the auto-increment id so no internal primary key crosses the API boundary. Opt-in *deliberately*: a client that did not know about `next_cursor` would silently sync a fraction of the manifest and turn real ticket-holders away at the gate. An old client keeps getting the complete manifest.
- **A device is recorded as synced only after the last row is written** — the touch moved into the tail of the stream generator, so a connection that drops mid-sync no longer claims a manifest it did not finish receiving. This was a latent bug before, and it is load-bearing now: key rotation gates on exactly that field.
- nginx `gzip` for JSON: 3.37 MB → 0.43 MB on realistic (non-repetitive) data, 9.4s → 1.2s on a 3 Mbps link. It belongs in nginx, not PHP — compressing application-side would mean buffering the whole body again and undoing the streaming.
- 5 tests in `tests/Feature/Scanner/ManifestScaleTest.php`, including a peak-memory ceiling that fails loudly if anyone reintroduces a non-streaming fetch, and a cursor walk asserting no page boundary repeats or skips a ticket.

**2. Key rotation is a real, gated procedure instead of hand-editing `.env` in the right order.**

docs/06 §6.5 requires: Super Admin only, re-auth, publish the new public key → wait for confirmed device sync → only then sign with it → notify all Event Managers. Getting that ordering wrong does not fail loudly on the server; it fails at a gate, rejecting every ticket issued from that moment.

- **The private key still never touches the database** — docs/06 §6.5 is explicit. `qr_signing_keys` holds the *public* half plus the lifecycle; env (or the secret manager behind it) holds key material, now via `QR_SIGNING_PRIVATE_KEYS` (a `key_id => base64` map) alongside the original single-key vars, which keep working untouched. The split means the harmless half of a rotation (making a key *available*) is an ordinary deploy, and the dangerous half (the flip) needs no deploy at all.
- `PublishQrSigningKey` **derives** the public key from the private half this server already holds, so no key material crosses an API request and a published key is by construction one that can actually sign.
- `ActivateQrSigningKey` refuses while any active device has not completed a manifest sync since publication, naming the devices holding it up. `force` exists because the alternative is worse — one permanently-offline device would otherwise lock rotation out entirely and send the operator back to editing `.env` with no audit trail — and it is logged as a distinct event.
- **A generated column plus a unique index makes two active keys impossible at the database level**, rather than relying on every write path to remember.
- Ticketing reads device state through a `ScannerFleetStatus` interface it owns, implemented by CheckIn (`CheckInDeviceFleetStatus`) — the module-boundary rule (D6), satisfied by dependency inversion rather than a cross-module query.
- **New re-auth primitive**: `POST /admin/auth/reauth` + `reauth` middleware, bound to the *access token* that confirmed it (a second session for the same person confirms on its own), 5-minute TTL in the cache. Requires password *and* TOTP where 2FA is confirmed. It deliberately does not count toward the login lockout — a mistyped confirmation must not lock the account in the middle of the incident being responded to.
- Event Managers are notified through the existing outbox via a new `QueueNotification::executeForRecipient()` — staff have no `Attendee`, so this is a second door rather than a loosened contract for the six existing callers.
- 20 tests in `tests/Feature/Ticketing/QrKeyRotationTest.php`, including the exit criterion asserted directly: **a ticket signed before the rotation still verifies after it**.
- **Three real bugs this found, which is the whole reason the tests and the live run exist:**
  1. *Caught by the tests.* The first rotation on any deployment would have invalidated every existing ticket: the incumbent key lives only in env, so once the database named a different active key there was nothing left publishing the old public key. Fixed twice over — the registry publishes the derived public key of every private key the server holds, and `PublishQrSigningKey` adopts the incumbent into the table before publishing its replacement.
  2. *Caught only by calling the real endpoint.* Publishing the key that is **already** the active signing key returned a **500** — adoption inserted it, then the publish inserted it again and hit `uk_qsk_key_id`. Every fixture used a next key distinct from the incumbent, so no test reached it. Adoption now runs before the duplicate check, so it answers a clean 422 and the rolled-back transaction leaves the table untouched. The console no longer offers the active key as a rotation candidate either.
  3. *Caught only by running a real rotation and reading the manifest.* `resolve()` excluded the active key from the published list on the assumption `QrSigner` derives it from its own secret — true on a server holding that secret, and wrong on one that does not. An instance mid-rolling-deploy published a manifest with the active key **missing entirely**, and any device syncing from it would have rejected every ticket signed with that key.

**Verified against the running app**, not only in tests: reauth refused an unconfirmed publish (403) and admitted a confirmed one; publish adopted the incumbent and created the incoming key as pending; activation was refused with `2 of 2 active scanner devices have not synced`, naming both; after both synced it activated, retiring the previous key; a payload signed with the **old** key still verified afterwards; and `manager@decent100.example` received the rotation notification. The dev database was restored to its prior state afterwards.

**3. The Bangla PDF text layer — fixed by moving rendering to headless Chrome.**

The defect was **worse than this file previously recorded**. It was documented as conjuncts extracting as private-use codepoints; in fact `pdftotext` *discards* them, so characters were silently lost: `মোহাম্মদ রহিম উদ্দিন` extracted as `মাহাদ রিহম উিন`. Two independent causes, both confirmed against the real pipeline:

1. mpdf assigns a synthetic PUA codepoint (`TTFontFile.php`'s `0xE000` fallback) to every glyph absent from the font cmap — which is every conjunct ligature — and writes it straight into the ToUnicode map.
2. Pre-base vowel signs (`কি` → `িক`) extract in *visual* order. **No ToUnicode map can express this**; it needs `/ActualText`, which mpdf cannot emit (its BDC/EMC support is optional-content-groups only).

Chrome shapes with HarfBuzz and writes a correct multi-character ToUnicode map. All nine adversarial cases round-trip.

- `App\Domain\Shared\Services\HtmlToPdfRenderer` is the single place HTML becomes a PDF; `config/pdf.php` documents why. Page size and margins live in each template's `@page` CSS, which is where Chrome reads them.
- **Fonts are bundled** (`resources/fonts/NotoSans*.ttf`, variable, OFL) rather than installed into the image, so a ticket is identical on a developer's Mac and in production. The GPOS table that made Noto unusable under mpdf is a non-issue for HarfBuzz.
- **Bold Bangla works**, closing the second defect: mpdf's `FreeSerifBold.ttf` has zero Bengali coverage, so bold Bangla did not degrade, it vanished. The ticket template's `.bn-value` opt-out and the export's "nothing may be bold" rule are both gone.
- The export's 20-row `WriteHTML` chunking is gone too — that existed only for mpdf's `pcre.backtrack_limit`.
- **`mpdf/mpdf` is removed from `composer.json`.** `symfony/process` is now an explicit dependency.
- **Two Chrome behaviours worth knowing before touching the renderer**, both measured here, not guessed: passing `--user-data-dir` makes Chrome render the PDF correctly and then **never exit**, turning every render into a timeout — current headless already isolates its own profile, and four concurrent renders without it produced four byte-identical files and all exited cleanly. And a `position: fixed` footer *does* repeat on every printed page, but `counter(page)` resolves to `0`: Chrome only implements page counters inside `@page` margin boxes.
- **What the move cost:** the directory's per-page "Page N of M". Chrome's CLI cannot supply the footer template its DevTools API can, so the running footer carries the document's identity instead. Numbering it properly means driving Chrome over CDP rather than `--print-to-pdf`.
- **Re-measured ceilings** (`config/exports.php`): pdf at 500 entries is 23.8s / ~15 MB peak / 7.4 MB file, of which **only 2.8s is Chrome** — the other 21s is photo decoding, unchanged from mpdf. Chrome's cost is a near-flat ~2.5s per invocation (2.6s at 250 entries, 2.5s at 500, 2.8s at 1,000), i.e. process startup, plus ~10s more for the very first render in a fresh container. Peak memory fell from ~110 MB to ~15 MB because layout happens out-of-process. The 500-row cap is unchanged. `tests/Feature/Admin/PdfExportBenchmarkTest.php` re-derives these (`EXPORT_BENCHMARK=1`).
- Production image installs `chromium` and pins `CHROME_BINARY`; `deploy.yml` asserts the binary exists, because a base-image change that dropped it would otherwise surface as tickets failing to render in production rather than as a failed build. `backend-ci.yml` installs chromium + poppler-utils explicitly so the PDF tests cannot quietly skip themselves.
- **Fixed in passing:** the ticket template rendered `<img src="">` when a ticket had no `qr_codes` row, putting a broken-image icon where the QR belongs — which reads as a printing fault rather than a ticket that cannot admit anyone. It now says so, bilingually. Found by looking at a rendered page, not by any test.
- Tests: the Bangla assertions that were deliberately weak (asserting only that the name had not vanished *entirely*) now assert the full name round-trips, and the export asserts every Bangla label including the conjunct-bearing ones. Whitespace is normalised first — `pdftotext` inserts word breaks from glyph advances, which splits a Bengali word without losing a character of it.

**Still open from Phase 6:** physical print/scan testing only (cracked screen, 40% brightness, laser, inkjet, photocopy). The key-rotation *rehearsal* on staging remains unrun for want of a staging environment, but the procedure it would rehearse is now enforced by the server rather than by a checklist.

Full suite **478 passing / 2 skipped** (the two benchmark harnesses, skipped unless `EXPORT_BENCHMARK=1`), Pint and PHPStan level 8 clean, SPA `typecheck` + `build` clean. OpenAPI regenerated — **112 paths** (was 108).

**Found while verifying, not fixed — it is your `.env`, not the repo's:** the development `.env` carries **two** `QR_SIGNING_PRIVATE_KEY` / `QR_SIGNING_KEY_ID` pairs (lines 70–71 and 78–79). Dotenv silently takes the last, so editing the first appears to do nothing — worth collapsing to one before anyone debugs a signing problem against the wrong key.

### ✅ A third price tier: what a current student pays — 2026-08-21

`ticket_types` gained **`current_student_price_paisa`**, so the centennial ticket now carries three rates instead of two, all editable in the admin console:

| | |
|---|---|
| everyone else | `base_price_paisa` — ৳2,500 |
| a current student | `current_student_price_paisa` — ৳500 |
| each extra adult / child | `additional_adult_price_paisa` / `additional_child_price_paisa` — ৳2,000 |
| child under 2 | free, still admitted |

Prices already came from the backend — the public `/tickets` page has read `/public/ticket-types` rather than a constants file since 2026-08-14. What did not exist was a student rate on the row the centennial page sells: `current_student` is in `CENTENNIAL_AUDIENCE`, so a student was billed the full ৳2,500. The standalone `STU` type that does carry a student price is a different row the public page never offers.

- **The discount covers the registrant's own seat only.** Family a student brings is charged the standard extra-adult/child rates, so the discount follows the student rather than their whole party. `test_a_current_students_family_pays_the_standard_member_rates` pins the arithmetic rather than just the base line, because family pricing sharing a code path with the base seat is exactly how a "student family rate" would appear by accident.
- **`TicketType::basePriceFor(?string $participantType)` is the single definition** of which price column applies to a buyer. `CreateRegistration` asks it instead of reading `base_price_paisa`, so an admin-created or imported registration cannot quietly bill a different rate than the public checkout does.
- **NULL means "no student rate", 0 means "free student ticket"** — the column is nullable rather than defaulting to 0, and every check compares against `null` rather than testing truthiness. Every ticket type that predates this is NULL, so their pricing is byte-identical. Same discipline as `child_free_under_age`.
- **It joins the post-sale price lock.** `TicketTypeController::update()`'s `$restrictedKeys` gained it alongside the other three — leaving it out would have left one editable money column on a tier that has already sold.
- **⚠️ The seeded ৳500 is a starting value, not a client decision.** It is carried over from the standalone `STU` type, the only current-student price this system has ever had. Set the real figure in the admin console (Tickets → Centennial Ticket → Current student price) or in `TicketTypeSeeder` before first seeding a production database. Note that `TicketTypeSeeder` uses `updateOrCreate`, so a re-seed overwrites an admin-chosen price — pre-existing behaviour for all four price columns, not new here.
- Admin SPA: a Current student price control in the ticket-type dialog (blank = no student rate, disabled once the tier has sold), and the list's Price column shows the student rate under the base price so the table and the dialog cannot disagree.
- 8 tests (6 in `CentennialTicketFlowTest`, 2 in `AdminCrudTest`) covering the student rate, the standard family rates on top of it, no leakage to other participant types, a NULL type billing base, a zero rate being free rather than absent, the public API publishing it, and both admin paths. `test_every_allowed_participant_type_may_buy_the_one_ticket` was updated to expect each type's own tier rather than one flat figure — it is now a tier assertion, not a weakened one. Full suite **503 passing / 2 skipped**, Pint and PHPStan level 8 clean, SPA `typecheck` + `build` clean. OpenAPI regenerated (still 112 paths — a request/response shape change to documented endpoints).

**The admin registration modal was updated to match**, since a total is now the output of three tiers rather than one:

- **Participant type is shown** — it is the input that decides which base rate applied, and the modal previously never named it.
- **A Price block itemises the total**: the registrant's line labelled with the tier that applied (`Current student rate` / `Standard rate`), the extra adults and children at their per-head rates, any infants at zero, the discount, and the stored total.
- **The stored `subtotal_paisa`/`total_paisa` stay the money; the lines only explain them.** A ticket type can legitimately be repriced after a registration was taken (the post-sale lock only applies once a tier has sold), so `priceBreakdown()` reports whether its lines still add up and the modal says so in as many words when they do not, rather than confidently explaining the wrong number. It returns null — and the itemisation simply does not render — when the row carried no prices, so a half-built breakdown is impossible.
- **Fixed in passing — the Party line had been under-reporting since 2026-08-14.** It read `{adults} adult(s), {children} child(ren)` and omitted `infants_count` entirely, so a party of four with one infant showed as three people while `admits_total` was four. `RegistrationResource` has published `infants_count` since that date; the SPA's hand-written `Registration` type simply never declared it — exactly the drift this file's SPA conventions warn about. The same widening exposed `attendee.participant_type` and the nested ticket type's price columns, all of which the resources were already returning and the SPA was discarding.
- **The layout was rebuilt shorter to absorb the new block**, since a Price block on top of the existing content pushed the dialog past the viewport on a large party. The action row moved into the Dialog's `footer` prop — pinned, out of the scrolling body — matching the attendee dialog, which has used that facility since it was built; previously Delete/Save sat inside the body, so a registration with several guests hid its own Save button below the scroll. The dialog widened to `max-w-2xl` (again matching the attendee dialog) to buy the horizontal room that pays for the height: the four facts sit in one row on desktop instead of two stacked pairs; Price takes the left column with Status top-right (the one control that acts on the record, where the eye lands) and Guests beneath it, so the row is as tall as the longer column rather than the sum of three stacked blocks; and Comments/Special notes pair up. Row rhythm dropped to `py-1.5` across both lists so they line up beside each other. **Not verified in a browser** (no browser driver here), so treat the proportions as unchecked; `typecheck` and `build` are clean.
- **Verified against the running app**: a current student with one extra adult, one child and one infant returns `participant_type: current_student`, a 2/1/1 party split and `subtotal_paisa` 450000, with the tier lines (৳500 + ৳2,000 + ৳2,000 + free) summing to exactly that. Not checked in a browser — no browser driver in this environment — so the layout itself is unverified beyond `typecheck` and `build`. Dev database and the minted token were cleaned up afterwards.

**Shipped in `centennial-celebration` the same day**, since the public page is the consumer:

- `basePriceFor(ticket, participantType)` in `features/ticket-system/pricing.ts` mirrors the server helper; `quoteRegistration()` takes `primaryParticipantType` and prices the registrant's line with it. The returned `basePaisa` is now the *effective* base, so the summary panel's header and its first line item cannot disagree.
- **Copy that had gone wrong was fixed, not just extended.** `TicketPricingRules`'s first card read "৳2,500 flat, whoever you are — alumnus, current student, teacher, staff or guardian", which the student rate makes false; it now names the student rate separately, and falls back to the flat wording when the ticket has none. `TicketCard` carries a "৳500 for current students" line under the headline price. Both bilingual.
- **Verified against the running backend**, not only tests: a current student alone → ৳500; a former student alone → ৳2,500; a current student plus one adult and one 9-year-old → ৳4,500. The dev database was restored to its prior state afterwards.

**Found while doing this, not fixed:** `src/lib/utils/priceEstimate.ts` in `centennial-celebration` is dead code — nothing imports `estimateRegistration`/`estimateRegistrationTotalPaisa` since the six-step `RegistrationWizard` was deleted on 2026-08-14. It is now a *second* pricing formula that does not know about the student rate, so anyone who reaches for it will get the wrong total. Delete it, or point it at `pricing.ts`.

### ✅ One free-text field on a registration, not two — 2026-08-21

`registrations.comments` is gone. `special_notes` is the only free-text field a registration carries, at every layer.

**They were never two things.** Both columns were `text NULL`, both validated `max:1000`, and every layer that accepted one accepted the other — public create, attendee self-service update, admin update, `RegistrationResource`, the OpenAPI spec. Nothing in the codebase ever read them differently or branched on which was set, so a note landed in whichever box the form happened to render: the public ticket page wrote to `comments`, the admin console showed both. Staff had to read two boxes to be sure they had not missed a dietary need or an accessibility request, which is the failure this closes.

- **Migration `2026_08_21_120000_*` merges before it drops, and that ordering is the migration.** `comments` is the box the public form has been writing to, so on any environment that has taken a real registration it holds attendee-supplied text — the text somebody actually needs on event day. It is copied into `special_notes` where that is empty and appended after a blank line where both are filled (picking a survivor is a judgement about content a migration cannot read), and only then is the column dropped. `down()` re-adds an empty column: the merge genuinely cannot be undone, since nothing marks where the seam was. Guarded with `Schema::hasColumn` so a partial deploy does not fatal.
- **Removed from:** `Registration::$fillable`, `CreateRegistration`, `RegistrationResource`, all three FormRequests (public store, attendee update, admin update), `Attendee\RegistrationController`'s `$request->only()` allowlist, every OpenAPI property and two endpoint summaries, `docs/04-erd.md`, and the admin SPA's `Registration`/`UpdateRegistrationPayload` types. Special notes now takes the full row in the registration modal, since it is the only free-text control left.
- **An old client sending `comments` is ignored, not broken.** It is absent from every rule, so `validated()` strips it — a cached build of the public site keeps working and simply does not record that field. `test_a_legacy_comments_key_is_ignored_rather_than_erroring` pins that it neither 500s on a dropped column nor silently pretends the note was saved.
- **`centennial-celebration` renamed its field rather than just remapping it.** The draft field, the Zod schema key, the form registration and the review row all moved `comment` → `special_notes`, so the client name matches the column it becomes; the label changed from "Comment"/"মন্তব্য" to "Special notes"/"বিশেষ নোট" (copy keys renamed to match — a key called `comment` rendering "Special notes" is the next person's confusion). The placeholder already described exactly what the field is for and did not change. `addOnsSchema` in `features/registration/schema.ts` — dead since the six-step wizard was deleted 2026-08-14 — lost its `comments` key too, so no dangling reference to the removed field survives anywhere.
- 5 tests in `tests/Feature/Registration/SpecialNotesTest.php`, including a schema assertion that the column is gone and `special_notes` is not. Neither field had *any* test coverage before this. Full suite **508 passing / 2 skipped**, Pint and PHPStan level 8 clean, both frontends typecheck/lint/build clean. OpenAPI regenerated (still 112 paths — a request/response shape change).
- **Verified against the running app**: a public registration carrying both `special_notes` and a legacy `comments` key returns 201, stores the note, and the response has no `comments` key at all. Dev database cleaned up afterwards.

### ✅ The public attendees directory is real data — 2026-08-21

The public site's `/attendees` page (repo `centennial-celebration`) shipped rendering `features/attendees/mock-data.ts` — a hand-written array of invented alumni, filtered, sorted and paginated in the browser. It is now backed by this system, through a new public endpoint that did not exist.

**Only a registration that succeeded is listed.** `PublicAttendeeDirectory::VISIBLE_STATUSES` is `['paid', 'confirmed']` and nothing else. This is the load-bearing rule, not a nicety: `POST /public/registrations` is unauthenticated, so anything listed before payment is verified would let a stranger put any name on the public site for free — and would publish people who abandoned checkout as though they were attending. `cancelled`, `refunded` and `expired` fall back out of the directory by the same rule, which is why it is a status list rather than an `is_public` flag somebody has to remember to clear.

**A row is a registration, not an attendee.** The party makeup the cards and counters show (participation type, adults, children, infants, family) belongs to the registration; the person belongs to the attendee behind it. Listing attendees would have meant a subquery for every one of those numbers.

**Shipped here:**

- `GET /api/v1/public/attendees` (`Api\Public\AttendeeDirectoryController`, `throttle:60,1`) — filters `search` / `participant_type` / `batch_year` / `batch_from` / `batch_to` / `has_guests`, sorts `batch_asc|batch_desc|name_asc|recent`, ETagged through the existing `RespondsWithEtag` so a repeat fetch is a 304.
- `App\Domain\Registration\Support\PublicAttendeeDirectory` — the one definition of who is visible, what may be filtered on, and in what order. It **cannot use `ListSort`**: the query spans two tables and `ListSort` qualifies every column with the queried model's own table. The allowlist discipline is reproduced there (public sort key => a fixed `[column, direction]` pair) rather than loosened in `ListSort`.
- `App\Http\Resources\Public\PublicAttendeeResource` — the narrowest resource in the codebase, and the one where the allowlist rule matters most, because the audience is everyone on the internet rather than a staff member behind an audited session. Deliberately absent: `mobile`, `email`, `whatsapp_number`, `emergency_contact_*`, `father_name`, `current_address`, `date_of_birth`, `gender`, `blood_group`, `notes`, money, `registration_number`, and **the guest roster** — a registration's family members are named people who never filled a form in, so the card shows how many came, never who. `test_the_card_exposes_only_the_public_allowlist` asserts the key set *exactly*, so a field added to the resource fails the test and makes someone decide.
- **A decade filter is sent as a `batch_from`/`batch_to` range, not a decade name.** The decade list is presentation (its labels are bilingual), so the server never learns what a "1990s" is and there is one place to edit when the school adds one.
- **Page size is capped at 48** (`MAX_PER_PAGE`). An uncapped public list endpoint is a free bulk export of the whole 20,000-name roster in one request.
- **`%`, `_` and `\` are escaped in the search term.** Unescaped, a search for `%` matches every row — turning the search box into a way to page the entire roster while looking like a filtered result. `test_a_wildcard_in_the_search_box_is_a_literal` pins it.
- **The header counters are computed over the whole directory, not the filtered page**, because that is what they claim to be — typing a name into the search box must not make "37 registered" drop to 1. Two aggregates (`summary()`, `availableBatches()`), not loaded rows, since they run on every filter change.
- Ordering appends `registrations.id` as a final tiebreaker. A batch year is shared by everyone in it, so ties are the norm here rather than an edge case, and MySQL may otherwise answer successive `LIMIT/OFFSET` pages inconsistently.
- 17 tests in `tests/Feature/Public/AttendeeDirectoryTest.php`. OpenAPI regenerated — **113 paths** (was 112).

**Shipped in `centennial-celebration`:**

- `features/attendees/mock-data.ts` is **deleted**, and so is `app/api/attendees/route.ts` — a BFF proxy whose only job was serving that array. The client calls the public API directly, the same way the ticket page already reads `/public/ticket-types`.
- `filterAndSortAttendees()` — the whole client-side filter/sort/paginate engine — is gone. It could not have survived real data: it needed every attendee in the browser to answer one page.
- `lib/api/client.ts` gained `apiFetchEnvelope()`. `apiFetch()` unwraps `{ data: … }`, which throws away the `meta` a paginated list carries — here that is the page counts *and* the whole-directory counters.
- `AttendeesClient` is server-driven via TanStack Query: search debounced 300ms (each keystroke is a request otherwise), `keepPreviousData` so the grid dims rather than empties between pages, and real loading and error states with a retry. The batch-year dropdown keeps the full list from `meta` rather than the years on the current page, so it cannot shrink as the reader filters and leave them unable to widen again.
- The page's server fetch `.catch()`es to an empty directory, so an unreachable API leaves the hero, toolbar and CTA rendering with the client offering its own retry, instead of taking the route down. It is also what lets `next build` prerender the page with no API running.
- `attendees.loading` and `attendees.error.*` added to `lib/i18n/copy.ts`, bilingual like everything else there.

**Verified against the running stack**, not only tests: 37 confirmed registrations listed while the same database's 10 `pending_payment` and 5 `cancelled` ones are not; a full 48-card page checked field-by-field for every banned key (none); `participant_type=teacher` returning 9 rows all teachers; `batch_from=1990&batch_to=1999` returning only 1993; `search=%` returning 0; `per_page=9999` answering with 48; an `If-None-Match` revalidation answering 304; and the rendered page showing real hero counters (৩৭ / ৯ / ২ / ১৪ / ৩৬ / ৮), a batch dropdown built from the eight years that actually exist, and "১ – ১২ এর মধ্যে ৩৭". Full suite **528 passing / 2 skipped**, Pint and PHPStan level 8 clean, frontend `tsc --noEmit`, ESLint (0 errors) and `next build` clean.

**Still open:**

- **Nobody consented to being listed.** The registration form has no "show me in the public directory" choice, so every paid attendee appears. If that is not what the client wants, it needs an opt-out column on `attendees` and a `where` in `PublicAttendeeDirectory::query()` — the query is deliberately the single place that decision would go.
- The search runs `LIKE '%term%'` across seven columns, which cannot use an index. Fine at the current volume and at the 12,000-ticket target; if it ever bites, it wants a FULLTEXT index, not a narrower filter.
- ~~The card renders initials rather than a photo.~~ **Closed the same day** — see below.

#### The card shows one name and a real photo — 2026-08-21

Two changes to what a directory card renders, both requested after the directory went live.

**The Bangla name only.** The card used to stack both names — the locale's name large, the other beneath it. It now shows one: `full_name_bn`, falling back to `full_name` only for the rows that genuinely have none (the public form has required a Bangla name since 2026-08-16, but older and admin-created attendees predate that, and a nameless card is worse than a Latin one). It carries `lang="bn"` so a browser picks a Bengali face even on the English side of the locale toggle. Search still matches both names server-side — what changed is the display, not what is findable.

The initials fallback is derived from the *displayed* name, so it is now Bengali too, and it splits with `Array.from()` rather than `word[0]`: a cluster like `মো` is two code units, and indexing would slice it into a lone combining mark.

**The badge photo is published.** This reverses the earlier decision to omit it, at explicit request — the trade-off is unchanged, it was the client's call to make.

- **The 128px thumbnail, never the ~1024px original** the ticket PDF prints. Twelve full-size renditions per page is several megabytes; the thumbnail measures 1,784 bytes of WebP. `smallest()` falls back to the original for a photo uploaded before thumbnails existed, so a card is never blank waiting on `media:backfill-thumbnails`.
- **`MediaFile::cacheableSignedUrl()` is new, and it is the load-bearing part.** `temporarySignedUrl()` stamps `now() + 15min`, so it mints a different URL every second — harmless on a one-off download, and fatal to a cached list: the URL is *in the body*, so the body changes every request, the ETag never matches, and both the 304 path and any shared cache in front of this endpoint silently stop working. The new method rounds the expiry to a window boundary, so every caller inside the same window gets a byte-identical body. The cost is that a URL outlives its nominal TTL by up to one window — the guarantee is "at least 15 minutes", not "exactly". Use it only where the response is cached; a link minted for one named person still wants `temporarySignedUrl()`. `test_the_photo_url_is_stable_between_requests_so_the_etag_still_matches` pins this, travelling 30 seconds between the two requests.
- `attendee.profilePhoto.thumbnail` is eager-loaded, or `smallest()` costs two extra queries per card.
- The card renders a plain `<img>`, not `next/image` — a signed, short-lived URL is neither a stable remote pattern the optimizer can be configured for nor worth caching a derivative of — with an `onError` fallback, because a page left open outlives its signed URLs and a broken-image icon reads as a fault in the site. (That fallback was initials at first; it is the drawn silhouette as of the next subsection.)
- **A signed URL handed to an anonymous caller can be copied and re-shared for the life of the signature.** That is inherent in publishing the photos at all, not something a shorter TTL fixes; the way to undo it is to stop returning the field. `GET /api/v1/media/{ulid}` is already unauthenticated (the signature is its only check) and sits on the 300/min `media` limiter, so no route change was needed.

3 more tests (20 in the file). Verified against the running app: the one dev attendee with a photo returns a URL pointing at the *thumbnail* ULID that serves 1,784 bytes of `image/webp` to an anonymous caller; the URL is identical across requests three seconds apart and expires on a clean `13:30:00` boundary; a conditional re-request of a page *containing* a photo still answers 304; and the rendered page shows one Bangla name per card, Bengali initials (`মক`, `নস`, `অচ`) where there is no photo, and an `<img>` where there is.

#### No photo draws a male/female silhouette, not initials — 2026-08-21

A card with no badge photo used to show the attendee's initials; it now draws a gendered placeholder.

**`gender` still is not published.** The card needs to pick an outline, which is not the same thing as needing the personal record. `PublicAttendeeResource` derives **`avatar_variant`** — `male | female | neutral` — and the raw column stays out of the response. That distinction is load-bearing rather than fussy:

- `attendees.gender` is VARCHAR(32) *specifically* so it can hold `prefer_not_to_say`, a value whose entire meaning is "do not publish this". Putting it on an anonymous endpoint would contradict the answer the person gave.
- Anything not plainly `male` or `female` collapses to `neutral`, so the placeholder is never a guess about somebody.
- **This is not an edge case.** Only `StoreRegistrationRequest` constrains the column to male/female — the admin and attendee-profile requests do not accept `gender` at all — so seeded, imported and admin-created rows really do land outside it. On the current dev database that is 17 of 37 listed attendees (6 `other` + 11 `prefer_not_to_say`).

`test_the_avatar_hint_never_guesses_a_gender_and_never_leaks_the_column` covers all five inputs and asserts the `gender` key is absent. Note the fixture uses a **list of pairs, not a keyed map**: `null` used as a PHP array key silently becomes `''`, which quietly dropped the null case on the first run.

**The placeholder is inline SVG, not files under `public/images/`.** Twelve cards a page would be twelve extra requests for three distinct pictures; a missing asset would surface as a broken tile on a deploy that otherwise worked; and inline lets the figure inherit the card's own participant-type palette instead of introducing a third colour. Same reasoning the backend already applies to `AttendeeExportPhoto::placeholder()`, which draws its silhouette rather than shipping one.

`AttendeeAvatar` (`components/attendees/`) is now the single place that decides what fills the circle — photo, or silhouette — and owns the `onError` fallback, so a signed URL expiring under an open page degrades to the placeholder rather than a broken-image icon. Hair is drawn *behind* the head sharing its fill, so the overlap merges into one silhouette instead of showing a seam. The initials helper is gone.

**Verified against the running app:** the live directory returns 12 female / 8 male / 17 neutral, matching the database's own 12 / 8 / (6 `other` + 11 `prefer_not_to_say`) exactly, with no `gender` key on any card; the rendered page draws 2 photos and 10 silhouettes across 12 cards. Page 1 happened to contain no male attendee, so one dev row was flipped to `male` to confirm that branch renders and **restored immediately afterwards** — the gender distribution was checked back to its original 17/15/7/12.

Full suite **529 passing / 2 skipped**, Pint and PHPStan level 8 clean, frontend `tsc --noEmit`, ESLint (0 errors) and `next build` clean. OpenAPI regenerated (still 113 paths — a response-shape change).

### ✅ The ticket confirmation email carries the QR, in a designed shell — 2026-08-21

Email was real but plain: `MailDriver` handed `notifications.body_rendered` straight to a bare `Content(htmlString: …)`, so a ticket confirmation arrived as two unstyled paragraphs and the QR — the thing that actually admits the holder — was nowhere in it. Every outbound email now renders inside `resources/views/emails/notification.blade.php`: a dark violet hero, a perforated ticket card (details on the stub, ticket number and QR on the counterfoil), a four-up notes strip, a call to action and a footer. Built to a reference design the client supplied.

**The palette is the public site's own, not an invention.** `centennial-celebration/src/app/globals.css` records a violet/amber design system read off the live Figma file — brand/purple-600 `#7c3aed`, purple-700 `#6d28d9`, purple-100 `#ede9fe`, ink/heading `#3d1d7a` — and the email uses those values verbatim, so a confirmation and the page it was bought on read as one product. Display type is Georgia, which is the site's own named fallback for Playfair (no email client loads a webfont reliably).

**The split is the point: body copy is editable, the chrome is not.** The template body stays what an Event Manager edits in the admin console and `QueueNotification` interpolates; it renders as the hero sub-copy. The masthead, ticket card, QR panel, notes strip and footer are code the editor cannot reach — a mis-saved template must not be able to remove the code somebody is admitted with. The seeded `ticket_delivered` copy was rewritten to stop repeating the facts the shell now renders from the ticket.

- **The QR is an inline CID part, not a link and not a `data:` URI.** Gmail and Outlook both drop `data:` images, and a remote image would need `MediaFile::temporarySignedUrl()`, which expires in 15 minutes — this email is opened months later, at a gate, possibly with no signal. A CID part travels with the message and renders offline. `test_the_qr_code_travels_as_an_inline_image_part` asserts the `<img src>` names the part that actually travelled and that no `src="data:` survives.
- **Icons travel the same way, as pre-rendered PNGs in `resources/images/email/`.** No mail client renders SVG and an icon font is worse, so the ten Lucide glyphs the admin console already uses were rendered once through headless Chrome and split with GD. `MailPresentation::iconNames()` decides which ones a given message needs and `NotificationMail::icons()` loads only those; the view memoises each `embedData` call, so a glyph used twice (the ticket mark in the masthead and again beside the scan note) still travels once. `test_only_the_icons_the_layout_asks_for_travel_with_the_message` pins both halves — no duplicates, and no `pin.png` when no venue row rendered.
- **`ProvidesMailPresentation` (Notification) is implemented by `Ticket` (Ticketing)**, so `MailDriver` never imports another module's models — the same dependency inversion as Ticketing's `ScannerFleetStatus` implemented by CheckIn. `MailPresentation` carries the headline, card, QR panel, fact rows, notes and CTA; every field is optional and the shell drops a section at a time, so a payment receipt with no presentation at all renders as a hero and a footer with nothing missing-looking in between (the hero rounds its own bottom corners when no card follows).
- **Resolution happens at send time, and that closes a real race.** `TicketIssued` queues the email on the `notifications` lane and `GenerateTicketAssetsJob` on `tickets`; they run concurrently, so the stored QR PNG usually does not exist yet when the email drains. `TicketMailPresentation` prefers the stored image and otherwise re-renders from the ticket's signed payload — deterministically the same bytes, for a few ms of CPU. No delay, no QR-less ticket, no dependency on the asset job having won the race.
- **The counterfoil is guarded separately from the card.** A ticket with no `qr_codes` row still shows its number — that is the reference a holder quotes when they ring about a code that never rendered — and the perforation only appears when there is something on the other side of it.
- **No PDF attachment, deliberately.** A signed URL cannot be put in an email that outlives it, and attaching a PDF to every confirmation costs deliverability and mailbox quota for a document the QR in the body already replaces. The CTA links to `{FRONTEND_URL}/registrations/{ulid}` — the one public URL the SSLCommerz return legs already prove exists — built server-side, never from a request.
- **This design is committed to light**, `color-scheme: light` and no `prefers-color-scheme` block, matching the public site's own decision that dark mode must not auto-trigger. It is also a correctness matter: a scanner reads a dark module on a light quiet zone, so a client that inverted the QR would hand the attendee a code that will not scan.
- New settings the shell reads, all optional: `event.tagline` (footer), `event.venue_address` (under the venue row), and `event.support_email`/`event.support_phone` — **seeded empty on purpose**, because a "contact us at" line with nothing after it is worse than no line, so the help block is omitted until somebody fills them in. **This needs `php artisan db:seed --class=EventSettingSeeder`** to appear in an existing database.
- `mail:test` gained `--ticket=<ticket_number>`: sends the real confirmation email, QR and all, to any address without pushing another registration through payment.
- 10 tests in `tests/Feature/Notification/TicketEmailTest.php`, sent through the real mailer on the `array` transport rather than `Mail::fake()` — what matters is the MIME a provider would receive, and a faked mailer never builds one.

**Knowingly not carried over from the reference:** the hero photograph (no event image exists here; the hero is a CSS gradient, and a supplied photo could be embedded the same way the QR is), the card's negative overlap and its die-cut notches (both need `position:absolute`, which Outlook ignores — hero and card butt together instead), "View this email in your browser" (nothing hosts one), the social row (no accounts configured), "Add to Calendar" (needs an `.ics` endpoint that does not exist), and the unsubscribe link (this is transactional mail with no preference centre).

**Not verified:** delivery to a real inbox, and no email client has rendered it — Outlook's Word engine in particular will square the rounded corners and drop the two gradients to their solid `bgcolor` fallbacks, which is why every gradient has one. Rendering was checked by screenshotting the actual mailable output at 600px and inside a real 390px viewport. Note that headless Chrome on macOS clamps its window to ~532px, so a naive `--window-size=390` screenshot silently lays out wider than the shot and looks broken — render inside an iframe to test the breakpoint.

#### Notifications are written in Bangla — 2026-08-21

Every notification this system sends is now Bangla by default, end to end: the template row that is picked, the greeting inside it, and the chrome the email shell renders around it. Bilingual "English · বাংলা" labels are gone from the email — a message is written in one language, the one its reader reads.

- **`config/notifications.php` is the single decision.** `locales.default` (`NOTIFICATION_LOCALE`, `bn`) picks both the `notification_templates` row and the `lang/{locale}/emails.php` file the shell renders from. Any channel may override it — and `sms` is the one worth thinking about before you do: GSM-7 fits 160 characters per segment and Unicode only 70, so Bangla SMS costs roughly two to three times the segments for the same message. `SmsSegmentCalculator` already counts that correctly; the config comment says so at the place someone would change it.
- **A missing translation no longer silences a whole class of notification.** `QueueNotification` writes *no outbox row at all* when it cannot find a template, so one untranslated (key, channel) pair would have taken that notification off the air with nothing in the delivery log to show for it. It now falls back to `locales.fallback_locale` and — this is the load-bearing half — stores **the locale it actually rendered**, not the one it asked for, so a resend reproduces the message that was sent.
- **The shell's own words live in `lang/{en,bn}/emails.php`**, never in the view or the presentation. `NotificationMail` calls `$this->locale($bodyLocale)`, so Laravel wraps the whole render in `withLocale` and every `__()` resolves correctly. `MailDriver` sets the locale around `mailPresentation()` too, because the presentation is assembled *before* the mailable renders — without that, a Bangla email arrives with English gate details in the card beside its Bangla body. `test_the_shell_speaks_the_notification_own_language` renders the same ticket both ways and asserts neither language leaks into the other.
- **Bengali numerals are a second step, and a selective one.** Carbon's `bn` locale translates month names and meridiems but leaves digits Latin, so `App\Domain\Shared\Support\BanglaNumerals` finishes the job — applied to dates, times, admit counts and batch years, and **deliberately not** to the ticket number. An identifier is not a number: it is quoted down a phone, typed into the admin console and matched against a printed page, and `DEC100-CEN-২০০৫-০০০০১` is unusable for all three. Same reasoning the attendee-directory PDF already applies to phone numbers.
- **The greeting uses the reader's Bangla name.** Every outbox writer now passes `full_name_bn` alongside `full_name` (via the new `Attendee::banglaName()`, which falls back to the Latin name — the public form has required a Bangla name since 2026-08-16, but admin- and import-created rows still do not), and the `bn` template bodies interpolate that one. Greeting somebody by an empty string is the failure that fallback exists to prevent, and there is a test for it. The ticket card's attendee and ticket-type rows resolve the same way, in whichever direction the locale points.
- `APP_LOCALE` is untouched and stays `en` — the admin console, the API and its error envelopes are unchanged. Only notifications moved.
- **The seeded English templates stay complete.** They are the fallback, and a half-populated `en` set would surface as a silently-dropped notification rather than as an obvious gap.
- **No letter-spacing anywhere in the template.** Tracking is a Latin typographic device; Bengali carries its meaning in matras and conjunct clusters, and pushing the glyphs apart reads as broken shaping rather than as emphasis. The small caps labels were sized to be read *with* tracking, so removing it was paid for in size (10.5px → 11.5px, and the ticket-ID label 10px → 11px). `text-transform: uppercase` stays: it is inert in Bengali and preserves the intent if a channel is ever set back to English.
- **The attendee's own Bangla name is used, not just the ticket's snapshot.** `tickets.holder_name_bn` did not exist until Phase 8, so 35 of 36 tickets in the dev database have none — `TicketMailPresentation::holderName()` now consults the attendee record before giving up and printing the Latin name. The snapshot still wins where it exists: it is what the printed ticket and the gate list say, and an email that disagrees with the paper in someone's hand is worse than one carrying a spelling since corrected. Both directions have a test.
- 9 more tests (36 in `tests/Feature/Notification/`), covering the configured language, a per-channel override, the fallback path, the Bangla greeting and its Latin fallback, the attendee-name fallback, and snapshot precedence. Full suite **546 passing / 2 skipped**, Pint and PHPStan level 8 clean.

**Worth knowing when reading a rendered email:** `event.venue_address` set to the same text as `event.venue` used to print the venue name twice, which reads as a rendering fault; the presentation now drops a note identical to its value. And where the Latin name still shows through an otherwise Bangla message, check the data before the code — the dev database has rows whose `full_name_bn` literally holds a Latin string (`full_name_bn = 'Mominul Islam'`), which no fallback chain can detect: a name field is free text, and refusing to print a name because it is not in the expected script would be worse than printing it.

### ✅ SMS is real: REVE Systems — 2026-08-22

The `sms` channel sends actual messages. A vendor was named — **REVE Systems** (`smpp.revesms.com`), the SMPP/HTTP gateway whose configuration sheet and two Postman collections the client supplied — which unblocked the one Phase 5 item that was engineering-ready and waiting on a name. WhatsApp is untouched and still blocked on Meta.

**The gateway's whole surface, and what was built against each part:**

| REVE endpoint | Here |
|---|---|
| `/sendtext` — one body, `toUser` comma-separated | `ReveSmsClient::sendText()`, behind `SmsDriver` |
| `/send` — several caller/message groups at once | `ReveSmsClient::send()` |
| `/getstatus`, `/getmultistatus` | `ReveSmsClient::status()` / `multiStatus()`, driving `sms:poll-dlr` |
| `/submitstatus` (their DLR push shape) | `POST\|GET /webhooks/sms/dlr` |
| `/api/v2/balance` | `php artisan sms:test --balance` |
| `/mo/message` (inbound SMS) | **not built** — see Still open |

**⚠️ The response format was inferred when this landed — that is now closed.** The supplied material specifies each *request* precisely and contains no saved response at all, so the parser was written to accept every plausible shape. It has since been verified against a live deployment and the real format documented; see [§Why no SMS was sending](#-why-no-sms-was-sending-and-the-real-reve-response-format--2026-08-22), which also records the two real defects that verification exposed. The wider tolerance is kept deliberately: the vendor runs many per-reseller deployments and only one has been observed.

**Shipped:**

- `App\Domain\Notification\Gateways\ReveSmsClient` — the only class that knows REVE's wire format. Host, transport (`post` JSON or `get` query) and auth style (keys in the body, or the `/sendtext/{apikey}/{secretkey}` path variant) are all config, because the vendor publishes six interchangeable hosts and the account decides which auth form is enabled. **POST is the default deliberately**: a GET puts the message body — a real person's name, their ticket number — into every proxy access log between here and Dhaka.
- `App\Domain\Notification\Channels\SmsDriver`, resolved for `sms` in place of `FakeSmsDriver` once credentials exist. **The fallback is deliberate and visible**: a dev checkout and CI have no REVE account, and a resolver that threw would take the whole outbox down including the email half that works — but every row the fake writes carries `provider = fake_sms`, never `revesms`, so simulated delivery cannot be misread as real in the admin delivery log.
- `App\Domain\Notification\Support\Msisdn` — the number format REVE addresses a handset by. This is **not** in `AttendeeIdentity`, which normalises for storage and explicitly refuses to guess that `01711…` means `+8801711…` because that guess is wrong for an overseas alumnus. Here the guess is necessary (a leading `0` is a national trunk prefix no gateway understands) and cheap to get wrong (one undelivered message, not a corrupted identity row). A number already written internationally passes through untouched, so `+44…` reaches the UK rather than being forced into Bangladesh's country code — `test_a_foreign_number_is_never_rewritten_as_bangladeshi` exists because widening the national-form branch to "anything 10–11 digits" would silently make `+44 7700 900123` into `88044…`.
- **`ACCEPTD` is not `delivered`, and that distinction is the point of `ReveSmsDeliveryState`.** The wire values are SMPP v3.4 §5.2.28 `message_state` names, which a Bangladeshi carrier passes through end to end. `ACCEPTD` means the carrier took the message and nothing more — reporting it as delivered would claim a handset had a ticket that is still queued behind a switched-off phone. An **unrecognised** receipt stays `pending` rather than being guessed either way: a delivery that did not happen and a failure that did not happen are both worse than "nothing yet", and the raw value is kept on the event row for whoever adds the mapping.
- **Two ways a receipt arrives, one place it is written.** `Notification\Actions\RecordDeliveryReceipt` is the only code that moves a notification `sent → delivered|bounced`, so the push callback and the poll cannot disagree. Whichever arrives first wins; the other records its timeline entry and performs no transition. It re-reads the row `lockForUpdate()` inside the transaction specifically because a push and a poll landing together would otherwise both see `sent`, both attempt the transition, and the loser would throw `InvalidStateTransitionException` out of a webhook.
- **`sms:poll-dlr` is not a backstop, it is the primary path today.** Pointing REVE at `POST /webhooks/sms/dlr` is a setting on *their* account console — nothing in this repo can turn it on — so until somebody with that login makes the change, polling is the only way a delivery state is ever learned. Scheduled every five minutes, a no-op with no credentials. It asks only about messages older than `--min-age` (a receipt does not exist the instant a message is accepted) and younger than `--max-age` (a carrier silent for two days is not going to speak, and re-asking forever would grow the sweep without bound across the event).
- **The DLR callback answers an unknown message id exactly as it answers a known one** — same 200, same body. A 404 would be an oracle for enumerating live message ids, and REVE would read any error as a failed callback and retry it indefinitely. It is refused outright (401) when no credentials are configured, rather than accepting an unauthenticated caller: an open endpoint that rewrites delivery state is worse than one switched off. The stored `raw_payload` has the credentials stripped — that column is rendered in the admin delivery timeline and lands in every database backup.
- **Cost is computed locally, from the segment count.** REVE bills per segment against a prepaid balance and returns no price on a send, so `REVESMS_COST_PAISA_PER_SEGMENT` must match the contracted rate for the delivery-cost report to mean anything. It is reporting, not billing. Segments come from the existing `SmsSegmentCalculator`, so the Bangla-costs-2–3× rule `config/notifications.php` warns about is now being paid for real: a 80-character Bangla message bills as two segments where the same length in Latin bills as one.
- **No `->retry()` on the HTTP client, deliberately.** A send is not idempotent at REVE — there is no client-supplied request id to deduplicate on — so a retried request the first attempt had already accepted delivers the message twice and bills for it twice. `SendNotificationJob` owns the retry schedule and retries against a row whose state records whether the last attempt landed.
- `php artisan sms:test` — one real send (`--bangla` for a Unicode probe), `--status=<id>`, `--balance`. Writes no outbox row and ignores the kill switches: it checks the gateway, not the pipeline above it.
- 51 tests (`ReveSmsClientTest` 16, `SmsDeliveryTest` 22, `MsisdnTest` 13). Every *request* shape is pinned against the vendor's own material; the response tests exercise all the shapes the parser accepts, which is the honest state of it until a live call narrows them.

**Verified end to end against a real HTTP server**, not only `Http::fake()` — a local stand-in speaking REVE's documented request contract, so the config wiring, the transport and the command were exercised over a real socket: `sms:test "01711-223344"` reached it as `POST /sendtext` with `callerID=DEC100`, `toUser=8801711223344` (normalised from the dashed national form) and the message body; `--status` mapped `DELIVRD` to `delivered`; `--balance` parsed `1420.50`; `--bangla` billed at the Unicode rate; a wrong secret exited non-zero with the gateway's own reason; and `REVESMS_AUTH_STYLE=path REVESMS_METHOD=get` produced `GET /sendtext/{apikey}/{secretkey}` with both keys out of the query string.

**Still open:**

- **No live call to REVE itself.** No account credentials exist in this environment, so the inferred response shapes above are still inferred. This is the first thing to do when the account lands.
- **MO (inbound SMS) is not built.** `/mo/message` in the vendor collection is how a reply or a keyword to a short code would reach us, but nothing in this system has anywhere to put one — no model, no screen, no consumer — so an endpoint for it would drop messages on the floor while looking like a feature. It needs a decision about what inbound SMS is *for* first.
- **`REVESMS_DLR_IP_ALLOWLIST` is an intentional no-op until someone supplies REVE's real source ranges**, same as `SSLCOMMERZ_IPN_IP_ALLOWLIST` — a guessed range silently drops every real receipt, which is worse than leaving the key-pair check as the only gate.
- **Nothing surfaces the prepaid balance in the admin console.** `sms:test --balance` is a console command; a balance that runs out mid-event stops every SMS with nothing on screen to say why. That wants a tile on the notifications dashboard and probably a low-balance alert.
- **The bulk `/send` endpoint has no caller.** `SendNotificationJob` drains one outbox row at a time, so batching would mean one job owning several rows' state. It is built and tested because an operator-initiated broadcast is the obvious next thing to want, and it is materially cheaper per message at volume.

### ✅ SMS credentials move to the Settings screen, and `is_encrypted` becomes real — 2026-08-22

The REVE API key, secret key and sender ID are set from **Settings → SMS gateway** instead of `.env`, and the account's prepaid balance is shown beside them.

**This required making `is_encrypted` do something.** It has been a column on `event_settings` since the table was created and **nothing implemented it** — no encryption on write, no decryption on read, and `EventSettingResource` returned `value` verbatim. So putting a credential there would have written it in plaintext to the database, every replica, every nightly `db:backup`, *and* the `GET /admin/settings` response. That is precisely what this file's Payments rule forbids ("never in an unencrypted `event_settings` row"). Three things now hold together, and the rule is satisfied rather than bent:

1. **Encrypted at rest.** `castForStorage()` encrypts under `APP_KEY`; `decrypted()` reverses it. A blank value **clears** the row rather than storing an encrypted empty string — otherwise `hasValue()` would report a credential as configured and every send would fail.
2. **Write-only across the API.** `EventSettingResource` sends `value: null`, `typed_value: null`, plus `is_secret`, `is_set` and `masked_value` — even in the response to the write that just set it. A secret cannot be read back by anyone, Super Admin included; it can only be replaced.
3. **Redacted from the audit trail.** `SettingController::update()` was dumping `$setting->toArray()` — `value` and all — into `activity_logs.properties`. That table is append-only, has no redaction path and is read from the admin console, so a credential written there is there for good. It now records `[redacted]`/null: *who* changed the SMS secret key, when and from where is the whole point of the entry, and the value adds nothing to it but exposure.

**Masking is deliberately all-or-nothing under 9 characters.** A real REVE secret key is 8 (`9e138d90`); showing its last four would cut what is left to guess from 16⁸ to 16⁴. A 16-character apikey shows `••••••••d338`, which is enough to tell one key from another. Both cases have a test naming the reason.

**`SmsGatewayConfig` is the resolution order, and the database wins.** `event_settings` group `sms` first, then `config/services.php` (so `.env`). The point of a settings screen is changing a sender ID at 9pm without a deploy, so a stored value has to beat the deployed image — but **a blank setting is not an override**, it means "not configured here", so clearing a field falls back to `.env` rather than breaking a deployment that was set up that way. It is a container singleton with a per-instance memo (one query per request or job), flushed by the settings controller on write, so an edit applies to the very next send instead of after a cache TTL nobody can see. It reads nothing when `event_settings` does not exist yet, so a queue worker booting mid-deploy falls back to config instead of fataling.

**`auth_style` and `method` are deliberately not on the screen.** They describe how the account was provisioned, not something an operator tunes, and a wrong value takes SMS off the air entirely. `SmsGatewayConfig::KEY_MAP` is the allowlist of what a setting may override.

**Balance and recharge.** `GET /admin/notifications/sms-balance` (`notification.view_costs`) reports the live balance, an estimated segment count, the low-balance threshold and the portal URL. **There is no recharge API** — REVE exposes send, status and balance and nothing else — so the card's button is a link out to their billing portal and the balance is how the top-up becomes visible afterwards. An unreachable gateway answers **502, not a zero balance**: "we could not ask" and "the account is empty" lead an operator to opposite actions, and rendering them the same sends someone to top up a wallet that is already full. `estimated_segments` is an estimate from `sms.cost_paisa_per_segment`, a local figure REVE never returns — the description on that setting says so, because a wrong rate makes every cost figure in the system wrong.

**New settings** (run `php artisan db:seed --class=EventSettingSeeder`): `sms.api_key`, `sms.secret_key` (both encrypted, seeded empty — a placeholder would look like a working config while failing every send), `sms.sender_id`, `sms.base_url`, `sms.client_id`, `sms.cost_paisa_per_segment`, `sms.low_balance_threshold_paisa`, `sms.recharge_url`. The seeder now fills metadata *before* the value, because `castForStorage()` branches on `is_encrypted` and the old order would have seeded a credential in plaintext.

**Found by running it, not by a test:** `sms:test` printed `smpp.revesms.com:7790` and a blank sender ID while actually sending to the dashboard-configured host — it read `config()` directly instead of the resolver. The send was right and the header lied about it, which is the worse way round. Fixed.

**Verified against the running app**: the four settings saved over HTTP came back with `value: null` and `masked_value` only; the database column held `eyJpdiI6…` ciphertext; the audit rows recorded `[redacted]`; the balance endpoint returned ৳1,420.50 and 3,945 segments at the configured 36 paisa; and both the balance call and a real send carried the **dashboard** key rather than the one in `config`. 15 tests in `tests/Feature/Admin/SmsGatewaySettingsTest.php`. Full suite **611 passing / 2 skipped**, Pint and PHPStan level 8 clean, SPA typecheck + build clean. OpenAPI regenerated — **115 paths** (was 114).

**Still open:**

- **Rotating `APP_KEY` now silently breaks these rows.** `decrypted()` returns null and logs rather than throwing — deliberately, so a key rotation does not take the whole settings screen down with a 500 and hide the page that explains what happened — but nothing re-encrypts them, and the row still reports itself as set. A key rotation needs the secrets re-entered. The same is true of `QR_SIGNING_PRIVATE_KEY`; neither has tooling.
- No low-balance *alert*. The card warns when someone is looking at it; nothing notifies anyone who is not.
- The balance is fetched on demand with a 60s stale time and no server-side cache, so several admins on the page each cost a round trip to Dhaka.

### ✅ Why no SMS was sending, and the real REVE response format — 2026-08-22

Two independent faults, and the response format is no longer guesswork.

**Fault 1 — the gateway host is per-reseller, and ours was another operator's.** REVE licenses its platform to resellers who each run their own instance; **credentials are only valid on the instance that issued them.** Our account lives on `smpp.ajuratech.com`; every default in this repo pointed at `smpp.revesms.com`, taken from the vendor sheet. Verified live: the same real credentials answer

- `smpp.revesms.com:7790` → **HTTP 200 with a completely empty body**
- `smpp.ajuratech.com:7790` → a real, parseable response

An empty 200 is the worst possible failure shape — it is not an auth error, so it read as a parser bug rather than a misconfiguration. `parseSendResponse()` now names the likely cause in as many words instead of reporting "the gateway said 200". The default, `.env.example` and the seeded setting all point at `smpp.ajuratech.com:7790`, and the config comment says plainly that this is per-account. **Note the bare origin is the billing portal, not the API** — `https://smpp.ajuratech.com/sendtext` answers 302 to a login page. The API is on `:7790` (TLS), `:7788` (cleartext), or the `api…` host with no port.

**Fault 2 — no sender ID, so nothing was ever attempted.** All three of api key, secret key and sender ID are required; `sms.sender_id` was empty, so `isConfigured()` was false, the resolver handed back `FakeSmsDriver`, and no request was made at all. The old message for this named `REVESMS_*` env vars — actively misleading once the values live in Settings. `ReveSmsClient::missingCredentials()` now returns the missing ones *by their Settings label*, surfaced on the balance card ("still needed: SMS sender ID") and by both console commands. "Not configured" alone is the least useful thing this integration can say: three values are required and two are invisible once saved.

**The response format, verified rather than inferred** (via `/getstatus` and a send to an unroutable number — the `SslCommerzClient`-shaped unknown this file flagged is now closed):

```
{"Status":"0","Text":"ACCEPTD","Message_ID":"353406678"}
{"Status":"109","Text":"Invalid api key/secret key","Message_ID":""}
{"Status":"114","Text":"REJECTD","Message_ID":"","Delivery Time":"0"}
```

Four things it does that the parser now survives, each seen for real:

1. **`Status` is authoritative; `Text` is not — and this was a genuine bug.** A *request* rejection (bad keys `109`, unknown id `114`) comes back as `Text: REJECTD` — the same word an undeliverable message uses. `parseStatusResponse()` read `Text` first, so **an authentication failure during `sms:poll-dlr` would have settled every healthy message as `bounced`**, which is terminal and unrecoverable. Only a **numeric** `Status` is treated as a request code, because a deployment that puts the delivery word itself in that field (`status: DELIVRD`) is reporting a receipt, not an error.
2. **No `Content-Type` header at all.** `Response::json()` decodes on body alone, so this works — but nothing may ever branch on content type here.
3. **`Message_ID` is `""`, not absent**, on every error.
4. **"No news" is not JSON.** `/getmultistatus` answers `[,,]` and `/getstatus` answers an empty body when there is no receipt yet. Neither is an error and neither is a verdict; both now decode to nothing rather than to a row carrying `[,,]` as a delivery state.

**Two auth/transport variants from the collection that were missing**, both confirmed working live: `auth_style=basic` (HTTP Basic, username=apikey password=secretkey) and `method=form` (x-www-form-urlencoded — the fallback when something in front of the gateway will not forward a JSON body).

**`/api/v2/balance` is not available on every deployment.** Ours answers `{"Status":"ERROR"}` for *any* credentials, valid or not. That is "this account cannot report a balance", which is not a balance of zero and must not render as one — hence `balance_available`, and a card that says so rather than showing ৳0.

**Caught by a test, not by review:** the new `sms.base_url` description ran past `event_settings.description`'s VARCHAR(255) and would have broken `db:seed` on a fresh deployment. All 29 seeded descriptions were checked; nothing else is over.

11 more tests in `ReveSmsClientTest` (25 in the file), each fixture a body copied from a real call. Full suite **621 passing / 2 skipped**, Pint and PHPStan level 8 clean, SPA typecheck clean.

**Masking and non-masking, added the same day at the client's request.** The account sends **non-masking** (from a number) and wants masking (a branded sender name) available later, so `sms.masking_enabled` is a toggle, defaulting **off**.

The load-bearing fact, confirmed live rather than assumed: **`callerID` is mandatory in both modes.** Omitting it or sending it empty answers `114 Inappropriate request parameter` and submits nothing — so the mode never decides *whether* a sender ID is needed, only what shape belongs in it:

| | sender ID | recipient sees |
|---|---|---|
| masking off (default) | digits only, e.g. `8809612` — the vendor's own examples are numeric | a number |
| masking on | operator-approved brand name, ≤ 11 chars (GSM 03.38) | the name |

**The shape is validated at save time**, in `SmsSenderId::problemWith()`, because the gateway accepts either string and the mismatch fails later *at the carrier* — where there is no error message and nothing to look at. A brand name saved while masking is off is a 422 naming the toggle; a masking name over 11 characters is refused too, since the carrier drops it silently rather than the gateway refusing it. Flipping the toggle flushes the memo, so the very next save is judged against the new mode.

`sms.masking_enabled` is deliberately **not** in `SmsGatewayConfig::KEY_MAP` — it is not a gateway credential and nothing is sent differently because of it. It is a `bool`, so it renders as the existing save-on-flip switch with no new UI primitive.

**Still true, and it is the remaining step:** no message has been delivered to a handset yet. Put your account's numeric sender in Settings → SMS gateway → *SMS sender ID*, then `php artisan sms:test <number>`.

### ✅ Attendee sign-in is real SMS, and dev mode is gone — 2026-08-22

With SMS delivering for real, the attendee magic-link login was switched off dev mode — and doing that uncovered that **the login SMS had never worked at all**. Two bugs, both invisible for exactly as long as the shortcut existed.

**The shortcut.** `POST /attendee/auth/request-link` returned `debug_token` — the plaintext sign-in token — whenever `app()->environment('local', 'testing')`, and the public site's `MagicLinkForm` rendered it as a "Dev mode — verify now" link. Anyone who could reach the endpoint could post a mobile number and get back a token that signs them in **as that attendee**. It was gated to `local`, so it was never live in production; it *was* live on a development machine that now holds real attendee records and real gateway credentials. It is removed outright rather than put behind a flag — a flag is a thing someone can switch on.

**Bug 1 — the SMS had no body.** The controller wrote its outbox row with `Notification::create()` directly, passing `payload` and no `body_rendered`, and **there was no `attendee.login_link` template seeded at all**. `SmsDriver` casts a null body to `''`, so the message would have gone out empty.

**Bug 2 — and nothing would have sent it anyway.** That hand-written row never dispatched `SendNotificationJob`. The row was created `queued` and nothing in the system drains a row it was not handed; it would have sat there forever.

Neither showed up because every test read the token out of the HTTP response, so no test ever touched the delivery path. This is the docs/08 R12 failure mode again — a test that exercises the wrong code path — and it is the fourth occurrence recorded in this file.

**Fixed by going through the outbox action** like every other notification. `executeForRecipient()` rather than `execute()`, and the reason is the dedupe key: `execute()` keys on (notifiable, template, channel), which is right for a one-per-event notification and would silently swallow somebody's **second** sign-in request as a duplicate. The token's hash is the dedupe suffix, so every request is its own message. Tests now recover the token from the rendered SMS body, which is what the recipient actually receives.

**The link is built from `services.frontend.url` server-side**, never from the request — the same rule the payment return legs follow, and for the same reason: a client-supplied origin here mails an attendee a link that hands their session to someone else.

**Where the reader was heading survives the round trip.** `next` used to reach `/verify` only through the dev-mode link, so removing that block would have quietly killed it. The SMS carries the token and nothing else — there is no room in a segment-billed message for a return path, and a URL that changed per visit would be a phishing tell — so `/login` remembers it in `sessionStorage` and `/verify` reads it back, falling through to `/dashboard` when the SMS is opened on another device. `safeNextPath()` moved into `features/attendee/nextPath.ts` and both paths share it: stored state is no more trustworthy than a query param, since another tab could have written it.

**⚠️ Two things to set before a real attendee signs in:**

1. **`FRONTEND_URL` is still `http://localhost:3000`.** The sign-in link is built from it, so every login SMS currently sends a localhost URL that is useless on a phone. This is the single highest-value thing to fix.
2. **Bangla costs 3× per sign-in.** The link alone is ~70 characters, and one Bangla character forces the whole message to Unicode at 70 per segment. Measured against the seeded copy at the configured 36 paisa/segment:

   | locale | chars | segments | per sign-in |
   |---|---|---|---|
   | `bn` (default) | 150 | 3 (Unicode) | ৳1.08 |
   | `en` | 155 | 1 (GSM-7) | ৳0.36 |

   Both rows are seeded and as short as the meaning allows. Set `notifications.locales.sms => 'en'` in `config/notifications.php` if the saving is worth more than the language — at 12,000 attendees it is a real bill, and it is a business decision rather than a technical one.

**Still `local`-gated, deliberately left alone** — these were not what "dev mode" referred to, and neither is currently doing anything: the admin 2FA bypass in `Admin\AuthController` (no user has `two_factor_confirmed_at` set, so it is inert today) and CSP suppression in `SetSecurityHeaders` (Vite's HMR injects unnonced tags no strict policy survives). `APP_DEBUG` is also still `true`. Say so if you want any of them tightened.

Full suite **630 passing / 2 skipped**, Pint and PHPStan level 8 clean, admin SPA typecheck clean; public site `tsc`, ESLint (0 errors, 7 pre-existing warnings) and `next build` clean. OpenAPI regenerated — still 115 paths, with `debug_token` gone from the documented response.

### ✅ Attendee sign-in uses a password; SMS is once, or never — 2026-08-22

Signing in used to send an SMS **every time**. It now sends one only when there is no other way in. A password is chosen during checkout, so an attendee who registers online never triggers a paid message at all.

**The cost, which is the reason for the change.** Measured against the real 36 paisa/segment rate on the account:

| | segments | per send |
|---|---|---|
| the old Bangla sign-in link | 3 (Unicode) | ৳1.08 |
| the new English code | 1 (GSM-7) | ৳0.36 |

At 12,000 attendees signing in twice, the old design billed roughly **৳26,000**. The new one bills for activations of people who never registered online, plus forgotten passwords — a small fraction of that, and it falls as adoption rises rather than growing with traffic.

**SMS is English now** (`notifications.locales.sms => 'en'`), by explicit decision. It is a billing decision as much as a language one: GSM-7 fits 160 characters per segment and a *single* Bangla character drops the whole message to 70. Email and WhatsApp are untouched and stay Bangla.

**A six-digit code replaced the link**, and it is both cheaper and friendlier. The reader stays in the tab they started in rather than following a link that opens whichever browser is default; the field carries `autocomplete="one-time-code"`, so a phone offers the code straight from the notification; and an OTP is the sign-in every Bangladeshi phone already understands. `/verify` is a `permanentRedirect` to `/login` rather than a deletion, so an old link lands somewhere that works.

**Six digits is a million guesses, and the length is not what makes it safe.** `verify()` burns the code after **five** wrong attempts (`attendees.auth_code_attempts`), counted *before* the comparison so a near miss costs the same as a wild one. The route limiter sits at ten a minute rather than five, deliberately: the app's own ceiling should be what a mistyped digit hits, with a clear message, not a bare `429`. `verify()` also takes the **mobile** now — a six-digit code is not unique across attendees, and matching on it alone would let one person's code open whichever account happened to share it.

**The load-bearing security rule: a password supplied at registration is applied only when the attendee has none.** `POST /public/registrations` is unauthenticated and resolves a *returning* registrant by mobile number, so a path that overwrote an existing password would be a complete account takeover — register with somebody else's number, choose a password, and their account is yours. A returning registrant keeps what they had and the submitted value is discarded in silence, because telling an anonymous caller that the number already has an account is the enumeration signal the rest of this flow is careful not to leak. `test_registering_again_cannot_overwrite_an_existing_password` pins it, and it was verified against the running app.

**Two abuse surfaces closed, both of which cost real money:**

- `request-code` had **no limiter of its own** — only the shared `api` bucket at 60/min, which let one IP spend roughly **৳2,000 an hour** of prepaid balance. It now has two buckets, because they stop different things: **3/hour per mobile** (burning one victim's balance and filling their inbox) and **20/hour per IP** (a script walking a list of numbers, which the per-mobile limit alone would happily allow). docs/06 §6.7 already required this — "throttled primarily to control SMS/email cost" — and it had never been implemented.
- An unknown number costs nothing: `requestLink()` returns the generic response before writing anything, so a number range cannot be walked into a bill.

**Sign-in answers identically for a wrong password and an unknown number** — same status, same body — and the bcrypt comparison **runs either way**, against a dummy hash when there is nothing real to compare with. Returning early for an unknown number would answer in microseconds where a real one costs a full verify, and that difference is measurable: account existence would be discoverable with a stopwatch despite the identical body.

**`01711111111` signs in.** Nobody types `+880` into a login box on their own phone. `AttendeeIdentity::mobileLookupCandidates()` offers both forms to the query — and it is a *lookup* helper, deliberately separate from `normaliseMobile()`, which still refuses to canonicalise because it is the value a uniqueness constraint is built on and the guess is wrong for an overseas alumnus. Here being wrong costs one failed match rather than a corrupted row.

- `attendees.password` is nullable, cast `hashed`, and outside `$fillable` — a credential must not be settable by mass assignment from any array that happens to carry the key. Nullable is the design: 22,000 existing rows have none and there is nothing truthful to backfill.
- `POST /attendee/auth/password` sets the first one (no current password required — there is none) or changes an existing one (required, because a bearer token outlives the moment it was issued and a borrowed phone must not lock its owner out). It **revokes every other session** and spends any outstanding code, so a reset SMS from before the change stops working.
- After a code sign-in the response carries `must_set_password`, and the public site asks straight away — that is the one moment somebody without a password is definitely present and definitely authenticated. Skipping is allowed; pressing it on someone who came to check their ticket is a good way to lose them.

31 tests across `AttendeePasswordAuthTest` (18) and a rewritten `AttendeeAuthTest` (13). Full suite **649 passing / 2 skipped**, Pint and PHPStan level 8 clean; public site `tsc`, ESLint (0 errors) and `next build` clean. OpenAPI regenerated — **117 paths** (was 115).

**Verified against the running app**: a registration carrying a password returned 201; signing in with `01799000111` (national form) returned a session and **wrote no notification row at all**; re-registering with the same number and a different password left the original working and the new one refused; and the fourth `request-code` in an hour answered 429.

**Still open:** `FRONTEND_URL` is `http://localhost:3000`, which no longer breaks sign-in (the SMS carries no link) but still affects the ticket email's CTA. The admin 2FA bypass and CSP suppression remain `local`-gated and inert.

### ✅ One SMS per ticket purchase, and templates are editable — 2026-08-22

A ticket purchase used to fire **three** SMS — booking, payment, ticket. It now fires **one**, and the wording of every message is editable from the admin console.

**Booking and payment no longer use the SMS channel.** `QueueRegistrationReceivedNotification`, `QueuePaymentSucceededNotification` and `QueueManualPaymentVerifiedNotification` are `['email', 'whatsapp']`. The ticket confirmation keeps SMS, because it is the message that says *you are in, and where to be*, and it now carries the event details the other two used to. `payment_failed` and `refund_issued` deliberately keep theirs: neither is part of a normal purchase, both need attention rather than a record, and an unopened email is no use for a payment that did not go through.

At the seeded rate that is **৳1.08 → ৳0.36 per purchase**, or roughly **৳12,960 → ৳4,320** across 12,000 sales.

**The new ticket SMS** (option D of four the client was shown, priced before anything was written):

```
Ticket confirmed - {{event_name}}
ID: {{ticket_id}}
{{event_date}}, {{event_time}}, {{venue}}
QR ticket sent to your email. Keep it for entry.
```

144 of the 160 GSM-7 characters — one segment, with 16 to spare. Date, time and venue come from the ticket's own **session** rather than the event-wide setting, so an evening ticket does not print the morning's time.

**The template the client first proposed did not work, and would have cost 5×.** Worth recording, because both failures are silent:

1. **Its variables did not exist.** `interpolate()` replaces only the keys the dispatching listener passes and leaves anything else alone — so `{{customer_name}}` and `{{event_name}}` would have been **sent as literal text** to real ticket-holders. The listener now supplies all six (`customer_name`, `ticket_id`, `event_name`, `event_date`, `event_time`, `venue`) alongside the original four, which are kept exactly as they were because the email and WhatsApp templates interpolate them.
2. **Emoji are not GSM-7 — and neither is a plain `|`.** Nor `{ } [ ] ~ ^ \ €`. Any one of them drops the whole message from 160 characters per segment to 70. The proposal measured 5 segments (৳21,600 across 12,000); the same words with the emoji and the pipe removed measured 2.

**`event.name_en` / `event.venue_en` are new**, because `event.name` and `event.venue` hold Bangla and SMS is English — one Bangla character in an English SMS triples the segment count for the event's name alone. Seeded short (`NHS Centennial`, `School Campus`) precisely because the message has only 16 characters of headroom, and both descriptions say so.

**A bug this uncovered: `{` and `}` are not GSM-7 either.** So measuring a *raw* template body reports Unicode for anything containing a placeholder — the seeded ticket confirmation measured **3 segments raw against 1 rendered**, a 3× over-count in the direction that makes an affordable message look unaffordable. `SmsSegmentCalculator::renderForEstimate()` substitutes placeholders before measuring and is shared by the seeder, the save path and the live preview, so those three can never disagree. The class docblock also claimed extension-table characters were "treated as GSM-7-safe" when the alphabet excludes them; the comment was wrong, not the code, and now says so.

**Templates are editable** — `notification_templates` gained a `ulid` (an auto-increment id must not cross the API boundary), the resource now returns `body`, `variables` and `estimated_segments` (it previously returned neither the message nor its variables, which is why the screen could only ever list names), and three endpoints exist under `notification.manage_templates`:

- `POST /admin/notifications/templates` — create. A duplicate (key, channel, locale, version) is a **422 naming the conflict** rather than the unique index being hit raw, which is a 500 with SQL in the log.
- `PATCH /admin/notifications/templates/{ulid}` — edit wording, subject and active state. **Identity is create-only**: retargeting a template would silently change which notification it renders, so a different message is a different row.
- `POST /admin/notifications/templates/preview` — encoding, segments and projected cost for a draft, before it is saved.

`SaveNotificationTemplate` writes its own `ActivityLog` row (D8 discipline) recording the before/after body — unlike a credential, what the text used to say is exactly the point of the entry — and finally populates `estimated_segments`, a column that had existed unused since the table was created.

**The editor shows the two things that are invisible while typing**, which is the whole reason it exists rather than being a plain text box: the **available variables** as clickable chips, with a warning naming any `{{placeholder}}` used that this template does not supply; and a **live cost readout** — characters, encoding, segments, cost each and cost across 12,000 — with an explicit warning listing the characters that force Unicode. The preview is debounced 350ms and measured server-side, so the number in the editor, the number in the list and the number that is billed all come from one implementation.

11 tests in `NotificationTemplateAdminTest`, plus two rewritten outbox tests — `test_booking_notifies_by_email_and_whatsapp_but_never_by_sms` keeps an *active* SMS template present deliberately, to prove the channel list is what excludes it rather than a missing row. Full suite **660 passing / 2 skipped**, Pint and PHPStan level 8 clean, SPA typecheck and build clean.

**Verified against the running app**: the templates endpoint returns the ticket SMS at 1 segment (en) and 2 (bn) with all ten variables; the preview endpoint priced the original proposal at 4 segments / ৳12,960 per 12,000 against template D's 1 segment / ৳3,240 at the account's current 27 paisa rate. Nothing was written to the database.

**Needs `php artisan db:seed --class=EventSettingSeeder` and `--class=NotificationTemplateSeeder`** for the new settings and the new ticket copy.

### ✅ Deploy jobs added, following `decentedu` — 2026-08-22

`.github/workflows/deploy-image.yml` became `deploy.yml` and gained the two jobs it had always been missing. The structure follows the sibling `decentedu` project's `deploy.yml` — same job shape, same variable and secret names (`STAGING_HOST`, `PRODUCTION_HOST`, `DEPLOY_USER`, `DEPLOY_SSH_KEY`), same `workflow_dispatch` environment choice, same no-op-rather-than-fail behaviour when a host is unconfigured — so an operator who knows one repo knows this one.

**The three existing gates are kept**, because a deploy step cannot catch what they catch: the migration set must apply to a fresh MySQL, the image must boot, and the Chromium binary the PDF renderer shells out to must exist (a base-image change that dropped it would pass everything else and surface as tickets failing to render).

**Staging deploys on every push to `main`. Production needs a manual dispatch *and* the `production` environment's required reviewers** — the job does not start until approval is granted. Nothing that reaches real ticket-holders should be a side effect of a merge.

**Three departures from `decentedu`, each because this application is shaped differently — copying it verbatim would have produced a workflow that fails or, worse, silently deploys the wrong thing:**

1. **No `nginx` service.** nginx and php-fpm share one container here (the Dockerfile's runtime stage bundles them, because `fastcgi_pass` hands PHP-FPM a filesystem path and both processes must see identical files at identical paths). `decentedu`'s `up -d … nginx` would fail on an unknown service.
2. **The image is pinned by *writing* the override file, not `sed`ing it.** `docker-compose.prod.yml` declares `build:`, not `image:`, so there is no `image:` line to substitute — and `decentedu`'s `sed … || true` would match nothing, exit 0, and deploy **the previous release while reporting success**. The file is written from scratch each run, so it cannot half-apply.
3. **The production backup runs through `prod.yml` alone.** The override file does not exist on a first deploy and a missing `-f` is a hard error; `exec` finds the running container by project name regardless.

**Ordering that matters.** `migrate --force` runs *before* the container swap, against the old running container, so a failed migration aborts with the previous release still serving. `horizon:terminate` rather than a kill, so Horizon finishes the job in hand instead of dropping a notification or a half-rendered ticket. Production takes a `db:backup` — gzip plus the row-count manifest `db:restore --verify` reads — as the last step before anything touches a database holding real registrations and payments. `concurrency` is `cancel-in-progress: false`: interrupting between `migrate --force` and the swap leaves a database ahead of the code running against it.

**Each deploy ends by polling `/up` for a 200**, which is the deep check (database, Redis, filesystem write), not "did the container start". A release that comes up unhealthy fails the workflow loudly instead of serving quietly.

**Still open:** no host is configured, so both jobs are no-ops — that is the unpicked hosting provider in External Dependencies, unchanged. **Rollback is untested**: the sha-tagged images make it a `docker compose pull` of the previous tag, but nothing rehearses it and nothing reverts a migration. ~~And the deploy runs no seeders on purpose — `NotificationTemplateSeeder` would silently revert dashboard edits.~~ **Fixed 2026-08-22**: that seeder now follows the same admin-owns-the-value rule as `EventSettingSeeder`, so `subject`, `body`, `is_active` and `whatsapp_template_status` are seeded only for a row that does not exist yet. `variables` is deliberately still refreshed on every run — which placeholders exist is decided by the dispatching listener, and a stale list is worse than none because the editor shows it as the set that is safe to use — and `estimated_segments` is recomputed from the body the row actually holds, so an edited template does not report the cost of the copy it replaced. Three tests pin it, each confirmed to fail against the previous `updateOrCreate`. The deploy still runs no seeders, but re-running them by hand is now safe.

### 💳 Payments — development environment

Development runs against the **SSLCommerz sandbox** (<https://developer.sslcommerz.com/doc/v4/>). Credentials are self-service — no merchant onboarding — so the full money path is live in development. **`sslcommerz` is the public checkout's default gateway** (`services.payment.default_method`, `PAYMENT_DEFAULT_METHOD`); `bkash`/`nagad`/`rocket` still resolve to `FakeGateway` pending Phase 4B, so never make one of them the default.

**Verified against the live sandbox 2026-08-14** — this is no longer an unexercised adapter. See [§SSLCommerz live-sandbox verification](#-sslcommerz-sandbox-verified-live-and-two-real-defects-fixed--2026-08-14) below for the two defects that verification exposed.

| Purpose | Sandbox endpoint |
|---|---|
| Session initiation | `POST https://sandbox.sslcommerz.com/gwprocess/v4/api.php` |
| Order validation (`val_id`) | `GET https://sandbox.sslcommerz.com/validator/api/validationserverAPI.php` |
| Refund | `https://sandbox.sslcommerz.com/validator/api/merchantTransIDvalidationAPI.php` |

The older `sandbox-gw.sslcommerz.com` host still answers the session endpoint but returns the legacy `gw.php?sessionkey=` URL; `sandbox.sslcommerz.com` is what the v4 docs publish and returns the current EasyCheckOut page, so all three calls share that one host. Demo store credentials are **`testbox` / `qwerty`** — `qwerty1234`, which this file and `config/services.php` both previously claimed, is rejected with "Store Password credential mismatch".

`store_id`/`store_passwd` belong in `config/services.php` + `.env`, never committed and never in an unencrypted `event_settings` row. The mandatory order for every transaction: **IPN `verify_sign`/`verify_key` check → `val_id` validation API (accept only `VALID` or `VALIDATED`) → amount/currency re-check against `amount_due_paisa` → only then `succeeded`.** The `success_url` browser redirect proves nothing and must never transition a payment — SSLCommerz's own docs say so explicitly. SSLCommerz transacts in decimal BDT with a 10.00 minimum; that conversion lives inside `SslCommerzClient` alone and no decimal amount may leak past the adapter.

### ✅ SSLCommerz sandbox verified live, and two real defects fixed — 2026-08-14

Phase 4A shipped `SslCommerzClient` in full but explicitly never called a live sandbox ("Treat the exact response field names as needing a first real-sandbox smoke test"). That smoke test has now happened, and it found two defects that no amount of `Http::fake()` could have.

**Defect 1 — the IPN signature check was wrong, so no gateway payment could ever succeed.** `verifySignature()` appended `store_passwd` last and never sorted. SSLCommerz's actual algorithm (their own `SSLCOMMERZ_hash_verify()` in `sslcommerz/SSLCommerz-PHP`) puts `store_passwd => md5(pw)` *into* the field array, `ksort()`s the whole thing, joins `key=value&…`, trims the trailing `&`, and md5s that. Any payload carrying a field sorting after `store_passwd` — `tran_id` and `value_a`, which SSLCommerz always sends — hashes differently, so **every genuine IPN failed verification** and no payment could reach `succeeded`. The v4 docs describe `verify_sign` only as a "Data Validation Key" and never publish the algorithm; their library is the reference.

  The unit test did not catch this because its `sign()` helper reimplemented the *same* wrong construction — the implementation asserted against itself. That is the docs/08 R12 failure mode again (third occurrence, after D1/D2 and the Phase 8 concurrency tests). It is now pinned by `test_signature_matches_sslcommerz_reference_digest`, a hand-computed fixed digest, plus a test asserting the old unsorted construction is *rejected*.

**Defect 2 — `payment_method` was never in `StoreRegistrationRequest`'s rules at all**, so `validated()` stripped it and `CreateRegistration`'s `$data['payment_method'] ?? 'bkash'` always took the fallback. The public checkout could not have reached SSLCommerz even if a caller asked for it. Now allowlisted against `PaymentGatewayResolver::SUPPORTED_GATEWAYS` (made a public const so validation and the resolver cannot drift), with the default in config. This closes the `payment_method` half of D7.

**Also fixed:** `Http::fake()` in `SslCommerzClientTest` matched on the old `sandbox-gw` host, so after the host change one test made a **real network call to the live sandbox** instead of failing. `Http::preventStrayRequests()` is now set in that suite's `setUp()`, so a stale fake pattern fails loudly rather than silently calling a third party. `FRONTEND_URL` was empty, which made every gateway return leg bounce the payer back to the API instead of the site.

**Verified end to end against the real sandbox**, not mocks:

- Registration → `payment.method = sslcommerz` → initiate returns a genuine `https://sandbox.sslcommerz.com/EasyCheckOut/…` URL that serves HTTP 200.
- A correctly-signed IPN (built with SSLCommerz's own algorithm) posted to `POST /webhooks/sslcommerz` is accepted and recorded with `signature_valid = true`.
- **The security invariant holds:** that same accepted IPN left the payment at `failed`, *not* `succeeded`, because the server-to-server `val_id` validation rejected the fabricated id. A forged or replayed IPN cannot mint a paid ticket — signature check → `val_id` validation → amount re-check, in that order, exactly as the docs require.
- All three return legs (`success`/`fail`/`cancel`) land on `{FRONTEND_URL}/registrations/{ulid}?payment_status=…`, and a `next` pointing at `evil.com` is refused and falls back to the configured origin.

**Return page fixed 2026-08-14 — "Confirming your payment" span forever.** Two compounding faults:

1. `PaymentStatusPoller`'s timeout lived in a `useEffect` keyed on `[awaitingConfirmation, status, registration]` that compared `Date.now()` against a ref. While a payment stayed pending the API returned identical JSON, so TanStack Query's structural sharing handed back the **same object reference** and no dep ever changed — the effect never re-ran after mount, where elapsed time is zero. `timedOut` stayed false forever while `refetchInterval` quietly stopped at the 120s cap, leaving a spinner with nothing left to resolve it. Now a real `setTimeout`.
2. The poller only re-read `GET /public/registrations/{ulid}`, which reflects only what an **IPN has already written**. SSLCommerz cannot reach a localhost dev server at all, and a delayed or lost IPN is routine for Bangladeshi MFS in production — so the page could spin on a payment that had genuinely settled.

`POST /public/registrations/{registration}/payment/verify` (`PaymentController::verify`, throttled 20/min) fixes the second: it re-runs the same server-to-server `VerifyPayment` the IPN path uses (`val_id`, falling back to `tran_id`) and re-checks the amount before anything settles. **It accepts no request body**, so the browser's arrival is only a prompt to go ask the gateway — never evidence of payment, and `PaymentVerifyEndpointTest` asserts a caller-supplied `status`/`amount_paid_paisa` changes nothing. Already-settled payments short-circuit without a gateway round trip, so polling is cheap. 4 tests; spec now **106 paths**.

**Found while fixing this, not fixed — flagged:** `VerifyPayment::handle()` dispatches `PaymentSucceeded` inside its own DB transaction, and `IssueTicketForSucceededPayment` runs synchronously. A throw in ticket issuance therefore **rolls back the payment's success** — observed here for real when a missing `QR_SIGNING_PRIVATE_KEY` made `QrSigner::sign()` throw and the verified payment silently reverted to `pending`. In production that means money taken at the gateway with the payment left unsettled until reconciliation. The dev trigger was just the documented `qr-signing:generate-key --if-missing` setup step, but the coupling is the real hazard: issuance belongs on the `tickets` queue lane (`->afterCommit()`), the way `GenerateTicketAssetsJob` already is.

**Still open:** a *completed* sandbox payment (driving the hosted checkout to a `VALID` transaction) has not been run — that needs a browser on the EasyCheckOut page, so `verify()`'s success branch and `refund()` are still exercised only against fakes. `SSLCOMMERZ_IPN_IP_ALLOWLIST` remains an intentional no-op until someone supplies SSLCommerz's real IPN ranges.

### 🚨 External Dependencies (start during Phase 2!)
- [ ] Payment gateway merchant applications (bKash, Nagad, Rocket, SSLCommerz) — **2-6 weeks lead time**. Blocks Phase 4B (live cutover) only; sandbox work is unblocked. Sequence SSLCommerz first.
- [ ] WhatsApp Business template approval
- [x] SMS vendor contract and sender ID — **REVE Systems** (smpp.revesms.com), integrated 2026-08-22. Still needs the account's real `REVESMS_API_KEY`/`REVESMS_SECRET_KEY`/`REVESMS_SENDER_ID` provisioned and one live send; see [§SMS is real](#-sms-is-real-reve-systems--2026-08-22).
- [ ] Domain registration and email SPF/DKIM/DMARC setup
- [ ] Hosting provider decision (added 2026-08-04, Phase 9) — docs/07 §7.3 specs sizing and topology, not a vendor. Blocks everything in Phase 9 past "publish a release image": production infrastructure, CDN/WAF, TLS, the live `deploy` step, on-call, event-day operations.

---

## Repository layout

A single Laravel application at the repo root, which serves **both** the API and the admin dashboard SPA. **`.github/workflows/backend-ci.yml` is the one that actually deploys** — lint, static analysis and `composer audit` on every push, the test suite on pull requests, then a `deploy` job that rsyncs the release to Hostinger over SSH and runs migrations, caches and `admin:create-super-admin --if-missing` on the host (see [docs/09](docs/09-hostinger-deployment.md), and [§First Super Admin](#commands) for the bootstrap). That rsync uses `--delete`, so **anything living only on the server is removed unless the workflow names it** — `.env`, `storage/` and `public/storage` are excluded, and `.htaccess`, `.well-known/` and `.user.ini` are `protect`ed from deletion while still letting the repo's own `public/.htaccess` deploy. `.github/workflows/deploy.yml` (Phase 9) is the **Docker/GHCR path and is not wired to a real host** — it builds and publishes the production image and carries staging/production deploy jobs that are no-ops until `STAGING_HOST`/`PRODUCTION_HOST` are set.

| Path | What lives there |
|---|---|
| `app/Domain/{Module}/` | The seven domain modules — Actions, Models, Policies, Services, Events, Listeners |
| `app/Http/Controllers/Api/{Public,Attendee,Admin,Scanner}/` | Thin controllers, split by audience |
| `app/Http/Controllers/Webhooks/` | Gateway IPN endpoints (one per gateway) |
| `routes/api/` | `public.php`, `attendee.php`, `admin.php`, wired by `v1.php`; plus `scanner.php`, `webhooks.php` at `routes/` |
| `resources/js/` | **The admin dashboard** — Vite + React 19 SPA (see below) |
| `docs/01`–`08` | Design docs. Start at `docs/README.md`; the ADRs explain *why* |
| `Dockerfile`, `docker-compose.prod.yml`, `docker/` | Production container image and reference topology (Phase 9) — not build-tested, see CLAUDE.md's Phase 9 section |

**Three frontends, two repos.** Only one of them is here:

- **Admin dashboard — in this repo** at `resources/js`, a Vite + React 19 SPA served by the catch-all in `routes/web.php`. Deliberately *not* Next.js: it is authenticated-only, so SSR/ISR buys nothing and co-location removes a deploy target.
- **Public site — separate Next.js repo**, consuming this API cross-origin. Needs `config/cors.php` with an explicit origin allowlist, which does not exist yet (D9).
- **Scanner — separate React Native (Expo) app**, `decent-event-scanner`, talking to `routes/scanner.php`. Core offline scan loop landed 2026-08-04 — see that repo's README for scope and what's deferred.

## Commands

**PHP dependencies / setup**
```bash
composer install
cp .env.example .env && php artisan key:generate
php artisan qr-signing:generate-key --if-missing
php artisan migrate
php artisan storage:link
```
`qr-signing:generate-key --if-missing` gives this checkout its own Ed25519 keypair for QR ticket signing (docs/06 §6.5) rather than sharing one across every clone — it's a no-op if `QR_SIGNING_PRIVATE_KEY` is already set. Without it, `IssueTicket` throws on the first ticket issuance.

Without `storage:link`, `public/storage` doesn't exist and every `MediaFile::publicUrl()` (CMS media library, sponsor logos, page OG images, gallery — anything using the `public` disk) 404s: the thumbnail shows a broken image even though the upload itself succeeded and the file is really on disk under `storage/app/public/`. This bit a fresh checkout during Phase 3.5 testing — the step is easy to forget because nothing in `composer install` or `migrate` fails without it.

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
Faster, and what CI runs (paratest, added 2026-08-22 — 674 tests go from ~307s to ~106s on four cores):
```bash
php artisan test --parallel
```
Each process gets **its own database** (`decent_event_testing_test_1`, `_test_2`, …), so anything that shells out to a second process must read its connection off the parent rather than naming a database literally — `tests/Feature/Concurrency/*Test.php`'s `runConcurrently()` is the worked example, and hardcoding it there made the race pass while proving nothing. `--parallel` takes no path argument; narrow with `--filter` or drop back to a serial run.

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
⚠️ **`php artisan db:seed` is a local-only command.** `DatabaseSeeder` calls `DummyDataSeeder` (fake registrations, payments and tickets) *and* creates a hardcoded super admin whose password is literally `password`. On a live database, run the individual seeders you actually want — `RbacSeeder`, `EventSettingSeeder`, `TicketTypeSeeder`, `NotificationTemplateSeeder` — and make the first staff account with `admin:create-super-admin` below.

**A deploy that dies on `1050 Table 'permissions' already exists`:**
```bash
php artisan migrate:repair-permission-tables            # no-op unless the database is half-applied
php artisan migrate:repair-permission-tables --dry-run
```
Spatie's permission migration ends its `up()` with a cache flush, *after* its five `Schema::create` calls, so an unreachable cache store (`CACHE_STORE=redis` on a host with no redis) throws with every table already created. MySQL does not roll DDL back and Laravel records a migration only after `up()` returns, so the tables survive unrecorded and every later `migrate` dies re-creating `permissions`. The command records the migration rather than dropping anything — reaching the flush at all means every create succeeded — and **refuses when only some of the tables exist**, since declaring a partial schema applied moves the eventual failure further from its cause. The deploy runs it before `migrate`, and runs `migrate` itself with `CACHE_STORE=array` so the flush can no longer reach anything able to fail. See [docs/09](docs/09-hostinger-deployment.md).

**First Super Admin on a new environment:**
```bash
php artisan admin:create-super-admin                       # prompts for email and password
php artisan admin:create-super-admin --email=you@example.com --password=... --no-interaction
php artisan admin:create-super-admin --email=you@example.com --force   # reset the password, reactivate, clear the lockout
php artisan admin:create-super-admin --if-missing --generate-password --no-interaction   # the deploy path — see below
```
There is no HTTP path for this and deliberately isn't one: `routes/api/admin.php` exposes users index/show and `assign-role` only, so every staff account is created by somebody who already has one — which leaves the first one to the console. The command seeds `RbacSeeder` itself when the `Super Admin` role is missing, because on a `migrate --force`-only database it always is and `syncRoles()` would otherwise throw `RoleDoesNotExist`. Re-running it **without** `--force` never touches the password or the status: it only assigns the missing role, which is the repair path for an account that exists but can't do anything. It writes an `activity_logs` row with a null causer and `source: console:admin:create-super-admin` — shell access to the host is the only provenance such an account has.

**`--if-missing` is the deploy path**, run by both jobs in `.github/workflows/deploy.yml` after `config:cache` (which is the point at which config reflects the `.env` the release is running against). It reads `SUPER_ADMIN_EMAIL`/`SUPER_ADMIN_PASSWORD`/`SUPER_ADMIN_NAME`/`SUPER_ADMIN_PHONE` from the host's own `.env` via `config/admin.php`, so no credential passes through the workflow file, the SSH command line, or `ps`. Three behaviours, each deliberate:

- **Nothing configured → skip, exit 0 — unless `--generate-password`, which the deploy passes.** Without it, both halves must be set. An environment that provisions its admin by hand must not have one invented for it on every release, and this is also what makes deleting `SUPER_ADMIN_PASSWORD` from `.env` after first use the intended end state rather than a loose end — it is a plaintext credential for exactly as long as it sits there.
- **The account already exists → report, exit 0, write nothing.** Not even the missing-role repair a plain re-run performs. This step runs on *every* deploy, so assigning the role here would hand full system authority back to an account somebody deliberately demoted, once per release; the repair stays a decision an operator makes by running the command without `--if-missing`. A later password change, rename or suspension likewise survives every deploy.
- **Configured but malformed (unparseable email, password under 12 characters) → fail the deploy.** A blank setting is a decision; a malformed one is a mistake, and a green deploy that quietly created no administrator is the exact failure this command exists to end.

**`--generate-password` is what makes the deploy work with nothing configured**, and it is why the account gets created exactly once. With no `SUPER_ADMIN_PASSWORD` set it invents a 20-character password, creates the account at `config('admin.super_admin.email')` (which has a default — an address is a login identifier, not a secret, and this one is already committed in `DatabaseSeeder`), and **prints it once in the deploy log**, because only a bcrypt hash is stored and no code path can recover it afterwards. It is deliberately not masked: a masked one-time password is no password at all. The cost is that it sits in that log, so the output says so rather than leaving it to be inferred — sign in and change it. A configured `SUPER_ADMIN_PASSWORD` takes precedence and prints nothing.

Every later deploy takes the "already exists" branch, so **the account is created on exactly one deploy and never touched again** — a password the owner has since changed, a rename, or a suspension all survive being deployed over. `--if-missing` and `--force` are refused together, and it never prompts, so it cannot block a deploy on a question nothing can answer.

**PDF rendering** — ticket and directory PDFs render through headless Chrome (`config/pdf.php`), not a PHP library, because mpdf silently dropped Bengali conjuncts from the extractable text layer. Install Chromium (or Chrome) locally; `CHROME_BINARY` overrides the auto-detected path. `pdftotext` (poppler-utils) is needed only to run the PDF text-layer tests, which skip themselves without it.

**Media thumbnails** — new uploads derive one inline; this is only for images stored before thumbnails existed (safe to re-run, `--dry-run` reports without writing):
```bash
php artisan media:backfill-thumbnails
```

**SMS** (REVE Systems — see CLAUDE.md's SMS section for what is and isn't verified):
```bash
php artisan sms:test 01711223344            # one real send; prints the raw gateway response
php artisan sms:test 01711223344 --bangla   # Unicode probe — proves the 70-char segment rate end to end
php artisan sms:test --status=1373104       # what the gateway says happened to one message
php artisan sms:test --balance              # prepaid balance
php artisan sms:poll-dlr                    # settle sent messages from delivery receipts; scheduled every 5m
```
Unset `REVESMS_API_KEY` and the `sms` channel stays on `FakeSmsDriver` — a checkout with no REVE account still drains its outbox.

**Backup / restore** (Phase 9 — see CLAUDE.md's Phase 9 section for what this does and doesn't cover):
```bash
php artisan db:backup                            # dump + checksum + row-count manifest, scheduled nightly
php artisan db:restore --verify path/to/dump.sql.gz    # restore into a scratch DB, diff against the manifest, drop it — never touches the live DB
php artisan db:restore --force path/to/dump.sql.gz     # the real, destructive restore — --force is mandatory, there is no confirmation prompt
```

**Production image** (Phase 9 — not build-tested in this environment, see CLAUDE.md's Phase 9 section):
```bash
docker compose -f docker-compose.prod.yml build
docker compose -f docker-compose.prod.yml up
```

## Architecture

Full design docs live in `docs/` (`01`–`08`, start at `docs/README.md`). Read the relevant doc before making non-trivial changes to a subsystem — the ADRs in `docs/README.md` explain *why*, and getting them wrong breaks correctness at the gate on event day, not just a test. The load-bearing decisions:

- **Modular monolith.** Laravel organized into seven domain modules under `app/Domain/`: `Registration`, `Payment`, `Ticketing`, `Notification`, `CheckIn`, `Reporting`, `Content`, plus `Shared` for cross-cutting concerns (`User`, `EventSetting`, `MediaFile`, `ActivityLog`, `IdempotencyKey`, and support traits). Modules communicate through events and service interfaces — **never reach into another module's Eloquent models directly**. ⚠️ **This is the target, not the current state (D6):** most `Events/`/`Listeners/` directories are still empty and existing code violates it (`CreateRegistration` creates `Payment` directly; `VerifyManualPayment` calls `IssueTicket` directly). Write *new* cross-module code the right way — an event and a listener — rather than copying the existing violations. `Content` (added Phase 3.5, 2026-08-04) holds the line: it references CheckIn's event sessions only by a soft `event_session_code` string, never a foreign key or a model import.
- **Layering within a module:** HTTP (`app/Http/Controllers` — thin, no business logic) → FormRequests (validation + `authorize()`) → Actions/domain services in `app/Domain/*/Actions` and `*/Services` → Repositories/Eloquent → async Jobs for anything slow (gateway calls, PDF/QR rendering, SMS/email/WhatsApp). The queue boundary is deliberate: registration and payment must stay fast and transactional; notification delivery and asset generation are allowed to be slow or briefly broken.
- **Money is integer paisa, never float/decimal** (`BIGINT UNSIGNED`, 1 BDT = 100 paisa), with an explicit `currency` column. Never introduce a decimal money column.
- **Public-facing identifiers are ULIDs**; auto-increment BIGINT PKs stay internal only. Routes use `{model:ulid}` binding (see `routes/api/*.php`).
- **Tickets are immutable once issued.** Corrections are void + reissue (`replaces_ticket_id` chain), never edit/delete.
- **State transitions go through `HasStateMachine`** (`app/Domain/Shared/Support/HasStateMachine.php`): a model defines a `TRANSITIONS` constant (`['from' => ['to', ...]]`) and calls `transitionTo()`; illegal transitions throw `InvalidStateTransitionException`. Don't mutate a `status` column directly — use this instead. Permitted maps are specified in `docs/04-erd.md` §4.7.
- **QR tickets are Ed25519-signed**, not database-lookup tokens; scanner devices hold only the public key plus a signed revocation manifest, so admission decisions work fully offline. Admission counting uses an atomic conditional `UPDATE ... WHERE admitted_count + :n <= admits_total` — never SELECT-then-INSERT — to stay race-safe under concurrent gate scans. Signing is real (Phase 6, closed 2026-08-04): `App\Domain\Ticketing\Services\QrSigner` is the only code path that touches the private key (`config('services.qr_signing')`, sourced from `QR_SIGNING_PRIVATE_KEY`/`QR_SIGNING_KEY_ID`), `IssueTicket` signs every ticket for real, and `ProcessCheckIn` verifies the signature and payload expiry via `QrSigner`/`QrPayload` before a ticket ever reaches `AdmissionPolicy` — a manual override is the one path that legitimately skips this, since it has no real scanned payload. Multiple keys can be valid simultaneously (`QR_SIGNING_PUBLIC_KEYS` for retired keys), and `php artisan qr-signing:generate-key` provisions a new keypair, but the *rotation procedure itself* — publish → confirm every device has synced → only then start signing with the new key — is a staged, human-gated process (Super Admin, re-auth, notifies Event Managers per docs/06 §6.5) that isn't automated or rehearsed here.
- **RBAC:** `spatie/laravel-permission` under the `web-admin` guard, catalogue seeded from the versioned `config/rbac.php` (never created ad hoc — this is what keeps staging/production provably in sync). Code must check **permissions** (`payment.verify_manual`), never role names. Staff (`users`) and attendees (`attendees`) are separate identity domains/guards — do not conflate them. Volunteer (`scanner` guard) access is additionally scoped server-side by enrolled device, assigned gate, and the check-in time window — see `docs/02-rbac-permissions.md` §2.4 for the full authorization flow (permission check **and** model policy must both pass).
- **Routes** are split by audience under `routes/api/`: `public.php` (unauthenticated browse/register), `attendee.php` (attendee self-service, `auth:attendee`), `admin.php` (staff console, `auth:web-admin`), wired together in `routes/api/v1.php`; plus `routes/scanner.php` (volunteer devices) and `routes/webhooks.php` (payment gateway IPNs). `routes/api.php` just mounts `v1.php` under the `api/v1` prefix.
- **Payments:** four gateways (bKash, Nagad, Rocket, SSLCommerz) behind one adapter contract (`createIntent`, `verify`, `refund`, `parseWebhook`) under `app/Domain/Payment/Gateways/`. A browser return-callback is **never** trusted to mark a payment succeeded — only a server-to-server verify call or a signature-validated IPN can do that. `payments` (the money intent) and `payment_transactions` (every gateway interaction, append-only) are deliberately separate tables — don't collapse them. ⚠️ Today **every** gateway name resolves to `FakeGateway` (`PaymentGatewayResolver::forMethod()`); `SslCommerzClient` is the Phase 4A deliverable — see the sandbox section above. `PaymentGatewayResolver` is the *only* place that may branch on gateway name; domain code never does.
- **Notifications** go through a database outbox (`notifications`/`notification_events`) written in the same transaction as the triggering business event (via `Notification\Actions\QueueNotification`), then drained by `app/Jobs/SendNotificationJob` on the `notifications` Horizon lane via provider-agnostic channel drivers (`NotificationChannelResolver`). Don't call a notification provider directly from request-handling code — dispatch a domain event and add a `Notification\Listeners\Queue*Notification` instead, following the six existing examples. `email` (`MailDriver`) and `sms` (`SmsDriver` over `ReveSmsClient`, REVE Systems) are both real; `sms` falls back to `FakeSmsDriver` when no credentials are configured, and says so in `notifications.provider`. Delivery receipts come back through `Notification\Actions\RecordDeliveryReceipt` — the only writer of `sent → delivered|bounced` — fed by either `POST /webhooks/sms/dlr` or the `sms:poll-dlr` sweep. ⚠️ `whatsapp` still resolves to `FakeWhatsAppDriver` pending Meta template approval (see Phase 5 above).

Queue lanes (Horizon) are named by urgency — `payments` (<5s), `tickets` (<30s), `notifications` (<60s), `reports` (minutes) — keep jobs on the queue that matches their latency budget, since notification volume must never delay a payment webhook. `app/Jobs/SendNotificationJob` lives on the `notifications` lane (Phase 5); `app/Jobs/GenerateTicketAssetsJob` (Phase 6) is the first job on the `tickets` lane, rendering the QR PNG and PDF off the issuance transaction. `payments`/`reports` still have nothing dispatched to them. When you add one, put it on the lane matching its latency budget rather than the default.

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
- **List endpoints order through `App\Domain\Shared\Support\ListSort`, never a raw `orderBy($request->input(...))`.** `orderBy()` interpolates its column argument rather than binding it, so a request-supplied column name is a straight SQL injection; `ListSort` takes an allowlist of public field name => real column and the request only ever picks a key from it. It also appends the primary key as a final tiebreaker, because MySQL may answer tied `LIMIT/OFFSET` pages inconsistently — the same row on two pages, another on none. An unknown field or direction falls back to the default rather than 422ing. Default every admin list to newest first (`created_at desc`). Sortable columns must live on the table being queried; a value behind a relation is marked `enableSorting: false` in the SPA rather than given a join these lists cannot afford at 20,000 rows.
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
- **Errors normalise through `toApiError()`** into the API's `{ code, message, errors?, request_id }` envelope. Surface `errors` as field-level messages and `message` as the toast; a 403 should say which permission is missing. ⚠️ Most feature `api.ts` files still do `throw new Error(toApiError(e).message)`, which **discards `errors`** and makes that impossible — a 422 can then only ever appear as one vague banner. New write calls should `throw new ApiRequestError(e)` (`lib/api.ts`) instead, as `updateAttendee` does; it subclasses `Error`, so an existing `onError: (e: Error) => push(e.message)` keeps working. Render the field messages with `Field` (`components/ui.tsx`, which wires `aria-describedby` for you) and keep the toast a summary — the field says what is wrong, the toast says the save did not happen.
- **Server-side pagination, sorting, and filtering only.** The attendee table targets 20,000+ rows — client-side sorting of a full table will not survive real data. Use `lib/pagination.ts` (`PaginatedResponse`, `unwrap`) and `lib/sorting.ts` (`useTableSorting`, `SortParams`). A sortable column's TanStack **id must equal the API's `sort` field name**, since that id is what gets sent, and it must be declared with `accessorKey` (or an `accessorFn`) — `getCanSort()` returns false without one, so an `id`-only column renders a sortable-looking header that silently does nothing. `DataTable` disables sorting entirely unless the page passes `onSortingChange`, for the same reason.
- **Address resources by `ulid`, not `id`.** Some existing filters still pass numeric `ticket_type_id`, which leaks an internal primary key across the API boundary — don't extend that pattern; new filters take ULIDs.
- **Navigation and actions render from the permission set returned at login**, never from role names — same rule as the backend. A user must not see a control they cannot use.
- **Types are hand-written today and will drift.** The roadmap's Phase 3 exit criterion requires no drift between client and server validation. Until the client is generated from `public/docs/openapi.json`, treat `types.ts` as needing a manual check whenever you change an API Resource.
- **Run `npm run typecheck` before committing** — TypeScript is strict and CI does not currently catch SPA type errors.
- No pages are unbacked placeholders anymore: Check-in (D10), Notifications (Phase 5) and Content (Phase 3.5) all closed 2026-08-04 with real backends and full SPA screens. If you add a new module ahead of its backend, don't stub fake data — leave a `Placeholder` (`resources/js/features/misc/Placeholder.tsx`) and flag it, matching how these were handled while open.
- **Bilingual editing is one locale toggle per form, not a field-level one.** `features/cms/components/BilingualField.tsx` renders whichever half of a `field`/`field_bn` pair the toggle selects and carries the other through untouched; a blank Bangla value is a supported state that falls back to English server-side, so surface it (the field says so) rather than blocking the save.
