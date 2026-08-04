# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Current Phase Status

**Phase 2 — Backend API Development (Week 2 of 6 weeks) — D1–D4 closed 2026-08-04, sign-off still pending frontend-lead OpenAPI review**
**Phase 3.5 — CMS: closed 2026-08-04. Backend foundation (schema + public read API) and admin half (CRUD with revision capture/restore, media upload, SPA screens, ISR revalidation hook) both landed — see [§Phase 3.5 below](#-phase-35-cms--closed-2026-08-04).**
**Phase 5 — Email/SMS/WhatsApp: buildable-now slice landed 2026-08-04 (outbox, dispatcher, real email, admin dashboard). Real SMS/WhatsApp drivers and DLR webhooks stay deferred — no vendor is chosen and Meta hasn't approved templates. See [§Phase 5 below](#-phase-5-emailsmswhatsapp--buildable-now-slice-closed-2026-08-04).**
**Phase 4A — SSLCommerz Sandbox: buildable-now slice landed 2026-08-04 (real `SslCommerzClient`, expiry sweeper closing D5, nightly reconciliation, refund-to-gateway wiring). Not yet smoke-tested against a live sandbox transaction — no `SSLCOMMERZ_STORE_PASSWORD` has been provisioned in this environment. See [§Phase 4A below](#-phase-4a-sslcommerz-sandbox--buildable-now-slice-closed-2026-08-04).**
**Phase 6 — QR & PDF Tickets: buildable-now slice landed 2026-08-04 (real Ed25519 `QrSigner`, signature+expiry verification in `ProcessCheckIn`, race-safe `TicketNumberGenerator`, QR PNG + bilingual A5 PDF generation on the `tickets` lane, signed-URL media serving, manifest delta sync + published keys, key-rotation tooling). Physical print/scan testing and a live device-rotation rehearsal are explicitly out of scope for this slice — see [§Phase 6 below](#-phase-6-qr--pdf-tickets--buildable-now-slice-closed-2026-08-04).**
**Phase 7 — Mobile Verification App: the React Native scanner is a separate repo (`decent-event-scanner`, sibling to this one — see "Three frontends, two repos" below), so nothing here changed except one small backend addition: `POST /scanner/v1/enrol` now also returns the volunteer's assigned gates. Core offline scan loop (enrolment, manifest delta sync, local Ed25519 verification, local admission policy, offline scan queue with batched idempotent upload) landed there 2026-08-04. Manual lookup, override-request routing, crash reporting, and all physical-device testing are deferred — see that repo's README.**

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
- **D9** *Partially closed 2026-08-04.* The CMS media upload endpoint now exists (`POST /admin/content/media`, Phase 3.5) with magic-byte validation and image re-encoding — but it is **public-disk, CMS-collections only** and deliberately refuses anything else, so **manual payment proof is still unusable**: that needs a private-disk upload path with short-TTL signed URLs, which is Phase 4A's to build (reuse `UploadContentMedia`'s validation, not its storage settings). Still open: no observers exist; no `config/cors.php` for the Next.js origin; `config/sanctum.php` has `'expiration' => null`, so staff tokens never expire.
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

### 💳 Payments — development environment

Development and all Phase 4A work run against the **SSLCommerz sandbox** (<https://sandbox-gw.sslcommerz.com/docs>, <https://developer.sslcommerz.com/doc/v4/>). Sandbox credentials are self-service — no merchant onboarding required — so the full money path is buildable now. `SslCommerzClient` (Phase 4A, closed 2026-08-04) implements it; see [§Phase 4A above](#-phase-4a-sslcommerz-sandbox--buildable-now-slice-closed-2026-08-04) for what's still unverified against a live call.

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
| `app/Domain/{Module}/` | The seven domain modules — Actions, Models, Policies, Services, Events, Listeners |
| `app/Http/Controllers/Api/{Public,Attendee,Admin,Scanner}/` | Thin controllers, split by audience |
| `app/Http/Controllers/Webhooks/` | Gateway IPN endpoints (one per gateway) |
| `routes/api/` | `public.php`, `attendee.php`, `admin.php`, wired by `v1.php`; plus `scanner.php`, `webhooks.php` at `routes/` |
| `resources/js/` | **The admin dashboard** — Vite + React 19 SPA (see below) |
| `docs/01`–`08` | Design docs. Start at `docs/README.md`; the ADRs explain *why* |

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
