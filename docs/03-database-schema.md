# 03 — Database Schema

> Phase 1 deliverable. Complete table specification: purpose, fields, data types, indexes, and relationships.

**Engine:** InnoDB · **Charset:** `utf8mb4` · **Collation:** `utf8mb4_0900_ai_ci` · **Timestamps:** UTC

---

## 3.0 Conventions

These apply to every table and are not repeated in each specification.

| Convention | Rule |
|---|---|
| Primary key | `id BIGINT UNSIGNED AUTO_INCREMENT` — internal only, never exposed |
| Public identifier | `ulid CHAR(26)` with a unique index — used in all URLs, QR payloads, and API responses |
| Timestamps | `created_at`, `updated_at` — `TIMESTAMP NULL`, stored UTC |
| Soft delete | `deleted_at TIMESTAMP NULL` on tables where history matters |
| Money | `BIGINT UNSIGNED` in **paisa**, suffixed `_paisa`, alongside `currency CHAR(3) DEFAULT 'BDT'` |
| Phone numbers | `VARCHAR(20)` in E.164 (`+8801712345678`) — normalised on write |
| Enums | `VARCHAR(32)` + application-level enum, **not** MySQL `ENUM` (adding a value to a MySQL enum locks the table) |
| JSON columns | `JSON` type, used only for genuinely schemaless data (gateway payloads, log diffs) — never for queryable business fields |
| Foreign keys | Named `fk_{table}_{column}`, `ON DELETE RESTRICT` by default; `CASCADE` only where the child has no independent meaning |
| Actor references | `*_by_user_id` for staff actions, always nullable, always `ON DELETE SET NULL` so removing a user never destroys history |

---

## 3.1 Table inventory

| # | Table | Purpose | Est. rows |
|---|---|---|---|
| 1 | `users` | Staff accounts (Super Admin, Event Manager, Volunteer) | ~40 |
| 2 | `roles` / `permissions` / pivots | RBAC (spatie) | ~90 |
| 3 | `attendees` | Person identity — the human being | ~22,000 |
| 4 | `registrations` | An application/order to attend | ~20,000 |
| 5 | `registration_guests` | Family and couple members on a registration | ~18,000 |
| 6 | `ticket_types` | Sellable ticket categories and pricing | ~7 |
| 7 | `tickets` | An issued, admission-bearing ticket | ~12,000 |
| 8 | `qr_codes` | Signed QR payload + rendered asset per ticket | ~13,000 |
| 9 | `payments` | The money intent for a registration | ~20,000 |
| 10 | `payment_transactions` | Every gateway interaction (append-only) | ~90,000 |
| 11 | `refunds` | Refund records | ~400 |
| 12 | `notification_templates` | Versioned message templates per channel | ~40 |
| 13 | `notifications` | Transactional outbox | ~260,000 |
| 14 | `notification_events` | Delivery receipts / status transitions | ~600,000 |
| 15 | `event_sessions` | Named admission sessions | 1–4 |
| 16 | `gates` | Physical entry points | ~8 |
| 17 | `volunteer_profiles` | Volunteer-specific data for a user | ~30 |
| 18 | `volunteer_gate_assignments` | Which volunteer works which gate | ~60 |
| 19 | `check_in_devices` | Enrolled scanner devices | ~30 |
| 20 | `check_ins` | Every scan attempt, admitted or rejected | ~20,000 |
| 21 | `media_files` | Uploaded files (photos, payment proofs, PDFs) | ~35,000 |
| 22 | `event_settings` | Key-value system configuration | ~60 |
| 23 | `activity_logs` | Audit trail | ~2,000,000 |
| 24 | `report_exports` | Async export job records | ~2,000 |
| 25 | `idempotency_keys` | Replay protection for unsafe operations | ~120,000 |

---

## 3.2 `users`

**Purpose.** Staff accounts only — Super Admins, Event Managers, and Volunteers. Attendees are **not** stored here ([02 §2.1](02-rbac-permissions.md#21-design-principles)).

| Field | Type | Null | Notes |
|---|---|:--:|---|
| `id` | BIGINT UNSIGNED PK | | |
| `ulid` | CHAR(26) | | Unique |
| `name` | VARCHAR(150) | | |
| `email` | VARCHAR(190) | | Unique |
| `email_verified_at` | TIMESTAMP | ✓ | |
| `phone` | VARCHAR(20) | ✓ | E.164 |
| `password` | VARCHAR(255) | | bcrypt cost 12 |
| `two_factor_secret` | TEXT | ✓ | Encrypted at rest |
| `two_factor_recovery_codes` | TEXT | ✓ | Encrypted at rest |
| `two_factor_confirmed_at` | TIMESTAMP | ✓ | Mandatory for admin roles |
| `status` | VARCHAR(32) | | `active`, `suspended`, `deactivated` |
| `last_login_at` | TIMESTAMP | ✓ | |
| `last_login_ip` | VARBINARY(16) | ✓ | Packed, IPv6-capable |
| `failed_login_attempts` | TINYINT UNSIGNED | | Default 0 |
| `locked_until` | TIMESTAMP | ✓ | Set on repeated failures |
| `created_by_user_id` | BIGINT UNSIGNED FK | ✓ | → `users.id` |
| `created_at` / `updated_at` / `deleted_at` | TIMESTAMP | ✓ | |

**Indexes:** `uk_users_ulid (ulid)`, `uk_users_email (email)`, `idx_users_status (status)`

**Relationships:** has many `activity_logs`, `check_ins` (as scanner), `volunteer_profiles` (one), `report_exports`; belongs to many `roles`.

---

## 3.3 RBAC tables

Standard `spatie/laravel-permission` layout, guard `web-admin`:

`roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions`.

Roles and permissions are **seeded from versioned config**, never created at runtime, so staging and production are provably identical. Full catalogue in [02 — RBAC](02-rbac-permissions.md#23-permission-catalogue).

---

## 3.4 `attendees`

**Purpose.** The person. Separated from `registrations` because identity outlives any single application, and because deduplication on mobile number ([ADR-08](README.md#adr-08--attendee-identity-is-deduplicated-on-normalized-mobile-number)) is what keeps the SSC batch reports honest when the same alumnus registers twice.

| Field | Type | Null | Notes |
|---|---|:--:|---|
| `id` | BIGINT UNSIGNED PK | | |
| `ulid` | CHAR(26) | | Unique |
| `full_name` | VARCHAR(150) | | Bangla-capable |
| `full_name_bn` | VARCHAR(150) | ✓ | Optional Bangla rendering for badges |
| `mobile` | VARCHAR(20) | | **Unique** — identity key, E.164 |
| `whatsapp_number` | VARCHAR(20) | ✓ | Often differs for overseas alumni |
| `email` | VARCHAR(190) | ✓ | Indexed, not unique — shared family emails are common |
| `gender` | VARCHAR(16) | ✓ | `male`, `female`, `other`, `prefer_not_to_say` |
| `date_of_birth` | DATE | ✓ | |
| `occupation` | VARCHAR(120) | ✓ | |
| `designation` | VARCHAR(120) | ✓ | |
| `organization` | VARCHAR(150) | ✓ | |
| `participant_type` | VARCHAR(32) | | `current_student`, `former_student`, `teacher`, `staff`, `guest`, `sponsor` |
| `ssc_batch_year` | SMALLINT UNSIGNED | ✓ | 1971 … current year; required when `participant_type` ∈ {`current_student`, `former_student`} |
| `current_class` | VARCHAR(32) | ✓ | Current students only |
| `profile_photo_media_id` | BIGINT UNSIGNED FK | ✓ | → `media_files.id` |
| `tshirt_required` | BOOLEAN | | Default false |
| `tshirt_size` | VARCHAR(8) | ✓ | `XS`…`XXXL`; required when `tshirt_required` |
| `address_district` | VARCHAR(80) | ✓ | For regional reporting |
| `country` | CHAR(2) | | Default `BD` |
| `blood_group` | VARCHAR(8) | ✓ | Practical for a 10,000-person event |
| `emergency_contact_name` | VARCHAR(120) | ✓ | |
| `emergency_contact_phone` | VARCHAR(20) | ✓ | |
| `is_verified` | BOOLEAN | | Alumni identity confirmed by an Event Manager |
| `verified_by_user_id` | BIGINT UNSIGNED FK | ✓ | → `users.id` |
| `verified_at` | TIMESTAMP | ✓ | |
| `merged_into_attendee_id` | BIGINT UNSIGNED FK | ✓ | → `attendees.id`, set when deduplicated |
| `auth_token_hash` | VARCHAR(255) | ✓ | Magic-link / OTP login |
| `auth_token_expires_at` | TIMESTAMP | ✓ | |
| `notes` | TEXT | ✓ | Internal staff notes |
| `created_at` / `updated_at` / `deleted_at` | TIMESTAMP | ✓ | |

**Indexes**
- `uk_attendees_ulid (ulid)`, `uk_attendees_mobile (mobile)`
- `idx_attendees_email (email)`
- `idx_attendees_batch_type (ssc_batch_year, participant_type)` — the primary reporting axis
- `idx_attendees_participant_type (participant_type)`
- `idx_attendees_tshirt (tshirt_required, tshirt_size)` — vendor production report
- `idx_attendees_name (full_name)` — gate lookup by name
- `idx_attendees_merged (merged_into_attendee_id)`

**Relationships:** has many `registrations`; belongs to `media_files` (photo); self-referencing merge target.

> **Design note.** `email` is intentionally **not** unique. A household of three alumni siblings will use one email. Making it unique here generates support tickets on day one.

---

## 3.5 `registrations`

**Purpose.** One application to attend, made by one lead attendee, covering one or more people. This is the order — the unit that gets priced, paid for, and turned into tickets.

| Field | Type | Null | Notes |
|---|---|:--:|---|
| `id` | BIGINT UNSIGNED PK | | |
| `ulid` | CHAR(26) | | Unique — the public reference |
| `registration_number` | VARCHAR(32) | | Unique, human-readable: `REG-100Y-000148` |
| `attendee_id` | BIGINT UNSIGNED FK | | → `attendees.id` — the lead registrant |
| `ticket_type_id` | BIGINT UNSIGNED FK | | → `ticket_types.id` |
| `event_session_id` | BIGINT UNSIGNED FK | ✓ | → `event_sessions.id` |
| `participation_type` | VARCHAR(16) | | `single`, `couple`, `family` |
| `adults_count` | TINYINT UNSIGNED | | Default 1, includes the lead |
| `children_count` | TINYINT UNSIGNED | | Default 0 |
| `total_persons` | TINYINT UNSIGNED | | Generated: `adults_count + children_count` |
| `status` | VARCHAR(32) | | See state machine below |
| `subtotal_paisa` | BIGINT UNSIGNED | | Before discount |
| `discount_paisa` | BIGINT UNSIGNED | | Default 0 |
| `total_paisa` | BIGINT UNSIGNED | | Amount owed |
| `currency` | CHAR(3) | | Default `BDT` |
| `discount_code` | VARCHAR(32) | ✓ | Batch-committee or early-bird codes |
| `comments` | TEXT | ✓ | Attendee's free text |
| `special_notes` | TEXT | ✓ | Accessibility, dietary, seating |
| `source` | VARCHAR(32) | | `web`, `admin`, `import`, `kiosk` |
| `submitted_at` | TIMESTAMP | ✓ | |
| `confirmed_at` | TIMESTAMP | ✓ | Payment settled |
| `cancelled_at` | TIMESTAMP | ✓ | |
| `cancellation_reason` | VARCHAR(255) | ✓ | |
| `locked_at` | TIMESTAMP | ✓ | Post-cutoff, attendee edits disabled |
| `created_by_user_id` | BIGINT UNSIGNED FK | ✓ | → `users.id`, set for admin-created |
| `ip_address` | VARBINARY(16) | ✓ | |
| `user_agent` | VARCHAR(255) | ✓ | |
| `created_at` / `updated_at` / `deleted_at` | TIMESTAMP | ✓ | |

**Status values:** `draft` → `pending_payment` → `paid` → `confirmed` → `cancelled` / `refunded` / `expired`

**Indexes**
- `uk_registrations_ulid (ulid)`, `uk_registrations_number (registration_number)`
- `idx_reg_attendee (attendee_id)`
- `idx_reg_status_created (status, created_at)` — dashboard and funnel queries
- `idx_reg_ticket_type (ticket_type_id)`
- `idx_reg_participation (participation_type)`
- `idx_reg_confirmed (confirmed_at)` — revenue-over-time reporting

**Relationships:** belongs to `attendees`, `ticket_types`, `event_sessions`; has many `registration_guests`, `tickets`, `payments`, `notifications`.

---

## 3.6 `registration_guests`

**Purpose.** The other people on a family or couple registration. Each is a person with their own name and T-shirt size — [ADR-10](README.md#adr-10--t-shirt-size-belongs-to-the-person-not-the-registration). Without this table the T-shirt vendor order is a guess.

| Field | Type | Null | Notes |
|---|---|:--:|---|
| `id` | BIGINT UNSIGNED PK | | |
| `ulid` | CHAR(26) | | Unique |
| `registration_id` | BIGINT UNSIGNED FK | | → `registrations.id`, `ON DELETE CASCADE` |
| `full_name` | VARCHAR(150) | | |
| `relation` | VARCHAR(32) | ✓ | `spouse`, `son`, `daughter`, `parent`, `guest` |
| `age_group` | VARCHAR(16) | | `adult`, `child` |
| `age` | TINYINT UNSIGNED | ✓ | Drives child pricing and catering |
| `gender` | VARCHAR(16) | ✓ | |
| `tshirt_required` | BOOLEAN | | Default false |
| `tshirt_size` | VARCHAR(8) | ✓ | |
| `is_admitted` | BOOLEAN | | Default false — set at gate |
| `admitted_at` | TIMESTAMP | ✓ | |
| `sort_order` | TINYINT UNSIGNED | | Default 0 |
| `created_at` / `updated_at` | TIMESTAMP | ✓ | |

**Indexes:** `uk_guests_ulid (ulid)`, `idx_guests_registration (registration_id)`, `idx_guests_tshirt (tshirt_required, tshirt_size)`, `idx_guests_age_group (age_group)`

**Relationships:** belongs to `registrations`. `ON DELETE CASCADE` is correct here — a guest has no meaning without their registration.

---

## 3.7 `ticket_types`

**Purpose.** Sellable categories with pricing, capacity, eligibility, and admission rules. Prices are configuration, not hardcoded.

| Field | Type | Null | Notes |
|---|---|:--:|---|
| `id` | BIGINT UNSIGNED PK | | |
| `ulid` | CHAR(26) | | Unique |
| `code` | VARCHAR(16) | | Unique: `ALM`, `STU`, `TCH`, `STF`, `VIP`, `FAM`, `SPN` — used in ticket numbers |
| `name` | VARCHAR(100) | | e.g. "Alumni Ticket" |
| `name_bn` | VARCHAR(100) | ✓ | |
| `description` | TEXT | ✓ | |
| `base_price_paisa` | BIGINT UNSIGNED | | |
| `additional_adult_price_paisa` | BIGINT UNSIGNED | | Default 0 |
| `additional_child_price_paisa` | BIGINT UNSIGNED | | Default 0 |
| `currency` | CHAR(3) | | Default `BDT` |
| `base_admits` | TINYINT UNSIGNED | | 1 single, 2 couple, 4 family |
| `max_admits` | TINYINT UNSIGNED | | Upper bound per ticket |
| `allowed_participant_types` | JSON | | e.g. `["former_student"]` — eligibility gate |
| `quantity_total` | INT UNSIGNED | ✓ | NULL = unlimited |
| `quantity_sold` | INT UNSIGNED | | Default 0, incremented atomically |
| `quantity_reserved` | INT UNSIGNED | | Default 0, held during payment |
| `requires_approval` | BOOLEAN | | Default false — true for VIP/Sponsor |
| `includes_tshirt` | BOOLEAN | | Default false |
| `includes_meal` | BOOLEAN | | Default true |
| `sale_starts_at` | TIMESTAMP | ✓ | |
| `sale_ends_at` | TIMESTAMP | ✓ | |
| `is_active` | BOOLEAN | | Default true |
| `is_public` | BOOLEAN | | Default true — false hides VIP/Sponsor from public listing |
| `badge_color` | VARCHAR(9) | ✓ | Hex, for gate badges and scanner UI |
| `sort_order` | TINYINT UNSIGNED | | |
| `created_at` / `updated_at` / `deleted_at` | TIMESTAMP | ✓ | |

**Indexes:** `uk_ticket_types_ulid (ulid)`, `uk_ticket_types_code (code)`, `idx_ticket_types_active (is_active, is_public, sort_order)`

**Relationships:** has many `registrations`, `tickets`.

> **Capacity control.** Sold and reserved counts are updated with the same conditional-UPDATE pattern used for admissions:
> `UPDATE ticket_types SET quantity_sold = quantity_sold + 1 WHERE id = ? AND (quantity_total IS NULL OR quantity_sold + quantity_reserved < quantity_total)`.
> Zero affected rows means sold out. This is race-free without table locks.

---

## 3.8 `tickets`

**Purpose.** An issued admission instrument. Immutable once created ([ADR-09](README.md#adr-09--tickets-are-immutable-once-issued)) — corrections happen by void and reissue.

| Field | Type | Null | Notes |
|---|---|:--:|---|
| `id` | BIGINT UNSIGNED PK | | |
| `ulid` | CHAR(26) | | Unique — **this is what the QR payload carries** |
| `ticket_number` | VARCHAR(40) | | Unique: `DEC100-ALM-1998-04217` |
| `registration_id` | BIGINT UNSIGNED FK | | → `registrations.id` |
| `attendee_id` | BIGINT UNSIGNED FK | | → `attendees.id` — denormalised for gate lookup |
| `ticket_type_id` | BIGINT UNSIGNED FK | | → `ticket_types.id` |
| `event_session_id` | BIGINT UNSIGNED FK | ✓ | → `event_sessions.id` |
| `status` | VARCHAR(32) | | See state machine below |
| `admits_total` | TINYINT UNSIGNED | | Persons this ticket admits |
| `admitted_count` | TINYINT UNSIGNED | | Default 0 — **the concurrency-safe counter** |
| `price_paid_paisa` | BIGINT UNSIGNED | | Snapshot at issuance; type price may change later |
| `currency` | CHAR(3) | | Default `BDT` |
| `holder_name` | VARCHAR(150) | | Snapshot — a ticket must print correctly even if the profile is later edited |
| `holder_batch_year` | SMALLINT UNSIGNED | ✓ | Snapshot |
| `holder_type_label` | VARCHAR(64) | | Snapshot, e.g. "Alumni · SSC 1998" |
| `issued_at` | TIMESTAMP | ✓ | |
| `issued_by_user_id` | BIGINT UNSIGNED FK | ✓ | → `users.id`, NULL when system-issued |
| `voided_at` | TIMESTAMP | ✓ | |
| `voided_by_user_id` | BIGINT UNSIGNED FK | ✓ | → `users.id` |
| `void_reason` | VARCHAR(255) | ✓ | Mandatory when voiding |
| `replaces_ticket_id` | BIGINT UNSIGNED FK | ✓ | → `tickets.id` — the reissue chain |
| `first_admitted_at` | TIMESTAMP | ✓ | |
| `last_admitted_at` | TIMESTAMP | ✓ | |
| `pdf_media_id` | BIGINT UNSIGNED FK | ✓ | → `media_files.id` |
| `manifest_version` | INT UNSIGNED | | Bumped on any status change; drives scanner delta sync |
| `created_at` / `updated_at` | TIMESTAMP | ✓ | |

**Status values:** `issued` → `active` → `partially_admitted` → `fully_admitted`; or → `voided` / `refunded` / `expired`

**Indexes**
- `uk_tickets_ulid (ulid)`, `uk_tickets_number (ticket_number)`
- `idx_tickets_registration (registration_id)`
- `idx_tickets_attendee (attendee_id)`
- `idx_tickets_status (status)`
- `idx_tickets_type_status (ticket_type_id, status)` — sales and attendance reporting
- `idx_tickets_manifest (manifest_version)` — scanner delta sync, the hottest index on event day
- `idx_tickets_session_status (event_session_id, status)`

**Relationships:** belongs to `registrations`, `attendees`, `ticket_types`, `event_sessions`; has one `qr_codes`; has many `check_ins`; self-referencing reissue chain.

> **Why `admitted_count` lives here and not in a join.** The gate needs one atomic write to decide admission. Deriving remaining admissions by counting `check_ins` rows requires a read-then-write, which races across twenty simultaneous scanners. See [ADR-04](README.md#adr-04--duplicate-entry-is-prevented-by-an-atomic-conditional-update).

---

## 3.9 `qr_codes`

**Purpose.** The signed payload and rendered image for a ticket. Separated from `tickets` because signing keys rotate and QR codes are reissued independently of the ticket's identity.

| Field | Type | Null | Notes |
|---|---|:--:|---|
| `id` | BIGINT UNSIGNED PK | | |
| `ulid` | CHAR(26) | | Unique |
| `ticket_id` | BIGINT UNSIGNED FK | | → `tickets.id` |
| `payload_version` | TINYINT UNSIGNED | | Default 1 — payload format version |
| `payload` | VARCHAR(255) | | The exact encoded string in the QR image |
| `payload_hash` | CHAR(64) | | SHA-256 of payload, unique — fast lookup, no scan of a long string |
| `signature` | VARCHAR(128) | | Ed25519 signature, base64url |
| `signing_key_id` | VARCHAR(32) | | Which key signed it — enables rotation |
| `issued_at` | TIMESTAMP | | |
| `expires_at` | TIMESTAMP | ✓ | Typically event end + 24h |
| `is_active` | BOOLEAN | | Default true — false after reissue |
| `revoked_at` | TIMESTAMP | ✓ | |
| `revoke_reason` | VARCHAR(255) | ✓ | |
| `image_media_id` | BIGINT UNSIGNED FK | ✓ | → `media_files.id` — rendered PNG |
| `scan_count` | INT UNSIGNED | | Default 0 — includes rejected scans |
| `created_at` / `updated_at` | TIMESTAMP | ✓ | |

**Indexes:** `uk_qr_ulid (ulid)`, `uk_qr_payload_hash (payload_hash)`, `idx_qr_ticket_active (ticket_id, is_active)`, `idx_qr_signing_key (signing_key_id)`

**Relationships:** belongs to `tickets`, `media_files`.

**Payload format** — see [06 §6.5 QR code security](06-security-architecture.md#65-qr-code-security):

```
DTM1.<ticket_ulid>.<admits_total>.<exp_unix>.<key_id>.<ed25519_sig_b64url>
```

---

## 3.10 `payments`

**Purpose.** The money intent for a registration. One row per attempt-to-pay lifecycle; the business reasons about this table.

| Field | Type | Null | Notes |
|---|---|:--:|---|
| `id` | BIGINT UNSIGNED PK | | |
| `ulid` | CHAR(26) | | Unique |
| `payment_number` | VARCHAR(32) | | Unique: `PAY-100Y-000148` |
| `registration_id` | BIGINT UNSIGNED FK | | → `registrations.id` |
| `attendee_id` | BIGINT UNSIGNED FK | | → `attendees.id` — denormalised for finance queries |
| `method` | VARCHAR(32) | | `bkash`, `nagad`, `rocket`, `sslcommerz`, `manual_bkash`, `manual_nagad`, `manual_rocket`, `bank_transfer`, `cash` |
| `channel` | VARCHAR(16) | | `online`, `manual` |
| `status` | VARCHAR(32) | | See state machine below |
| `amount_due_paisa` | BIGINT UNSIGNED | | |
| `amount_paid_paisa` | BIGINT UNSIGNED | | Default 0 |
| `fee_paisa` | BIGINT UNSIGNED | | Gateway fee, default 0 |
| `net_paisa` | BIGINT UNSIGNED | | Settled amount, default 0 |
| `refunded_paisa` | BIGINT UNSIGNED | | Default 0 |
| `currency` | CHAR(3) | | Default `BDT` |
| `gateway_reference` | VARCHAR(120) | ✓ | Gateway's payment/session ID |
| `gateway_transaction_id` | VARCHAR(120) | ✓ | Indexed — the TrxID printed on receipts |
| `payer_msisdn` | VARCHAR(20) | ✓ | Sending mobile wallet number |
| `manual_trx_id` | VARCHAR(64) | ✓ | Attendee-entered TrxID for manual transfers |
| `manual_proof_media_id` | BIGINT UNSIGNED FK | ✓ | → `media_files.id` — screenshot |
| `manual_sender_note` | VARCHAR(255) | ✓ | |
| `verified_by_user_id` | BIGINT UNSIGNED FK | ✓ | → `users.id` |
| `verified_at` | TIMESTAMP | ✓ | |
| `verification_note` | VARCHAR(255) | ✓ | |
| `rejection_reason` | VARCHAR(255) | ✓ | |
| `initiated_at` | TIMESTAMP | ✓ | |
| `paid_at` | TIMESTAMP | ✓ | Authoritative settlement time |
| `expires_at` | TIMESTAMP | ✓ | Intent expiry, releases reserved capacity |
| `failed_at` | TIMESTAMP | ✓ | |
| `idempotency_key` | VARCHAR(64) | ✓ | Unique — blocks double-charge on double-click |
| `reconciled_at` | TIMESTAMP | ✓ | Matched against settlement report |
| `reconciliation_status` | VARCHAR(32) | ✓ | `matched`, `missing_locally`, `missing_at_gateway`, `amount_mismatch` |
| `created_at` / `updated_at` | TIMESTAMP | ✓ | |

**Status values:** `pending` → `initiated` → `processing` → `succeeded`; or → `failed` / `expired` / `cancelled` / `refunded` / `partially_refunded`; manual path adds `awaiting_verification` → `succeeded` / `rejected`

**Indexes**
- `uk_payments_ulid (ulid)`, `uk_payments_number (payment_number)`, `uk_payments_idem (idempotency_key)`
- `idx_payments_registration (registration_id)`
- `idx_payments_status_created (status, created_at)`
- `idx_payments_gateway_txn (gateway_transaction_id)` — support lookup by receipt TrxID
- `idx_payments_method_status (method, status)` — revenue by method
- `idx_payments_manual_trx (manual_trx_id)`
- `idx_payments_paid_at (paid_at)` — revenue over time
- `idx_payments_reconciliation (reconciliation_status, reconciled_at)`

**Relationships:** belongs to `registrations`, `attendees`; has many `payment_transactions`, `refunds`.

---

## 3.11 `payment_transactions`

**Purpose.** Append-only log of every interaction with a gateway. Never updated, never deleted. This is what reconciliation, chargeback disputes, and post-mortems read.

| Field | Type | Null | Notes |
|---|---|:--:|---|
| `id` | BIGINT UNSIGNED PK | | |
| `ulid` | CHAR(26) | | Unique |
| `payment_id` | BIGINT UNSIGNED FK | | → `payments.id` |
| `type` | VARCHAR(32) | | `initiate`, `redirect`, `callback`, `ipn`, `verify`, `query`, `refund`, `reversal` |
| `direction` | VARCHAR(16) | | `outbound`, `inbound` |
| `gateway` | VARCHAR(32) | | `bkash`, `nagad`, `rocket`, `sslcommerz` |
| `status` | VARCHAR(32) | | `success`, `failed`, `pending`, `error` |
| `amount_paisa` | BIGINT UNSIGNED | ✓ | |
| `currency` | CHAR(3) | ✓ | |
| `gateway_reference` | VARCHAR(120) | ✓ | |
| `gateway_transaction_id` | VARCHAR(120) | ✓ | |
| `gateway_status_code` | VARCHAR(32) | ✓ | |
| `gateway_message` | VARCHAR(255) | ✓ | |
| `request_payload` | JSON | ✓ | **Secrets redacted before write** |
| `response_payload` | JSON | ✓ | **Secrets redacted before write** |
| `signature_valid` | BOOLEAN | ✓ | Webhook signature verification result |
| `ip_address` | VARBINARY(16) | ✓ | Source IP for inbound webhooks |
| `latency_ms` | INT UNSIGNED | ✓ | Gateway response time — feeds the ops dashboard |
| `idempotency_key` | VARCHAR(64) | ✓ | Unique when present — blocks duplicate webhook processing |
| `created_at` | TIMESTAMP | | No `updated_at` — rows are immutable |

**Indexes:** `uk_ptx_ulid (ulid)`, `uk_ptx_idem (idempotency_key)`, `idx_ptx_payment_created (payment_id, created_at)`, `idx_ptx_gateway_txn (gateway_transaction_id)`, `idx_ptx_type_status (type, status)`, `idx_ptx_created (created_at)`

**Relationships:** belongs to `payments`.

> **Redaction is a write-time responsibility.** Auth tokens, `X-APP-Key` headers, and card-adjacent fields are stripped by the adapter before persistence. A leaked database backup must not contain live gateway credentials.

---

## 3.12 `refunds`

**Purpose.** Refund records, tracked separately because a refund has its own approval, lifecycle, and gateway interaction.

| Field | Type | Null | Notes |
|---|---|:--:|---|
| `id` | BIGINT UNSIGNED PK | | |
| `ulid` | CHAR(26) | | Unique |
| `refund_number` | VARCHAR(32) | | Unique: `RFD-100Y-00042` |
| `payment_id` | BIGINT UNSIGNED FK | | → `payments.id` |
| `registration_id` | BIGINT UNSIGNED FK | | → `registrations.id` |
| `amount_paisa` | BIGINT UNSIGNED | | Supports partial refunds |
| `currency` | CHAR(3) | | Default `BDT` |
| `reason` | VARCHAR(255) | | Mandatory |
| `type` | VARCHAR(16) | | `full`, `partial` |
| `method` | VARCHAR(32) | | `gateway`, `manual_transfer`, `cash` |
| `status` | VARCHAR(32) | | `requested` → `approved` → `processing` → `completed`; or `rejected` / `failed` |
| `requested_by_user_id` | BIGINT UNSIGNED FK | ✓ | → `users.id` |
| `approved_by_user_id` | BIGINT UNSIGNED FK | ✓ | → `users.id` |
| `approved_at` | TIMESTAMP | ✓ | |
| `processed_at` | TIMESTAMP | ✓ | |
| `gateway_refund_id` | VARCHAR(120) | ✓ | |
| `recipient_msisdn` | VARCHAR(20) | ✓ | For manual transfer-back |
| `voided_ticket_ids` | JSON | ✓ | Tickets voided as part of this refund |
| `created_at` / `updated_at` | TIMESTAMP | ✓ | |

**Indexes:** `uk_refunds_ulid (ulid)`, `uk_refunds_number (refund_number)`, `idx_refunds_payment (payment_id)`, `idx_refunds_status (status)`, `idx_refunds_created (created_at)`

**Relationships:** belongs to `payments`, `registrations`.

---

## 3.13 `notification_templates`

**Purpose.** Versioned message bodies per channel and locale. Templates are data, so the Super Admin can fix a typo in a reminder without a deployment.

| Field | Type | Null | Notes |
|---|---|:--:|---|
| `id` | BIGINT UNSIGNED PK | | |
| `key` | VARCHAR(64) | | e.g. `payment.succeeded` |
| `channel` | VARCHAR(16) | | `email`, `sms`, `whatsapp` |
| `locale` | VARCHAR(8) | | `en`, `bn` |
| `version` | SMALLINT UNSIGNED | | Default 1 |
| `subject` | VARCHAR(190) | ✓ | Email only |
| `body` | TEXT | | Blade/Handlebars-style placeholders |
| `whatsapp_template_name` | VARCHAR(100) | ✓ | Meta-approved template identifier |
| `whatsapp_template_status` | VARCHAR(32) | ✓ | `pending`, `approved`, `rejected` |
| `variables` | JSON | ✓ | Declared placeholders, validated at render |
| `estimated_segments` | TINYINT UNSIGNED | ✓ | SMS cost planning |
| `is_active` | BOOLEAN | | Default true |
| `created_at` / `updated_at` | TIMESTAMP | ✓ | |

**Indexes:** `uk_tpl_key_channel_locale_version (key, channel, locale, version)`, `idx_tpl_active (is_active)`

---

## 3.14 `notifications`

**Purpose.** The transactional outbox ([ADR-07](README.md#adr-07--notifications-go-through-a-database-outbox-not-direct-sends)). Written inside the business transaction, drained by workers, and the durable answer to "did we actually send it?"

| Field | Type | Null | Notes |
|---|---|:--:|---|
| `id` | BIGINT UNSIGNED PK | | |
| `ulid` | CHAR(26) | | Unique |
| `notifiable_type` | VARCHAR(64) | | `registration`, `payment`, `ticket`, `attendee` |
| `notifiable_id` | BIGINT UNSIGNED | | Polymorphic target |
| `attendee_id` | BIGINT UNSIGNED FK | ✓ | → `attendees.id` — indexed for the attendee's message history |
| `template_key` | VARCHAR(64) | | |
| `channel` | VARCHAR(16) | | `email`, `sms`, `whatsapp` |
| `locale` | VARCHAR(8) | | Default `en` |
| `recipient` | VARCHAR(190) | | Email address or E.164 number |
| `subject` | VARCHAR(190) | ✓ | |
| `body_rendered` | TEXT | ✓ | What was actually sent — retained for disputes |
| `payload` | JSON | ✓ | Template variables |
| `attachment_media_id` | BIGINT UNSIGNED FK | ✓ | → `media_files.id` — the ticket PDF |
| `status` | VARCHAR(32) | | `queued` → `sending` → `sent` → `delivered` → `read`; or `failed` / `bounced` / `cancelled` |
| `priority` | TINYINT UNSIGNED | | 1 highest … 5 lowest |
| `attempts` | TINYINT UNSIGNED | | Default 0 |
| `max_attempts` | TINYINT UNSIGNED | | Default 5 |
| `scheduled_for` | TIMESTAMP | ✓ | Reminder scheduling |
| `sent_at` | TIMESTAMP | ✓ | |
| `delivered_at` | TIMESTAMP | ✓ | |
| `failed_at` | TIMESTAMP | ✓ | |
| `last_error` | VARCHAR(500) | ✓ | |
| `provider` | VARCHAR(32) | ✓ | Which vendor handled it |
| `provider_message_id` | VARCHAR(120) | ✓ | For receipt correlation |
| `segment_count` | TINYINT UNSIGNED | ✓ | SMS segments actually billed |
| `cost_paisa` | INT UNSIGNED | ✓ | Real send cost |
| `dedupe_key` | VARCHAR(190) | ✓ | Unique — prevents duplicate one-shot sends |
| `created_at` / `updated_at` | TIMESTAMP | ✓ | |

**Indexes**
- `uk_notif_ulid (ulid)`, `uk_notif_dedupe (dedupe_key)`
- `idx_notif_status_scheduled (status, scheduled_for)` — the worker's claim query
- `idx_notif_notifiable (notifiable_type, notifiable_id)`
- `idx_notif_attendee (attendee_id)`
- `idx_notif_channel_status (channel, status)`
- `idx_notif_provider_msg (provider_message_id)` — receipt webhooks look up by this
- `idx_notif_created (created_at)`

**Relationships:** belongs to `attendees`, `media_files`; has many `notification_events`.

---

## 3.15 `notification_events`

**Purpose.** Delivery receipts and status transitions from providers — SMS DLRs, WhatsApp status webhooks, email bounces and opens.

| Field | Type | Null | Notes |
|---|---|:--:|---|
| `id` | BIGINT UNSIGNED PK | | |
| `notification_id` | BIGINT UNSIGNED FK | | → `notifications.id`, `ON DELETE CASCADE` |
| `event` | VARCHAR(32) | | `queued`, `sent`, `delivered`, `read`, `failed`, `bounced`, `complained` |
| `provider_status` | VARCHAR(64) | ✓ | Raw provider code |
| `detail` | VARCHAR(500) | ✓ | |
| `raw_payload` | JSON | ✓ | |
| `occurred_at` | TIMESTAMP | | Provider timestamp, not receipt time |
| `created_at` | TIMESTAMP | | Immutable |

**Indexes:** `idx_ne_notification (notification_id, occurred_at)`, `idx_ne_event (event)`

---

## 3.16 `event_sessions`

**Purpose.** Named admission sessions. If the event is a single continuous day this holds one row and costs nothing; if it becomes a gala dinner plus a cultural night, per-session admission works without a schema change. See [open question 1](README.md#open-questions-for-the-client).

| Field | Type | Null | Notes |
|---|---|:--:|---|
| `id` | BIGINT UNSIGNED PK | | |
| `ulid` | CHAR(26) | | Unique |
| `code` | VARCHAR(32) | | Unique, e.g. `MAIN`, `GALA` |
| `name` | VARCHAR(120) | | |
| `venue` | VARCHAR(190) | ✓ | |
| `starts_at` / `ends_at` | TIMESTAMP | | |
| `checkin_opens_at` / `checkin_closes_at` | TIMESTAMP | | Gate window |
| `capacity` | INT UNSIGNED | ✓ | |
| `admitted_count` | INT UNSIGNED | | Default 0 |
| `is_active` | BOOLEAN | | Default true |
| `created_at` / `updated_at` | TIMESTAMP | ✓ | |

**Indexes:** `uk_sessions_ulid (ulid)`, `uk_sessions_code (code)`, `idx_sessions_active (is_active, starts_at)`

---

## 3.17 `gates`

**Purpose.** Physical entry points. Needed so attendance can be reported per gate and so volunteers can be scoped to a location.

| Field | Type | Null | Notes |
|---|---|:--:|---|
| `id` | BIGINT UNSIGNED PK | | |
| `ulid` | CHAR(26) | | Unique |
| `code` | VARCHAR(16) | | Unique, e.g. `GATE-A` |
| `name` | VARCHAR(100) | | e.g. "Main Gate — Alumni" |
| `event_session_id` | BIGINT UNSIGNED FK | ✓ | → `event_sessions.id` |
| `allowed_ticket_type_ids` | JSON | ✓ | NULL = all; enables a VIP-only gate |
| `location_note` | VARCHAR(190) | ✓ | |
| `admitted_count` | INT UNSIGNED | | Default 0 — live dashboard counter |
| `is_active` | BOOLEAN | | Default true |
| `created_at` / `updated_at` | TIMESTAMP | ✓ | |

**Indexes:** `uk_gates_ulid (ulid)`, `uk_gates_code (code)`, `idx_gates_session (event_session_id)`

---

## 3.18 `volunteer_profiles`

**Purpose.** Volunteer-specific data attached to a `users` row. Kept separate so the `users` table is not polluted with columns meaningless to admins.

| Field | Type | Null | Notes |
|---|---|:--:|---|
| `id` | BIGINT UNSIGNED PK | | |
| `ulid` | CHAR(26) | | Unique |
| `user_id` | BIGINT UNSIGNED FK | | → `users.id`, unique |
| `volunteer_code` | VARCHAR(16) | | Unique, printed on their badge: `VOL-014` |
| `pin_hash` | VARCHAR(255) | | 6-digit device PIN, bcrypt |
| `pin_set_at` | TIMESTAMP | ✓ | |
| `team` | VARCHAR(64) | ✓ | `entry`, `registration_desk`, `vip` |
| `shift_starts_at` / `shift_ends_at` | TIMESTAMP | ✓ | |
| `is_active` | BOOLEAN | | Default true |
| `revoked_at` | TIMESTAMP | ✓ | |
| `revoked_by_user_id` | BIGINT UNSIGNED FK | ✓ | → `users.id` |
| `total_scans` | INT UNSIGNED | | Default 0 — per-volunteer throughput |
| `created_at` / `updated_at` | TIMESTAMP | ✓ | |

**Indexes:** `uk_vp_ulid (ulid)`, `uk_vp_user (user_id)`, `uk_vp_code (volunteer_code)`, `idx_vp_active (is_active)`

---

## 3.19 `volunteer_gate_assignments`

**Purpose.** Which volunteer may scan at which gate. Enforced server-side on every scan sync.

| Field | Type | Null | Notes |
|---|---|:--:|---|
| `id` | BIGINT UNSIGNED PK | | |
| `volunteer_profile_id` | BIGINT UNSIGNED FK | | → `volunteer_profiles.id`, `ON DELETE CASCADE` |
| `gate_id` | BIGINT UNSIGNED FK | | → `gates.id`, `ON DELETE CASCADE` |
| `event_session_id` | BIGINT UNSIGNED FK | ✓ | → `event_sessions.id` |
| `assigned_by_user_id` | BIGINT UNSIGNED FK | ✓ | → `users.id` |
| `created_at` / `updated_at` | TIMESTAMP | ✓ | |

**Indexes:** `uk_vga (volunteer_profile_id, gate_id, event_session_id)`, `idx_vga_gate (gate_id)`

---

## 3.20 `check_in_devices`

**Purpose.** Enrolled scanner devices. Enrolment binds a token to hardware so a leaked token cannot be used from an arbitrary machine.

| Field | Type | Null | Notes |
|---|---|:--:|---|
| `id` | BIGINT UNSIGNED PK | | |
| `ulid` | CHAR(26) | | Unique |
| `device_code` | VARCHAR(16) | | Unique, physically labelled: `DEV-07` |
| `device_name` | VARCHAR(100) | | |
| `device_fingerprint` | VARCHAR(190) | | Unique — hashed hardware identifier |
| `platform` | VARCHAR(16) | | `android`, `ios` |
| `app_version` | VARCHAR(20) | ✓ | |
| `os_version` | VARCHAR(32) | ✓ | |
| `assigned_volunteer_profile_id` | BIGINT UNSIGNED FK | ✓ | → `volunteer_profiles.id` |
| `sanctum_token_id` | BIGINT UNSIGNED | ✓ | → `personal_access_tokens.id` |
| `status` | VARCHAR(32) | | `enrolled`, `active`, `suspended`, `revoked` |
| `enrolled_by_user_id` | BIGINT UNSIGNED FK | ✓ | → `users.id` |
| `enrolled_at` | TIMESTAMP | ✓ | |
| `revoked_at` | TIMESTAMP | ✓ | |
| `manifest_version` | INT UNSIGNED | | Last manifest version this device holds |
| `last_sync_at` | TIMESTAMP | ✓ | |
| `last_seen_at` | TIMESTAMP | ✓ | |
| `pending_scan_count` | INT UNSIGNED | | Default 0 — unsynced scans, the ops dashboard's key signal |
| `battery_level` | TINYINT UNSIGNED | ✓ | Reported at sync; a dead scanner is an outage |
| `total_scans` | INT UNSIGNED | | Default 0 |
| `created_at` / `updated_at` | TIMESTAMP | ✓ | |

**Indexes:** `uk_dev_ulid (ulid)`, `uk_dev_code (device_code)`, `uk_dev_fingerprint (device_fingerprint)`, `idx_dev_status (status)`, `idx_dev_last_sync (last_sync_at)`

---

## 3.21 `check_ins`

**Purpose.** Every scan attempt — admitted **and** rejected ([ADR-05](README.md#adr-05--every-scan-is-recorded-including-rejections)). This is both the attendance record and the gate dispute log.

| Field | Type | Null | Notes |
|---|---|:--:|---|
| `id` | BIGINT UNSIGNED PK | | |
| `ulid` | CHAR(26) | | Unique |
| `client_scan_uuid` | CHAR(36) | | **Unique** — device-generated, makes offline sync idempotent |
| `ticket_id` | BIGINT UNSIGNED FK | ✓ | → `tickets.id`; NULL when the QR was unparseable |
| `registration_id` | BIGINT UNSIGNED FK | ✓ | → `registrations.id` — denormalised |
| `attendee_id` | BIGINT UNSIGNED FK | ✓ | → `attendees.id` — denormalised |
| `event_session_id` | BIGINT UNSIGNED FK | ✓ | → `event_sessions.id` |
| `gate_id` | BIGINT UNSIGNED FK | ✓ | → `gates.id` |
| `device_id` | BIGINT UNSIGNED FK | ✓ | → `check_in_devices.id` |
| `scanned_by_user_id` | BIGINT UNSIGNED FK | ✓ | → `users.id` — the volunteer |
| `result` | VARCHAR(32) | | See values below |
| `rejection_detail` | VARCHAR(255) | ✓ | |
| `admitted_count` | TINYINT UNSIGNED | | Persons admitted by *this* scan; 0 on rejection |
| `admitted_guest_ids` | JSON | ✓ | Which family members entered |
| `raw_payload` | VARCHAR(255) | ✓ | Exact scanned string — essential for debugging bad QR prints |
| `signature_valid` | BOOLEAN | ✓ | Result of local Ed25519 verification |
| `scan_mode` | VARCHAR(16) | | `online`, `offline` |
| `is_manual_override` | BOOLEAN | | Default false |
| `override_by_user_id` | BIGINT UNSIGNED FK | ✓ | → `users.id` — Event Manager only |
| `override_reason` | VARCHAR(255) | ✓ | Mandatory when overriding |
| `conflict_flag` | BOOLEAN | | Default false — set when offline scans collide |
| `conflict_resolved_at` | TIMESTAMP | ✓ | |
| `conflict_resolved_by_user_id` | BIGINT UNSIGNED FK | ✓ | → `users.id` |
| `scanned_at` | TIMESTAMP(3) | | **Device clock**, millisecond precision — decides first-scan-wins |
| `synced_at` | TIMESTAMP | ✓ | Server receipt time |
| `device_clock_skew_ms` | INT | ✓ | Device vs server drift, recorded at sync |
| `latitude` / `longitude` | DECIMAL(10,7) | ✓ | Optional, if gate geofencing is enabled |
| `created_at` | TIMESTAMP | | Immutable — no `updated_at` |

**Result values:** `admitted`, `duplicate`, `revoked`, `unpaid`, `invalid_signature`, `invalid_format`, `expired`, `over_capacity`, `wrong_gate`, `wrong_session`, `manual_override`

**Indexes**
- `uk_ci_ulid (ulid)`, `uk_ci_client_uuid (client_scan_uuid)` — the idempotency guarantee
- `idx_ci_ticket_scanned (ticket_id, scanned_at)` — duplicate investigation
- `idx_ci_result (result)`
- `idx_ci_gate_scanned (gate_id, scanned_at)` — per-gate throughput charts
- `idx_ci_device (device_id, scanned_at)`
- `idx_ci_scanned_by (scanned_by_user_id)`
- `idx_ci_conflict (conflict_flag, conflict_resolved_at)`
- `idx_ci_session_result (event_session_id, result)` — live attendance dashboard

> **Why `scanned_at` uses the device clock.** In an offline collision the server has no way to order two scans by its own receipt time — they may arrive hours later in arbitrary order. Devices are NTP-synced at enrolment and their drift is measured at every sync, so the recorded skew makes the ordering auditable rather than merely assumed.

---

## 3.22 `media_files`

**Purpose.** One table for every uploaded and generated file — profile photos, payment screenshots, ticket PDFs, QR images. Centralising this makes storage cleanup, virus scanning, and signed-URL generation a single concern.

| Field | Type | Null | Notes |
|---|---|:--:|---|
| `id` | BIGINT UNSIGNED PK | | |
| `ulid` | CHAR(26) | | Unique |
| `collection` | VARCHAR(32) | | `profile_photo`, `payment_proof`, `ticket_pdf`, `qr_image`, `report_export` |
| `disk` | VARCHAR(32) | | `s3`, `local` |
| `path` | VARCHAR(255) | | Storage key |
| `original_name` | VARCHAR(190) | ✓ | Sanitised on write |
| `mime_type` | VARCHAR(100) | | Detected server-side, never trusted from the client |
| `extension` | VARCHAR(12) | | |
| `size_bytes` | INT UNSIGNED | | |
| `checksum_sha256` | CHAR(64) | | Deduplication and integrity |
| `width` / `height` | SMALLINT UNSIGNED | ✓ | Images only |
| `is_public` | BOOLEAN | | Default false — everything private by default |
| `scan_status` | VARCHAR(16) | | `pending`, `clean`, `infected`, `skipped` |
| `scanned_at` | TIMESTAMP | ✓ | |
| `uploaded_by_type` | VARCHAR(32) | ✓ | `attendee`, `user`, `system` |
| `uploaded_by_id` | BIGINT UNSIGNED | ✓ | |
| `expires_at` | TIMESTAMP | ✓ | Auto-cleanup for exports |
| `created_at` / `updated_at` / `deleted_at` | TIMESTAMP | ✓ | |

**Indexes:** `uk_media_ulid (ulid)`, `idx_media_collection (collection)`, `idx_media_checksum (checksum_sha256)`, `idx_media_scan (scan_status)`, `idx_media_expires (expires_at)`, `idx_media_uploader (uploaded_by_type, uploaded_by_id)`

---

## 3.23 `event_settings`

**Purpose.** Typed key-value configuration so the Super Admin can change dates, cutoffs, and toggles without a deployment.

| Field | Type | Null | Notes |
|---|---|:--:|---|
| `id` | BIGINT UNSIGNED PK | | |
| `key` | VARCHAR(64) | | Unique |
| `group` | VARCHAR(32) | | `event`, `registration`, `payment`, `notification`, `checkin`, `branding` |
| `value` | TEXT | ✓ | |
| `type` | VARCHAR(16) | | `string`, `int`, `bool`, `json`, `datetime`, `money` |
| `is_encrypted` | BOOLEAN | | Default false — true for gateway credentials |
| `is_public` | BOOLEAN | | Default false — true values are exposed to the public frontend |
| `label` | VARCHAR(120) | | Admin UI label |
| `description` | VARCHAR(255) | ✓ | |
| `updated_by_user_id` | BIGINT UNSIGNED FK | ✓ | → `users.id` |
| `created_at` / `updated_at` | TIMESTAMP | ✓ | |

**Indexes:** `uk_settings_key (key)`, `idx_settings_group (group)`, `idx_settings_public (is_public)`

**Representative keys**

| Key | Type | Purpose |
|---|---|---|
| `event.name`, `event.date`, `event.venue` | string / datetime | Display and templates |
| `registration.opens_at`, `registration.closes_at` | datetime | Sales window |
| `registration.edit_cutoff_at` | datetime | Locks attendee edits — drives the T-shirt order freeze |
| `registration.max_family_size` | int | Validation bound |
| `payment.intent_ttl_minutes` | int | Releases reserved capacity |
| `payment.manual_verification_enabled` | bool | Kill switch |
| `payment.refund_cutoff_at` | datetime | Refund policy boundary |
| `checkin.window_start`, `checkin.window_end` | datetime | Bounds volunteer token validity |
| `checkin.allow_manual_override` | bool | Gate-day kill switch |
| `qr.active_signing_key_id` | string | Current signing key |
| `notification.sms_enabled`, `notification.whatsapp_enabled` | bool | Per-channel kill switches — essential when a vendor fails mid-event |

---

## 3.24 `activity_logs`

**Purpose.** The audit trail. Every privileged, financial, or destructive action, with actor, target, before/after diff, and network context.

| Field | Type | Null | Notes |
|---|---|:--:|---|
| `id` | BIGINT UNSIGNED PK | | |
| `ulid` | CHAR(26) | | Unique |
| `log_name` | VARCHAR(32) | | `auth`, `registration`, `payment`, `ticket`, `checkin`, `system`, `security` |
| `event` | VARCHAR(64) | | `created`, `updated`, `deleted`, `login_failed`, `refund_issued`, `permission_denied`, … |
| `description` | VARCHAR(255) | | Human-readable summary |
| `causer_type` | VARCHAR(32) | ✓ | `user`, `attendee`, `system`, `gateway` |
| `causer_id` | BIGINT UNSIGNED | ✓ | |
| `impersonator_user_id` | BIGINT UNSIGNED FK | ✓ | → `users.id` — set during impersonation |
| `subject_type` | VARCHAR(64) | ✓ | Affected model |
| `subject_id` | BIGINT UNSIGNED | ✓ | |
| `properties` | JSON | ✓ | `{ "old": {...}, "new": {...} }` |
| `ip_address` | VARBINARY(16) | ✓ | |
| `user_agent` | VARCHAR(255) | ✓ | |
| `request_id` | CHAR(26) | ✓ | Correlates with application logs |
| `severity` | VARCHAR(16) | | `info`, `notice`, `warning`, `critical` |
| `created_at` | TIMESTAMP | | Immutable, no `updated_at` |

**Indexes**
- `uk_al_ulid (ulid)`
- `idx_al_subject (subject_type, subject_id, created_at)` — "what happened to this registration?"
- `idx_al_causer (causer_type, causer_id, created_at)` — "what did this admin do?"
- `idx_al_name_event (log_name, event)`
- `idx_al_created (created_at)` — archival sweep
- `idx_al_severity (severity, created_at)` — security review

**Retention.** Rows older than 90 days are archived to compressed cold storage monthly and purged from the hot table. `security`-category rows are retained for two years regardless.

---

## 3.25 `report_exports`

**Purpose.** Async export jobs. A 20,000-row Excel export must not run inside an HTTP request.

| Field | Type | Null | Notes |
|---|---|:--:|---|
| `id` | BIGINT UNSIGNED PK | | |
| `ulid` | CHAR(26) | | Unique |
| `report_key` | VARCHAR(64) | | `registrations_by_batch`, `revenue_summary`, `tshirt_production`, `attendance_by_gate`, … |
| `format` | VARCHAR(8) | | `pdf`, `xlsx`, `csv` |
| `filters` | JSON | ✓ | Applied filters, retained for reproducibility |
| `status` | VARCHAR(16) | | `queued`, `processing`, `completed`, `failed` |
| `row_count` | INT UNSIGNED | ✓ | |
| `media_id` | BIGINT UNSIGNED FK | ✓ | → `media_files.id` |
| `requested_by_user_id` | BIGINT UNSIGNED FK | | → `users.id` |
| `started_at` / `completed_at` | TIMESTAMP | ✓ | |
| `error_message` | VARCHAR(500) | ✓ | |
| `expires_at` | TIMESTAMP | ✓ | Download link expiry — default 7 days |
| `created_at` / `updated_at` | TIMESTAMP | ✓ | |

**Indexes:** `uk_re_ulid (ulid)`, `idx_re_status (status)`, `idx_re_requester (requested_by_user_id, created_at)`, `idx_re_expires (expires_at)`

> Exports contain personal data of thousands of people. Download links are signed, short-lived, single-actor, and every download is logged to `activity_logs`.

---

## 3.26 `idempotency_keys`

**Purpose.** Generic replay protection for unsafe operations, so a double-tapped "Pay" button or a retried webhook cannot produce two effects.

| Field | Type | Null | Notes |
|---|---|:--:|---|
| `id` | BIGINT UNSIGNED PK | | |
| `key` | VARCHAR(64) | | Unique — client-supplied |
| `scope` | VARCHAR(64) | | `payment.initiate`, `checkin.sync`, `webhook.bkash`, … |
| `request_hash` | CHAR(64) | | SHA-256 of the body; a same-key/different-body request is rejected |
| `response_status` | SMALLINT UNSIGNED | ✓ | Cached response |
| `response_body` | JSON | ✓ | Replayed verbatim on retry |
| `locked_at` | TIMESTAMP | ✓ | In-flight marker |
| `completed_at` | TIMESTAMP | ✓ | |
| `expires_at` | TIMESTAMP | | Typically +24h |
| `created_at` | TIMESTAMP | | |

**Indexes:** `uk_idem_key (key)`, `idx_idem_scope (scope)`, `idx_idem_expires (expires_at)`

---

## 3.27 Supporting Laravel tables

Standard framework tables, listed for completeness: `personal_access_tokens` (Sanctum), `jobs`, `job_batches`, `failed_jobs`, `cache`, `cache_locks`, `sessions`, `password_reset_tokens`, `migrations`.

`failed_jobs` is monitored with an alert threshold — on event day a growing failed-job count is the earliest signal that something is wrong.

---

## 3.28 Denormalisation register

Every denormalised column is a deliberate trade of write complexity for read speed. They are listed here so Phase 2 knows exactly what must be kept consistent.

| Column | Source of truth | Kept in sync by | Why |
|---|---|---|---|
| `tickets.admitted_count` | `check_ins` | Atomic conditional UPDATE at admission | Race-free gate decision in one write |
| `tickets.attendee_id` | `registrations.attendee_id` | Set at issuance, immutable | Gate lookup without a join |
| `tickets.holder_name`, `holder_batch_year`, `holder_type_label` | `attendees` | Snapshot at issuance, never updated | A printed ticket must not change if the profile is edited |
| `ticket_types.quantity_sold` | `tickets` | Atomic conditional UPDATE | Race-free capacity control |
| `gates.admitted_count`, `event_sessions.admitted_count` | `check_ins` | Incremented on admission | Live dashboard without aggregating 20k rows |
| `check_ins.registration_id`, `attendee_id` | `tickets` | Set at sync | Attendance reports without a three-table join |
| `payments.attendee_id` | `registrations.attendee_id` | Set at creation | Finance queries without a join |
| `volunteer_profiles.total_scans`, `check_in_devices.total_scans` | `check_ins` | Incremented at sync | Per-volunteer and per-device throughput |

A nightly consistency job recomputes each of these from its source of truth and reports drift. It runs in report-only mode during the event and in repair mode afterwards.

---

**Next:** [04 — Entity Relationship Diagram](04-erd.md)
