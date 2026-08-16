# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Current Phase Status

**Phase 2 — Backend API Development (Week 2 of 6 weeks) — D1–D4 closed 2026-08-04, sign-off still pending frontend-lead OpenAPI review**
**Phase 3.5 — CMS: closed 2026-08-04. Backend foundation (schema + public read API) and admin half (CRUD with revision capture/restore, media upload, SPA screens, ISR revalidation hook) both landed — see [§Phase 3.5 below](#-phase-35-cms--closed-2026-08-04).**
**Phase 5 — Email/SMS/WhatsApp: buildable-now slice landed 2026-08-04 (outbox, dispatcher, real email, admin dashboard). Real SMS/WhatsApp drivers and DLR webhooks stay deferred — no vendor is chosen and Meta hasn't approved templates. See [§Phase 5 below](#-phase-5-emailsmswhatsapp--buildable-now-slice-closed-2026-08-04).**
**Phase 4A — SSLCommerz Sandbox: buildable-now slice landed 2026-08-04 (real `SslCommerzClient`, expiry sweeper closing D5, nightly reconciliation, refund-to-gateway wiring). Not yet smoke-tested against a live sandbox transaction — no `SSLCOMMERZ_STORE_PASSWORD` has been provisioned in this environment. See [§Phase 4A below](#-phase-4a-sslcommerz-sandbox--buildable-now-slice-closed-2026-08-04).**
**Phase 6 — QR & PDF Tickets: buildable-now slice landed 2026-08-04 (real Ed25519 `QrSigner`, signature+expiry verification in `ProcessCheckIn`, race-safe `TicketNumberGenerator`, QR PNG + bilingual A5 PDF generation on the `tickets` lane, signed-URL media serving, manifest delta sync + published keys, key-rotation tooling). Physical print/scan testing and a live device-rotation rehearsal are explicitly out of scope for this slice — see [§Phase 6 below](#-phase-6-qr--pdf-tickets--buildable-now-slice-closed-2026-08-04).**
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

Phase 6 landed its buildable-now slice 2026-08-04 (see below) — `placeholder_sig`, unverified signatures, missing PDF rendering, and the O(n) ticket-number counter are all fixed now, not deferred. What's still genuinely deferred from Phase 6: physical print/scan testing (needs a real printer and devices, docs/08 Phase 6 notes), and the live device-rotation rehearsal ("wait for confirmed device sync" step of key rotation, docs/06 §6.5) — the crypto/tooling for rotation exists (`qr-signing:generate-key`), but nothing here can prove a fleet of scanner devices actually re-synced before a new key starts signing.

Phase 4A landed the expiry sweeper, the reconciliation job, and a real `SslCommerzClient` (see below) — `bkash`/`nagad`/`rocket` still resolve to `FakeGateway` pending their merchant applications (Phase 4B).

Vendor-blocked pieces of Phase 5 (engineering is done; only a vendor pick or Meta approval is missing — see [§Phase 5](#-phase-5-emailsmswhatsapp--buildable-now-slice-closed-2026-08-04)): real `SmsDriver`/`WhatsAppDriver` (no Bangladesh SMS vendor named, no approved WhatsApp templates — both unchecked in External Dependencies below); DLR/delivery-webhook endpoints (the payload/signature shape is vendor-specific and unknown without one).

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
- Real `MailDriver` (Phase 5) and real `SslCommerzClient` (Phase 4A) plus fake drivers for the vendor-blocked pieces: `FakeGateway` (still resolved for `bkash`/`nagad`/`rocket`), `FakeSmsDriver`, `FakeWhatsAppDriver`
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
- **A real `/up` health check.** Laravel's built-in `health: '/up'` route (`bootstrap/app.php`) only ever proved the app booted; `App\Listeners\CheckApplicationHealth` (registered on the framework's own `DiagnosingHealth` event in `AppServiceProvider::boot()`) now checks the primary database, Redis (the connection Horizon and `config('cache.default')` both resolve to), and the default filesystem disk actually being writable, running all three even if the first fails so one hit surfaces every broken dependency rather than one per retry. This is what docs/07 §7.3's load balancer and uptime/synthetic monitoring are meant to poll. `HealthCheckTest` proves both the healthy 200 path and a real unreachable-database 500.
- **Backup + verified-restore tooling, proven against real MySQL — the provable half of "encrypted backups with verified restore."** `db:backup` (`mysqldump --single-transaction`, credentials passed via a `chmod 600` `--defaults-extra-file` temp file so they never appear in `ps aux`, never `MYSQL_PWD`) writes a gzip dump plus a `.meta.json` sidecar recording a per-table row count at backup time. `db:restore --verify` restores the dump into a disposable scratch database (`{db}_verify_<random>`), diffs its table row counts against exactly that manifest — not against whatever the live database happens to contain when the restore runs, which could be a materially different day — then drops the scratch database; the live database is never opened for writing in this mode. A real restore (no `--verify`) is gated behind `--force` specifically so it can never be a flag-order slip during an incident — there is no confirmation prompt to click through by muscle memory. `db:backup` is scheduled nightly (`routes/console.php`). **What this does not close:** the dump is gzip-compressed, not encrypted — encryption-at-rest and offsite replication need a real object store and a key-management decision this environment doesn't have, so ship the gzip through a storage provider's server-side encryption (or `gpg --encrypt` it) before it leaves the box. 3 tests in `DatabaseBackupRestoreTest`, using `DatabaseMigrations` rather than `RefreshDatabase` for the same reason `PurchaseConcurrencyTest` does (docs/08 Phase 8 section): `mysqldump`/`mysql` are real separate OS processes with their own MySQL connections, so they cannot see rows created inside `RefreshDatabase`'s uncommitted wrapping transaction.
- **A provider-agnostic production Dockerfile + `docker-compose.prod.yml` — NOT build- or run-tested.** This development environment has no Docker daemon at all (`which docker` finds nothing), so `docker build` has never actually been run against this image; every extension choice (sodium, gd, bcmath, zip, intl, pdo_mysql, redis via PECL) matches what this checkout's own `php -m` reports loaded rather than being guessed, but the build itself needs a first real run — the same category of gap as `SslCommerzClient` shipping unverified against a live sandbox call, and for the same reason: nothing here can provision the missing piece. Multi-stage (`composer:2` → `node:22-alpine` → `php:8.3-fpm-bookworm`); nginx and php-fpm are bundled into one image via `supervisord` rather than split across two containers sharing a volume — nginx's `fastcgi_pass` hands PHP-FPM a bare filesystem path, so the two processes must see identical files at identical paths, trivial in one container and a well-known footgun across two synchronized only by a volume something has to remember to populate. `docker-compose.prod.yml` is a reference topology (`app`, `horizon`, `scheduler` as separate containers — matching docs/07 §7.3's "workers dedicated, not co-located with app instances" — plus single-box `mysql`/`redis`, which is not what docs/07 §7.3's sizing assumes: a managed database with a replica and a hot standby), meant to be translated into whatever orchestrator a hosting decision lands on, not a deploy target itself.
- **Release-image CI pipeline** (`.github/workflows/deploy-image.yml`): on push to `main`, gates on the full migration set applying cleanly to a fresh database (`migrate --pretend` then `migrate --force` against an ephemeral MySQL service, mirroring `backend-ci.yml`'s already-proven service-container pattern), then builds the image, boot-checks it (`php artisan --version` inside the built container — proves vendor autoloading and PHP extensions don't fail outright, deliberately short of a full DB/Redis smoke test, which is a second unverified surface this slice didn't take on), and publishes to `ghcr.io` — chosen specifically because it needs no new secret beyond the `GITHUB_TOKEN` every workflow already gets, so this doesn't block on provisioning anything either. This is genuinely this Dockerfile's first real build: GitHub Actions runners have a real Docker daemon, this dev sandbox does not. **Deliberately stops at "publish a tagged image."** There is no `deploy` job that reaches a live host and cuts traffic over — same category as an unpicked SMS vendor (see External Dependencies below): building one now would mean guessing at a hosting provider's deploy API.
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
- [ ] SMS vendor contract and sender ID
- [ ] Domain registration and email SPF/DKIM/DMARC setup
- [ ] Hosting provider decision (added 2026-08-04, Phase 9) — docs/07 §7.3 specs sizing and topology, not a vendor. Blocks everything in Phase 9 past "publish a release image": production infrastructure, CDN/WAF, TLS, the live `deploy` step, on-call, event-day operations.

---

## Repository layout

A single Laravel application at the repo root, which serves **both** the API and the admin dashboard SPA. `.github/workflows/backend-ci.yml` runs lint/static-analysis/tests against the repo root; `.github/workflows/deploy-image.yml` (Phase 9) builds and publishes the production image on push to `main`.

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

**Media thumbnails** — new uploads derive one inline; this is only for images stored before thumbnails existed (safe to re-run, `--dry-run` reports without writing):
```bash
php artisan media:backfill-thumbnails
```

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
- **Notifications** go through a database outbox (`notifications`/`notification_events`) written in the same transaction as the triggering business event (via `Notification\Actions\QueueNotification`), then drained by `app/Jobs/SendNotificationJob` on the `notifications` Horizon lane via provider-agnostic channel drivers (`NotificationChannelResolver`). Don't call a notification provider directly from request-handling code — dispatch a domain event and add a `Notification\Listeners\Queue*Notification` instead, following the six existing examples. ⚠️ `email` is real (`MailDriver`); `sms`/`whatsapp` still resolve to `Fake*Driver` pending a vendor pick and Meta template approval (see Phase 5 above).

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
- No pages are unbacked placeholders anymore: Check-in (D10), Notifications (Phase 5) and Content (Phase 3.5) all closed 2026-08-04 with real backends and full SPA screens. If you add a new module ahead of its backend, don't stub fake data — leave a `Placeholder` (`resources/js/features/misc/Placeholder.tsx`) and flag it, matching how these were handled while open.
- **Bilingual editing is one locale toggle per form, not a field-level one.** `features/cms/components/BilingualField.tsx` renders whichever half of a `field`/`field_bn` pair the toggle selects and carries the other through untouched; a blank Bangla value is a supported state that falls back to English server-side, so surface it (the field says so) rather than blocking the save.
