# 04 — Entity Relationship Diagram

> Phase 1 deliverable. Full ERD, domain-focused sub-diagrams, cardinality rationale, and lifecycle state machines.

Field types below are shown in Mermaid's simplified notation. Exact MySQL types are in [03 — Database Schema](03-database-schema.md).

---

## 4.1 Full entity relationship diagram

```mermaid
erDiagram
    USERS ||--o| VOLUNTEER_PROFILES : "may be"
    USERS ||--o{ ACTIVITY_LOGS : "causes"
    USERS ||--o{ REPORT_EXPORTS : "requests"
    USERS ||--o{ CHECK_INS : "scans"

    VOLUNTEER_PROFILES ||--o{ VOLUNTEER_GATE_ASSIGNMENTS : "assigned to"
    VOLUNTEER_PROFILES ||--o{ CHECK_IN_DEVICES : "operates"
    GATES ||--o{ VOLUNTEER_GATE_ASSIGNMENTS : "staffed by"
    GATES ||--o{ CHECK_INS : "records"

    EVENT_SESSIONS ||--o{ GATES : "serves"
    EVENT_SESSIONS ||--o{ REGISTRATIONS : "scopes"
    EVENT_SESSIONS ||--o{ TICKETS : "admits to"
    EVENT_SESSIONS ||--o{ CHECK_INS : "scopes"

    ATTENDEES ||--o{ REGISTRATIONS : "places"
    ATTENDEES ||--o{ TICKETS : "holds"
    ATTENDEES ||--o{ PAYMENTS : "pays"
    ATTENDEES ||--o{ NOTIFICATIONS : "receives"
    ATTENDEES ||--o{ CHECK_INS : "checked in by"
    ATTENDEES ||--o| MEDIA_FILES : "profile photo"
    ATTENDEES ||--o{ ATTENDEES : "merged into"

    REGISTRATIONS ||--o{ REGISTRATION_GUESTS : "includes"
    REGISTRATIONS ||--o{ TICKETS : "produces"
    REGISTRATIONS ||--o{ PAYMENTS : "settled by"
    REGISTRATIONS ||--o{ REFUNDS : "refunded by"
    REGISTRATIONS ||--o{ CHECK_INS : "attended via"

    TICKET_TYPES ||--o{ REGISTRATIONS : "categorises"
    TICKET_TYPES ||--o{ TICKETS : "defines"

    TICKETS ||--|| QR_CODES : "encoded as"
    TICKETS ||--o{ CHECK_INS : "scanned as"
    TICKETS ||--o| TICKETS : "reissued from"
    TICKETS ||--o| MEDIA_FILES : "pdf"

    PAYMENTS ||--o{ PAYMENT_TRANSACTIONS : "logs"
    PAYMENTS ||--o{ REFUNDS : "reversed by"
    PAYMENTS ||--o| MEDIA_FILES : "proof"

    NOTIFICATION_TEMPLATES ||--o{ NOTIFICATIONS : "renders"
    NOTIFICATIONS ||--o{ NOTIFICATION_EVENTS : "receipts"
    NOTIFICATIONS ||--o| MEDIA_FILES : "attachment"

    CHECK_IN_DEVICES ||--o{ CHECK_INS : "captures"
    QR_CODES ||--o| MEDIA_FILES : "image"
    REPORT_EXPORTS ||--o| MEDIA_FILES : "output"
```

---

## 4.2 Registration domain

The core identity and order structure. One attendee places many registrations over time; each registration covers one or more people.

```mermaid
erDiagram
    ATTENDEES {
        bigint id PK
        char ulid UK
        string full_name
        string mobile UK "identity key, E.164"
        string whatsapp_number
        string email "indexed, not unique"
        string gender
        string occupation
        string designation
        string participant_type "6 values"
        smallint ssc_batch_year "1971..current"
        bigint profile_photo_media_id FK
        boolean tshirt_required
        string tshirt_size "XS..XXXL"
        boolean is_verified
        bigint merged_into_attendee_id FK
    }

    REGISTRATIONS {
        bigint id PK
        char ulid UK
        string registration_number UK
        bigint attendee_id FK
        bigint ticket_type_id FK
        bigint event_session_id FK
        string participation_type "single couple family"
        tinyint adults_count
        tinyint children_count
        tinyint total_persons "generated"
        string status
        bigint subtotal_paisa
        bigint discount_paisa
        bigint total_paisa
        text special_notes
        timestamp confirmed_at
        timestamp locked_at
    }

    REGISTRATION_GUESTS {
        bigint id PK
        char ulid UK
        bigint registration_id FK
        string full_name
        string relation
        string age_group "adult child"
        tinyint age
        boolean tshirt_required
        string tshirt_size
        boolean is_admitted
    }

    TICKET_TYPES {
        bigint id PK
        string code UK "ALM STU TCH STF VIP FAM SPN"
        string name
        bigint base_price_paisa
        bigint additional_adult_price_paisa
        bigint additional_child_price_paisa
        tinyint base_admits
        tinyint max_admits
        json allowed_participant_types
        int quantity_total
        int quantity_sold
        int quantity_reserved
        boolean requires_approval
        boolean is_active
    }

    MEDIA_FILES {
        bigint id PK
        char ulid UK
        string collection
        string path
        string mime_type
        char checksum_sha256
        string scan_status
    }

    ATTENDEES ||--o{ REGISTRATIONS : "places"
    ATTENDEES ||--o| MEDIA_FILES : "profile photo"
    ATTENDEES ||--o{ ATTENDEES : "merged into"
    REGISTRATIONS ||--o{ REGISTRATION_GUESTS : "includes"
    TICKET_TYPES ||--o{ REGISTRATIONS : "categorises"
```

**Cardinality rationale**

| Relationship | Cardinality | Why |
|---|---|---|
| Attendee → Registrations | 1 : many | Alumni may register, cancel, and re-register; identity persists across all of it |
| Registration → Guests | 1 : many, cascade | A guest has no meaning without their registration |
| Registration → TicketType | many : 1 | Type is chosen at registration and priced from it |
| Attendee → Attendee (merge) | self, optional | Deduplication redirects without destroying the original row |

---

## 4.3 Ticketing domain

Where a paid registration becomes admission-bearing instruments.

```mermaid
erDiagram
    REGISTRATIONS {
        bigint id PK
        string registration_number UK
        string status
    }

    TICKETS {
        bigint id PK
        char ulid UK "carried in the QR payload"
        string ticket_number UK "DEC100-ALM-1998-04217"
        bigint registration_id FK
        bigint attendee_id FK
        bigint ticket_type_id FK
        bigint event_session_id FK
        string status
        tinyint admits_total
        tinyint admitted_count "atomic counter"
        bigint price_paid_paisa
        string holder_name "snapshot"
        smallint holder_batch_year "snapshot"
        string holder_type_label "snapshot"
        timestamp issued_at
        timestamp voided_at
        string void_reason
        bigint replaces_ticket_id FK
        bigint pdf_media_id FK
        int manifest_version
    }

    QR_CODES {
        bigint id PK
        char ulid UK
        bigint ticket_id FK
        tinyint payload_version
        string payload
        char payload_hash UK
        string signature "Ed25519"
        string signing_key_id
        timestamp expires_at
        boolean is_active
        timestamp revoked_at
        bigint image_media_id FK
        int scan_count
    }

    CHECK_INS {
        bigint id PK
        char ulid UK
        char client_scan_uuid UK "offline idempotency"
        bigint ticket_id FK
        string result
        tinyint admitted_count
        json admitted_guest_ids
        boolean signature_valid
        string scan_mode "online offline"
        boolean conflict_flag
        timestamp scanned_at "device clock, ms"
        timestamp synced_at
    }

    REGISTRATIONS ||--o{ TICKETS : "produces"
    TICKETS ||--|| QR_CODES : "encoded as"
    TICKETS ||--o{ CHECK_INS : "scanned as"
    TICKETS ||--o| TICKETS : "reissued from"
```

**Why `tickets` → `qr_codes` is 1:1 in practice but 1:many in structure.** A ticket has exactly one *active* QR code at a time, enforced by `idx_qr_ticket_active`. Historical QR rows are retained with `is_active = false` after a reissue or key rotation, so a scan of an old printed ticket produces `revoked` rather than an unexplained failure. Modelling this as a strict 1:1 would force destructive updates and lose the ability to explain a rejected scan at the gate.

---

## 4.4 Payment domain

```mermaid
erDiagram
    REGISTRATIONS {
        bigint id PK
        string registration_number UK
        bigint total_paisa
    }

    PAYMENTS {
        bigint id PK
        char ulid UK
        string payment_number UK
        bigint registration_id FK
        bigint attendee_id FK
        string method "bkash nagad rocket sslcommerz manual"
        string channel "online manual"
        string status
        bigint amount_due_paisa
        bigint amount_paid_paisa
        bigint fee_paisa
        bigint net_paisa
        bigint refunded_paisa
        string gateway_transaction_id
        string payer_msisdn
        string manual_trx_id
        bigint manual_proof_media_id FK
        bigint verified_by_user_id FK
        timestamp paid_at
        string idempotency_key UK
        string reconciliation_status
    }

    PAYMENT_TRANSACTIONS {
        bigint id PK
        char ulid UK
        bigint payment_id FK
        string type "initiate callback ipn verify refund"
        string direction "outbound inbound"
        string gateway
        string status
        bigint amount_paisa
        string gateway_transaction_id
        string gateway_status_code
        json request_payload "redacted"
        json response_payload "redacted"
        boolean signature_valid
        int latency_ms
        string idempotency_key UK
    }

    REFUNDS {
        bigint id PK
        char ulid UK
        string refund_number UK
        bigint payment_id FK
        bigint registration_id FK
        bigint amount_paisa
        string reason
        string type "full partial"
        string status
        bigint approved_by_user_id FK
        string gateway_refund_id
        json voided_ticket_ids
    }

    REGISTRATIONS ||--o{ PAYMENTS : "settled by"
    PAYMENTS ||--o{ PAYMENT_TRANSACTIONS : "logs"
    PAYMENTS ||--o{ REFUNDS : "reversed by"
    REGISTRATIONS ||--o{ REFUNDS : "refunded by"
```

**Why one registration can have many payments.** A first attempt times out, the attendee retries with a different wallet, and a third attempt succeeds. Constraining this to 1:1 would force destructive updates and erase the failure history that reconciliation depends on. Exactly one payment per registration may hold `status = succeeded`, enforced in the application layer and asserted by the nightly consistency job.

---

## 4.5 Check-in domain

```mermaid
erDiagram
    EVENT_SESSIONS {
        bigint id PK
        string code UK
        string name
        timestamp checkin_opens_at
        timestamp checkin_closes_at
        int capacity
        int admitted_count
    }

    GATES {
        bigint id PK
        string code UK
        string name
        bigint event_session_id FK
        json allowed_ticket_type_ids
        int admitted_count
    }

    USERS {
        bigint id PK
        string name
        string email UK
        string status
    }

    VOLUNTEER_PROFILES {
        bigint id PK
        bigint user_id FK
        string volunteer_code UK
        string pin_hash
        timestamp shift_starts_at
        timestamp shift_ends_at
        boolean is_active
        int total_scans
    }

    VOLUNTEER_GATE_ASSIGNMENTS {
        bigint id PK
        bigint volunteer_profile_id FK
        bigint gate_id FK
        bigint event_session_id FK
    }

    CHECK_IN_DEVICES {
        bigint id PK
        string device_code UK
        string device_fingerprint UK
        string platform
        bigint assigned_volunteer_profile_id FK
        string status
        int manifest_version
        timestamp last_sync_at
        int pending_scan_count
        tinyint battery_level
    }

    CHECK_INS {
        bigint id PK
        char client_scan_uuid UK
        bigint ticket_id FK
        bigint gate_id FK
        bigint device_id FK
        bigint scanned_by_user_id FK
        string result
        tinyint admitted_count
        boolean is_manual_override
        boolean conflict_flag
        timestamp scanned_at
        int device_clock_skew_ms
    }

    USERS ||--o| VOLUNTEER_PROFILES : "may be"
    VOLUNTEER_PROFILES ||--o{ VOLUNTEER_GATE_ASSIGNMENTS : "assigned to"
    GATES ||--o{ VOLUNTEER_GATE_ASSIGNMENTS : "staffed by"
    VOLUNTEER_PROFILES ||--o{ CHECK_IN_DEVICES : "operates"
    EVENT_SESSIONS ||--o{ GATES : "serves"
    CHECK_IN_DEVICES ||--o{ CHECK_INS : "captures"
    GATES ||--o{ CHECK_INS : "records"
    USERS ||--o{ CHECK_INS : "scans"
```

**Why devices and volunteers are separate entities.** Volunteers swap shifts and hand devices to each other. Binding scans to only a device loses accountability; binding to only a volunteer loses the ability to diagnose "gate C's tablet stopped syncing at 10:40." Both are recorded on every scan.

---

## 4.6 Notification domain

```mermaid
erDiagram
    NOTIFICATION_TEMPLATES {
        bigint id PK
        string key
        string channel "email sms whatsapp"
        string locale "en bn"
        smallint version
        string subject
        text body
        string whatsapp_template_name
        string whatsapp_template_status
        json variables
        tinyint estimated_segments
        boolean is_active
    }

    NOTIFICATIONS {
        bigint id PK
        char ulid UK
        string notifiable_type
        bigint notifiable_id
        bigint attendee_id FK
        string template_key
        string channel
        string recipient
        text body_rendered
        json payload
        bigint attachment_media_id FK
        string status
        tinyint priority
        tinyint attempts
        timestamp scheduled_for
        string provider
        string provider_message_id
        tinyint segment_count
        int cost_paisa
        string dedupe_key UK
    }

    NOTIFICATION_EVENTS {
        bigint id PK
        bigint notification_id FK
        string event "sent delivered read failed bounced"
        string provider_status
        json raw_payload
        timestamp occurred_at
    }

    ATTENDEES {
        bigint id PK
        string full_name
        string mobile UK
        string whatsapp_number
        string email
    }

    NOTIFICATION_TEMPLATES ||--o{ NOTIFICATIONS : "renders"
    NOTIFICATIONS ||--o{ NOTIFICATION_EVENTS : "receipts"
    ATTENDEES ||--o{ NOTIFICATIONS : "receives"
```

---

## 4.7 Lifecycle state machines

The ERD shows structure; these show permitted movement. Any transition not drawn here is rejected by the application layer.

### Registration

```mermaid
stateDiagram-v2
    [*] --> draft: wizard started
    draft --> pending_payment: submitted
    draft --> expired: abandoned 24h
    pending_payment --> paid: payment succeeded
    pending_payment --> expired: intent TTL elapsed
    pending_payment --> cancelled: attendee cancels
    paid --> confirmed: tickets issued
    confirmed --> cancelled: admin cancels
    cancelled --> refunded: refund completed
    confirmed --> refunded: refund completed
    expired --> pending_payment: attendee retries
    refunded --> [*]
    cancelled --> [*]
```

`expired → pending_payment` is deliberate. A dropped mobile connection mid-payment is common; forcing the attendee to re-enter a five-step form because their intent timed out is a real abandonment cause.

### Payment

```mermaid
stateDiagram-v2
    [*] --> pending: intent created
    pending --> initiated: gateway session opened
    pending --> awaiting_verification: manual proof submitted
    pending --> expired: TTL elapsed, gateway session never opened
    initiated --> processing: callback or IPN received
    processing --> succeeded: server-side verify passed
    processing --> failed: verify declined
    initiated --> expired: TTL elapsed
    initiated --> cancelled: attendee aborted
    awaiting_verification --> succeeded: manager approved
    awaiting_verification --> rejected: manager rejected
    succeeded --> partially_refunded: partial refund
    succeeded --> refunded: full refund
    partially_refunded --> refunded: remainder refunded
    failed --> [*]
    rejected --> [*]
    refunded --> [*]
```

**The critical edge is `processing → succeeded`.** It is reachable only through a server-to-server verification call. No browser redirect, no unverified webhook, and no admin action other than manual approval can produce it. See [06 §6.6 Payment security](06-security-architecture.md#66-payment-security).

**`pending → expired` was added 2026-08-04 (Phase 4A).** The expiry sweeper's own query (§5.3 payment intent expiry) always included `pending` alongside `initiated` — an abandoned registration whose payer never even opened the gateway page must release capacity too, not just one that opened it and vanished. The diagram simply hadn't been updated to match; this is a doc fix, not a new business rule.

### Ticket

```mermaid
stateDiagram-v2
    [*] --> issued: payment confirmed
    issued --> active: QR signed and assets rendered
    active --> partially_admitted: some persons entered
    partially_admitted --> fully_admitted: remaining persons entered
    active --> fully_admitted: all persons entered at once
    active --> voided: admin voided
    partially_admitted --> voided: admin voided
    active --> refunded: refund completed
    voided --> [*]
    refunded --> [*]
    fully_admitted --> [*]
```

`partially_admitted` is what makes families work. A family of four arriving as two pairs, twenty minutes apart, is normal behaviour — not an error state.

### Check-in scan result

```mermaid
stateDiagram-v2
    [*] --> parsed: QR decoded
    parsed --> invalid_format: unparseable
    parsed --> signature_check: format valid
    signature_check --> invalid_signature: Ed25519 failed
    signature_check --> manifest_check: signature valid
    manifest_check --> revoked: voided or refunded
    manifest_check --> unpaid: payment not settled
    manifest_check --> expired: past expiry
    manifest_check --> scope_check: ticket live
    scope_check --> wrong_gate: type not allowed at gate
    scope_check --> wrong_session: session mismatch
    scope_check --> admission_check: scope ok
    admission_check --> duplicate: no admissions remaining
    admission_check --> over_capacity: party exceeds remaining
    admission_check --> admitted: counter incremented
    admitted --> [*]
    invalid_format --> [*]
    invalid_signature --> [*]
    revoked --> [*]
    duplicate --> [*]
```

Every terminal state — including all six rejection states — writes a `check_ins` row. The gate operator sees a specific reason, and the Event Manager can later answer precisely why a given person was turned away.

### Notification

```mermaid
stateDiagram-v2
    [*] --> queued: outbox row written
    queued --> sending: worker claimed
    sending --> sent: provider accepted
    sending --> queued: retryable error, backoff
    sending --> failed: max attempts exhausted
    sent --> delivered: DLR or status webhook
    sent --> bounced: hard bounce
    delivered --> read: WhatsApp read receipt
    queued --> cancelled: superseded or kill switch
    failed --> [*]
    read --> [*]
    bounced --> [*]
```

---

## 4.8 Referential integrity summary

| Parent → Child | On delete | Reason |
|---|---|---|
| `registrations` → `registration_guests` | CASCADE | Guests have no standalone meaning |
| `notifications` → `notification_events` | CASCADE | Receipts belong to their message |
| `volunteer_profiles` → `volunteer_gate_assignments` | CASCADE | Assignment is pure join data |
| `attendees` → `registrations` | RESTRICT | Never orphan a paid order |
| `registrations` → `tickets` | RESTRICT | Never orphan an issued ticket |
| `payments` → `payment_transactions` | RESTRICT | Audit trail is immutable |
| `tickets` → `check_ins` | RESTRICT | Attendance record is permanent |
| `users` → `check_ins.scanned_by_user_id` | SET NULL | Removing a volunteer must not erase attendance |
| `users` → `activity_logs.causer_id` | SET NULL | Audit survives account deletion |
| `media_files` → any referencing FK | SET NULL | A missing file degrades display, never breaks a record |

The pattern: **history is protected by RESTRICT, actors are released by SET NULL, and only pure child data cascades.** Deleting a user must never delete the record of what they did.

---

**Next:** [05 — Data Flow Design](05-data-flows.md)
