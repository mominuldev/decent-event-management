# 05 — Data Flow Design

> Phase 1 deliverable. End-to-end flows for registration, payment, ticket generation, notification, check-in, and offline sync — including the failure paths, which is where most of the real design lives.

---

## 5.1 Master flow

```mermaid
flowchart LR
    A["Visitor"] --> B["Registration<br/>5-step wizard"]
    B --> C["Pricing &<br/>capacity hold"]
    C --> D{"Payment"}
    D -->|online| E["Gateway"]
    D -->|manual| F["Verification<br/>queue"]
    E --> G["Server-side<br/>verify"]
    F --> G
    G --> H["Ticket issued<br/>QR signed"]
    H --> I["Assets rendered<br/>PDF + QR PNG"]
    I --> J["Notify<br/>Email · SMS · WhatsApp"]
    J --> K["Attendee holds<br/>digital ticket"]
    K --> L["Event day<br/>QR scan"]
    L --> M["Admission<br/>counter"]
    M --> N["Attendance<br/>reporting"]

    style H fill:#1f6f4a,color:#fff
    style G fill:#8a4b1f,color:#fff
    style M fill:#8a4b1f,color:#fff
```

The two amber steps are the system's trust boundaries — the only places where an external claim becomes an internal fact. The green step is where money becomes an admission right. Everything else is transport.

---

## 5.2 Registration flow

### Happy path

```mermaid
sequenceDiagram
    autonumber
    participant V as Visitor
    participant FE as Next.js
    participant API as Laravel API
    participant DB as MySQL
    participant Q as Queue
    participant S3 as Object Storage

    V->>FE: Open registration
    FE->>API: GET /ticket-types (public, cached)
    API-->>FE: Types + prices + eligibility

    Note over V,FE: Step 1 Personal · Step 2 Academic<br/>Step 3 Attendance & Family<br/>Step 4 T-Shirt · Step 5 Review
    FE->>FE: Per-step Zod validation<br/>draft saved to localStorage

    V->>FE: Upload profile photo
    FE->>API: POST /uploads (multipart)
    API->>API: MIME sniff · size check · re-encode
    API->>S3: Store, private ACL
    API->>DB: INSERT media_files (scan_status=pending)
    API-->>FE: media ULID
    API->>Q: dispatch ScanUploadedFile

    V->>FE: Submit
    FE->>API: POST /registrations (Idempotency-Key)

    rect rgb(240,246,242)
    Note over API,DB: Single transaction
    API->>DB: Upsert attendee by normalised mobile
    API->>DB: INSERT registration (status=pending_payment)
    API->>DB: INSERT registration_guests
    API->>DB: Conditional UPDATE ticket_types quantity_reserved
    API->>DB: INSERT payment (status=pending)
    API->>DB: INSERT notifications (registration.received × 3)
    end

    API-->>FE: registration ULID + payment ULID
    Q-->>Q: Drain notification outbox
    FE->>V: Redirect to payment
```

### Key decisions in this flow

**Attendee upsert, not insert.** Step 1 of the transaction matches on normalised mobile number. A returning alumnus who registered, cancelled, and came back is the *same person* — their batch year and profile carry forward, and the reporting stays correct. Conflicting field values (a changed designation) update the attendee; identity-bearing fields (batch year, participant type) require Event Manager confirmation before overwriting.

**Capacity is reserved, not sold, at submission.** `quantity_reserved` increments here; `quantity_sold` increments only on payment success. The reservation expires with the payment intent (`payment.intent_ttl_minutes`, default 30) and is released by a scheduled sweeper. Without this, a VIP tier "sells out" to fifty people who never paid.

**Notifications are written inside the transaction, sent outside it.** The outbox rows commit atomically with the registration; the SMS gateway being down cannot roll back a registration. [ADR-07](README.md#adr-07--notifications-go-through-a-database-outbox-not-direct-sends).

**The photo upload is decoupled from submission.** Uploading during step 1 rather than at submit means a failed upload on a weak connection doesn't discard four steps of typed data.

### Validation gates

| Rule | Where enforced |
|---|---|
| SSC batch year 1971 … current year | Zod + FormRequest + CHECK-equivalent app validation |
| Batch year required for student/alumni types | Conditional, both layers |
| Family fields required when `participation_type = family` | Conditional, both layers |
| `adults_count + children_count ≤ max_family_size` | FormRequest, bound from `event_settings` |
| T-shirt size required when `tshirt_required` | Conditional, both layers |
| Participant type allowed for chosen ticket type | Server only — `ticket_types.allowed_participant_types` |
| Mobile is a valid BD or international E.164 number | Server only, normalised on write |
| Sales window open, type active, capacity available | Server only |

Anything a client could lie about to gain an advantage — eligibility, price, capacity — is validated **server-side only**. Client validation exists for UX.

---

## 5.3 Payment flow — online gateway

```mermaid
sequenceDiagram
    autonumber
    participant A as Attendee
    participant FE as Next.js
    participant API as Laravel API
    participant GW as Gateway
    participant DB as MySQL
    participant Q as Queue

    A->>FE: Choose method, pay
    FE->>API: POST /payments/{ulid}/initiate (Idempotency-Key)
    API->>DB: Check idempotency_keys
    API->>DB: UPDATE payments status=initiated
    API->>GW: createIntent(amount, ref, callbackUrl)
    API->>DB: INSERT payment_transactions (type=initiate, outbound)
    GW-->>API: gateway session + redirect URL
    API-->>FE: redirect URL
    FE->>A: Redirect to gateway

    A->>GW: Authorise (PIN / OTP)

    par Browser return
        GW->>FE: Redirect to /payment/return?ref=...
        FE->>API: GET /payments/{ulid}/status
        Note right of API: UNTRUSTED — display only
    and Server IPN
        GW->>API: POST /webhooks/{gateway}
        API->>API: Verify signature + source IP
        API->>DB: INSERT payment_transactions (type=ipn, inbound)
    end

    API->>GW: verify(gatewayReference)
    API->>DB: INSERT payment_transactions (type=verify)
    GW-->>API: authoritative status + settled amount

    alt Verified and amount matches
        rect rgb(240,246,242)
        API->>DB: UPDATE payments status=succeeded, paid_at
        API->>DB: UPDATE registrations status=paid
        API->>DB: Conditional UPDATE ticket_types sold+1, reserved-1
        end
        API->>Q: dispatch IssueTicket
        API-->>FE: succeeded
    else Amount mismatch
        API->>DB: UPDATE payments reconciliation_status=amount_mismatch
        API->>Q: dispatch AlertEventManager
        API-->>FE: under review
    else Declined or timeout
        API->>DB: UPDATE payments status=failed
        API->>DB: Release reserved capacity
        API->>Q: dispatch SendPaymentFailedNotice
        API-->>FE: failed, retry available
    end
```

**The rule this flow exists to enforce:** the browser return is never trusted. The `par` block shows both signals arriving; only the server-to-server `verify` call moves the payment to `succeeded`. An attacker who forges a return URL gets a page that says "checking…" and then "failed."

**Amount mismatch is a distinct outcome, not a failure.** If the gateway reports a settled amount different from `amount_due_paisa`, the payment is neither succeeded nor failed — it is flagged for human review. Silently accepting an underpayment or treating an overpayment as a decline both cause reconciliation problems that surface weeks later.

### Payment intent expiry

```mermaid
flowchart TD
    S["Scheduler — every 5 min"] --> Q1["Find payments where<br/>status IN (pending, initiated)<br/>AND expires_at < now"]
    Q1 --> L{"For each"}
    L --> V["Query gateway:<br/>was it actually paid?"]
    V -->|paid| R["Recover: mark succeeded,<br/>issue ticket, notify"]
    V -->|not paid| E["status = expired<br/>release quantity_reserved"]
    E --> N["Notify attendee<br/>with retry link"]
    R --> N2["Notify attendee<br/>ticket delivered"]
```

The sweeper queries the gateway before expiring anything. Mobile financial services in Bangladesh routinely deliver a successful transaction with a delayed or missing IPN; expiring a genuinely paid registration is far worse than holding capacity for five extra minutes.

---

## 5.4 Payment flow — manual verification

A meaningful share of attendees will send money by personal bKash transfer whatever the website offers. Designing this path properly is not optional.

```mermaid
sequenceDiagram
    autonumber
    participant A as Attendee
    participant FE as Next.js
    participant API as Laravel API
    participant EM as Event Manager
    participant DB as MySQL
    participant Q as Queue

    FE->>A: Show merchant number + exact amount + reference
    A->>A: Sends money from personal wallet
    A->>FE: Enter TrxID, sender number, upload screenshot
    FE->>API: POST /payments/{ulid}/manual-proof
    API->>API: Validate TrxID format · MIME sniff image
    API->>DB: UPDATE payments status=awaiting_verification
    API->>DB: INSERT media_files (payment proof)
    API->>Q: dispatch NotifyManagersPendingVerification
    API-->>A: Submitted, verification within 24h

    EM->>API: GET /payments?status=awaiting_verification
    API->>DB: SELECT with duplicate-TrxID flag
    API-->>EM: Queue with proof images

    Note over EM: Cross-check TrxID against<br/>merchant wallet statement

    alt Approve
        EM->>API: POST /payments/{ulid}/verify
        API->>API: Assert TrxID not already used
        rect rgb(240,246,242)
        API->>DB: UPDATE payments succeeded, verified_by, verified_at
        API->>DB: UPDATE registrations paid
        API->>DB: Conditional UPDATE ticket_types
        API->>DB: INSERT activity_logs (payment.verified_manual)
        end
        API->>Q: dispatch IssueTicket
    else Reject
        EM->>API: POST /payments/{ulid}/reject (reason required)
        API->>DB: UPDATE payments rejected + rejection_reason
        API->>DB: INSERT activity_logs
        API->>Q: dispatch SendRejectionNotice
    end
```

**Duplicate TrxID detection is the whole security model here.** `manual_trx_id` is indexed and checked on both submission and approval — the same screenshot forwarded by five people must fail four times. The verification UI surfaces a warning banner when a submitted TrxID matches any existing record, approved or not.

**Approval is a logged, attributed, irreversible act.** `verified_by_user_id`, `verified_at`, and an `activity_logs` entry with the before/after state. When the finance reconciliation finds an unmatched approval, the record shows exactly who approved it and when.

---

## 5.5 Ticket generation flow

Triggered by `PaymentSucceeded`. Split into a fast synchronous part (the ticket exists and is valid) and a slower asynchronous part (the pretty assets), so a PDF rendering failure never blocks an attendee from having a working ticket.

```mermaid
sequenceDiagram
    autonumber
    participant EV as PaymentSucceeded event
    participant J1 as IssueTicket job
    participant DB as MySQL
    participant KMS as Signing Key
    participant J2 as GenerateTicketAssets job
    participant S3 as Object Storage
    participant Q as Notification queue

    EV->>J1: dispatch (payments queue)
    J1->>DB: Load registration + guests + ticket_type
    J1->>J1: admits_total = adults + children
    J1->>J1: ticket_number = DEC100-{TYPE}-{BATCH}-{SEQ}

    rect rgb(240,246,242)
    Note over J1,DB: Transaction
    J1->>DB: INSERT tickets (status=issued, admitted_count=0,<br/>holder snapshots)
    J1->>KMS: Sign payload with Ed25519 private key
    KMS-->>J1: signature + key_id
    J1->>DB: INSERT qr_codes (payload, payload_hash, signature)
    J1->>DB: UPDATE tickets status=active, manifest_version++
    J1->>DB: UPDATE registrations status=confirmed
    end

    Note over J1: Ticket is now valid and scannable.<br/>Everything below is presentation.

    J1->>Q: dispatch GenerateTicketAssets (tickets queue)

    J2->>DB: Load ticket + qr_code
    J2->>J2: Render QR PNG (ECC level M, 512px)
    J2->>S3: Upload QR image
    J2->>J2: Render PDF (bilingual, print-safe)
    J2->>S3: Upload PDF
    J2->>DB: UPDATE tickets pdf_media_id, qr_codes image_media_id
    J2->>Q: dispatch ticket.delivered notifications
```

### Ticket number format

```
DEC100-ALM-1998-04217
│      │   │    └── zero-padded per-type sequence
│      │   └────── SSC batch year (0000 for non-alumni)
│      └────────── ticket_type.code
└───────────────── event prefix
```

Human-readable and diagnostic: a volunteer reading a number aloud at the gate immediately knows the person's category and batch, which matters when someone's phone is dead and the manual override path is being used. The unguessable identifier is the ULID; the ticket number is for humans and is never sufficient for admission on its own.

### PDF composition

Bilingual (English + Bangla), A5 portrait, designed to survive a phone screenshot and a low-toner office printer:
- QR code at ≥ 4cm with high quiet-zone margin and ECC level M
- Holder name, ticket number, type, admits count, batch year
- Event name, date, venue, gate instructions
- Profile photo thumbnail when present — the gate's identity check
- Footer: support contact and the "screenshot this page" instruction

---

## 5.6 Notification flow

```mermaid
sequenceDiagram
    autonumber
    participant EV as Domain Event
    participant DB as notifications outbox
    participant W as Notification worker
    participant T as Template renderer
    participant P as Provider
    participant WH as Receipt webhook

    EV->>DB: INSERT (status=queued, dedupe_key)<br/>inside business transaction

    loop Worker poll
        W->>DB: Claim batch WHERE status=queued<br/>AND scheduled_for <= now<br/>ORDER BY priority (SKIP LOCKED)
        W->>DB: UPDATE status=sending
        W->>T: Render template + variables
        T-->>W: subject + body (+ segment count)

        alt Channel kill switch off
            W->>DB: status=cancelled
        else Send
            W->>P: Dispatch
            alt Accepted
                P-->>W: provider_message_id
                W->>DB: status=sent, provider_message_id, cost_paisa
            else Retryable (5xx, timeout, rate limit)
                W->>DB: status=queued, attempts++,<br/>scheduled_for = now + backoff
            else Permanent (invalid number, blocked)
                W->>DB: status=failed, last_error
            end
        end
    end

    P-->>WH: Delivery receipt (DLR / status webhook)
    WH->>DB: INSERT notification_events
    WH->>DB: UPDATE notifications status=delivered|read|bounced
```

**`SKIP LOCKED` on the claim query** is what lets ten workers drain one outbox without contention or double-sends.

**`dedupe_key` is the one-shot guarantee.** For `ticket.delivered` it is `ticket:{ulid}:email`. A retried job, a re-fired event, or an admin clicking "resend" twice cannot produce two ticket emails — the unique index rejects the second insert.

**Per-channel kill switches** (`notification.sms_enabled`, `notification.whatsapp_enabled`) are checked at send time, not at enqueue time. When an SMS vendor fails at 9 AM on event day, an Event Manager flips one setting and the queue drains to `cancelled` instead of burning 20,000 retries and the entire SMS budget.

### Reminder scheduling

```mermaid
flowchart LR
    SCH["Scheduler — hourly"] --> CHK{"Reminder due?<br/>T-7 / T-1 / T-0"}
    CHK -->|no| END["exit"]
    CHK -->|yes| SEL["SELECT confirmed registrations<br/>with active tickets"]
    SEL --> CHUNK["Chunk 500"]
    CHUNK --> INS["Bulk INSERT notifications<br/>scheduled_for staggered<br/>over 4 hours"]
    INS --> RL["Rate-limited drain<br/>respects provider TPS"]
```

Staggering matters twice: it stays inside the SMS gateway's throughput limit, and it prevents 20,000 people opening their ticket link within the same sixty seconds.

---

## 5.7 Event check-in flow — online

```mermaid
sequenceDiagram
    autonumber
    participant AT as Attendee
    participant VOL as Volunteer
    participant APP as Scanner app
    participant SQL as Device SQLite
    participant API as Laravel API
    participant DB as MySQL

    AT->>VOL: Presents QR (phone or print)
    VOL->>APP: Scan
    APP->>APP: Parse payload — version, fields
    APP->>APP: Verify Ed25519 signature<br/>(embedded public key)

    alt Signature invalid
        APP->>SQL: Queue check_in (result=invalid_signature)
        APP->>VOL: REJECT — invalid ticket
    else Signature valid
        APP->>SQL: Manifest lookup by ticket ULID
        alt Voided / refunded / unpaid
            APP->>SQL: Queue (result=revoked|unpaid)
            APP->>VOL: REJECT — reason shown
        else Live ticket
            APP->>VOL: Show holder card<br/>name · photo · type<br/>admits 4, remaining 4
            VOL->>APP: Confirm party size entering (e.g. 2)
            APP->>API: POST /scanner/check-ins (online)

            rect rgb(240,246,242)
            API->>DB: UPDATE tickets SET admitted_count = admitted_count + 2<br/>WHERE id = ? AND admitted_count + 2 <= admits_total
            end

            alt 1 row affected
                API->>DB: INSERT check_ins (result=admitted)
                API->>DB: Increment gates + event_sessions counters
                API-->>APP: ADMITTED — 2 remaining
                APP->>VOL: Green · admit
            else 0 rows affected
                API->>DB: INSERT check_ins (result=duplicate)
                API->>DB: Load prior admission time and gate
                API-->>APP: DUPLICATE — first entry 09:42 at Gate A
                APP->>VOL: Red · do not admit
            end
        end
    end
```

**The atomic conditional UPDATE is the entire duplicate-prevention mechanism** ([ADR-04](README.md#adr-04--duplicate-entry-is-prevented-by-an-atomic-conditional-update)). Twenty volunteers scanning the same forwarded QR simultaneously produce exactly one admission and nineteen `duplicate` records. No application locks, no read-then-write race.

**The rejection message carries the prior admission's time and gate.** "Already used" starts an argument at the gate; "entered 09:42 at Gate A" ends one.

---

## 5.8 Event check-in flow — offline

The default assumption, not the fallback. See [01 §1.5](01-system-architecture.md#15-mobile-verification-architecture).

```mermaid
sequenceDiagram
    autonumber
    participant VOL as Volunteer
    participant APP as Scanner app
    participant SQL as Device SQLite
    participant NET as Network
    participant API as Laravel API
    participant DB as MySQL
    participant EM as Event Manager

    Note over APP,SQL: Pre-event — device has signal
    APP->>API: GET /scanner/manifest (If-None-Match)
    API->>DB: SELECT tickets WHERE manifest_version > ?
    API-->>APP: Delta + Ed25519 public key + ETag
    APP->>SQL: Upsert manifest rows

    Note over VOL,SQL: Event day — no connectivity
    loop Each scan
        VOL->>APP: Scan QR
        APP->>APP: Verify signature locally
        APP->>SQL: Read local admitted_count
        alt Admissions remaining
            APP->>SQL: Increment local counter<br/>INSERT scan queue (client_scan_uuid, scanned_at ms)
            APP->>VOL: ADMIT
        else None remaining
            APP->>SQL: INSERT scan queue (result=duplicate)
            APP->>VOL: REJECT — used on this device at HH:MM
        end
    end

    Note over APP,NET: Connectivity returns
    APP->>NET: Detect online
    APP->>API: POST /scanner/check-ins/sync<br/>batch of 100, Idempotency-Key
    API->>DB: For each: INSERT ... ON DUPLICATE KEY (client_scan_uuid)

    loop Each admitted scan
        API->>DB: Atomic conditional UPDATE tickets
        alt Applied
            API->>DB: check_ins result=admitted
        else Rejected — another gate got there first
            API->>DB: check_ins result=duplicate, conflict_flag=true
        end
    end

    API-->>APP: Per-scan results + new manifest version
    APP->>SQL: Reconcile local state, clear synced queue
    APP->>VOL: "3 conflicts flagged for review"

    API->>EM: Conflict dashboard entry
    EM->>API: Resolve with note
```

### Conflict resolution rules

| Situation | Resolution |
|---|---|
| Same ticket admitted on two offline devices | Earlier `scanned_at` (device clock) wins; later becomes `duplicate` with `conflict_flag = true` |
| Device clock skew > 60s detected at sync | Scans still accepted, skew recorded in `device_clock_skew_ms`, all affected scans flagged |
| Ticket voided after a device's last manifest sync | Offline admission is honoured for the person already inside, flagged for review — turning someone away retroactively is not possible |
| Same `client_scan_uuid` submitted twice | Second insert is a no-op via unique index; the original result is replayed |
| Family over-admitted across two gates | Later scan flagged `over_capacity` with conflict; Event Manager decides |

**The governing principle: offline scans are never silently discarded.** Every one is recorded with its true outcome. Where the offline decision and the server's authoritative state disagree, the disagreement becomes a visible, resolvable item — not a corrupted count.

### Sync triggers

| Trigger | Behaviour |
|---|---|
| Connectivity regained | Immediate sync attempt |
| Every 60 seconds while online | Batch upload, delta manifest pull |
| Queue depth > 50 | Force attempt regardless of timer |
| Volunteer taps "Sync now" | Manual, with progress UI |
| App foregrounded | Sync attempt |
| Backoff on failure | 5s → 15s → 60s → 5min, capped; never blocks scanning |

Scanning is **never** blocked by sync state. A device with 400 unsynced scans keeps admitting people at full speed.

---

## 5.9 Manual override flow

For the cracked screen, the dead battery, the printer that jammed. Every event needs it; every event gets it abused if it is given to volunteers ([02 §2.3](02-rbac-permissions.md#check-in)).

```mermaid
flowchart TD
    A["QR will not scan"] --> B["Volunteer searches by<br/>mobile last 4 / ticket number"]
    B --> C{"Match found?"}
    C -->|no| D["Escalate to<br/>registration desk"]
    C -->|yes| E["Volunteer requests override"]
    E --> F["Event Manager device<br/>receives request"]
    F --> G{"Manager reviews<br/>identity + ticket state"}
    G -->|deny| H["Rejected, logged"]
    G -->|approve| I["Manager PIN + reason"]
    I --> J["Atomic conditional UPDATE<br/>same path as normal admission"]
    J --> K["check_ins<br/>is_manual_override = true<br/>override_by_user_id, reason"]
    K --> L["activity_logs<br/>severity = notice"]
    L --> M["ADMITTED"]
```

Overrides go through the **same** atomic admission path, so an override cannot double-admit a ticket that was already fully used. It bypasses the QR check, never the counter.

---

## 5.10 Reporting flow

```mermaid
flowchart LR
    subgraph live["Live — event day"]
        L1["check_ins INSERT"] --> L2["Increment gate<br/>+ session counters"]
        L2 --> L3["Redis dashboard cache<br/>60s TTL"]
        L3 --> L4["Admin live dashboard<br/>SSE / 30s poll"]
    end

    subgraph batch["Async — exports"]
        B1["Manager requests export"] --> B2["INSERT report_exports<br/>status=queued"]
        B2 --> B3["Worker on reports queue"]
        B3 --> B4[("Read replica")]
        B4 --> B5["Render PDF / XLSX / CSV"]
        B5 --> B6["Upload to object storage"]
        B6 --> B7["Signed URL, 7-day expiry"]
        B7 --> B8["Notify requester"]
    end

    subgraph roll["Nightly"]
        N1["Aggregate rollups"] --> N2["Batch · type · revenue<br/>attendance · T-shirt"]
        N2 --> N3["Cached summary tables"]
    end
```

Exports read the **replica**, never the primary — a 20,000-row Excel render must not compete with payment writes. Live dashboards read incremented counters, not aggregate queries; counting 20,000 `check_ins` rows every thirty seconds across eight concurrent admin sessions is exactly the load pattern that takes a database down mid-event.

### Report catalogue

| Report | Grouping | Primary source | Formats |
|---|---|---|---|
| Registrations by SSC batch | `ssc_batch_year` × `participant_type` | `attendees` + `registrations` | PDF, XLSX, CSV |
| Ticket sales by type | `ticket_type` × `status` | `tickets` | PDF, XLSX, CSV |
| Revenue summary | `method` × day | `payments` (succeeded) | PDF, XLSX |
| Revenue reconciliation | `reconciliation_status` | `payments` + `payment_transactions` | XLSX |
| Attendance by gate | `gate` × hour | `check_ins` (admitted) | PDF, XLSX |
| Attendance vs registered | `ticket_type` | `tickets` | PDF |
| **T-shirt production order** | size × type, persons not registrations | `attendees` + `registration_guests` | XLSX, CSV |
| Family and guest manifest | `registration` | `registration_guests` | XLSX |
| Refunds | status × reason | `refunds` | XLSX |
| Notification delivery & cost | channel × status | `notifications` | XLSX |
| Volunteer scan throughput | volunteer × hour | `check_ins` | XLSX |

The T-shirt report is the one most likely to be built wrong. It must aggregate **people** — lead attendees plus registration guests — not registrations. A family of four with four different sizes contributes four rows, not one.

---

**Next:** [06 — Security Architecture](06-security-architecture.md)
