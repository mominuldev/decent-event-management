# Decent Ticket Management — Phase 1: Architecture & Database Design

**System:** Event Ticket Management for the School 100 Years Celebration
**Phase:** 1 of 9 — Architecture, System Design, Database Planning
**Status:** Design complete, pending sign-off. No application code is written in this phase.

---

## Documents

| # | Document | Covers |
|---|---|---|
| 01 | [System Architecture](01-system-architecture.md) | Application, frontend, backend, database, mobile, notification, payment architecture |
| 02 | [RBAC & Permissions](02-rbac-permissions.md) | Roles, permission catalogue, policy matrix |
| 03 | [Database Schema](03-database-schema.md) | Every table: purpose, fields, types, indexes, relationships |
| 04 | [Entity Relationship Diagram](04-erd.md) | Full ERD, cardinality notes, lifecycle state machines |
| 05 | [Data Flow Design](05-data-flows.md) | Registration, payment, notification, check-in, offline sync flows |
| 06 | [Security Architecture](06-security-architecture.md) | AuthN, AuthZ, uploads, QR signing, payment, API, audit |
| 07 | [Scalability Plan](07-scalability-plan.md) | Capacity model, infrastructure, caching, queues, load targets |
| 08 | [Development Roadmap](08-development-roadmap.md) | Phases 2–9 with objectives, deliverables, timelines, dependencies |

---

## The shape of the problem

This is not a generic ticketing SaaS. Three properties of *this* event drive nearly every design decision:

**1. It happens once, on one day.** There is no second chance to fix a check-in bug. The system's risk profile is inverted from normal SaaS: a registration outage on a Tuesday is recoverable; a gate that stops scanning at 9:00 AM on event day is not. Correctness and offline resilience at the gate outrank almost everything else.

**2. A ticket is not a person.** A Family ticket admits four people through a gate. A Couple ticket admits two. The system must model *admissions* as a counted resource on a ticket, not assume one scan equals one entry — otherwise families get turned away or the same ticket walks in twice.

**3. The venue's network will fail.** A school campus with 10,000 people on it will not have reliable connectivity at the gates. Any design that requires a server round-trip to admit a guest will fail in practice. The mobile app is therefore **offline-first by default**, not offline-as-a-fallback.

---

## Architecture decision record

These are the load-bearing decisions. Each one is revisited in the document that owns it.

### ADR-01 — Modular monolith, not microservices
Laravel 13 as a single deployable with strict internal module boundaries (`Registration`, `Ticketing`, `Payment`, `Notification`, `CheckIn`, `Reporting`). The system runs for roughly 9 months of build plus one event day, operated by a small team. Microservices would add distributed-transaction complexity and operational surface for zero benefit at this scale. Module boundaries are enforced in code so extraction stays possible later.

### ADR-02 — Money is stored as integer paisa, never decimal or float
All monetary columns are `BIGINT UNSIGNED` holding **paisa** (1 BDT = 100 paisa), with an explicit `currency CHAR(3)` defaulting to `BDT`. Floating-point money causes reconciliation drift against gateway settlement reports. Every gateway in scope (bKash, Nagad, Rocket, SSLCommerz) settles in BDT with two decimal places.

### ADR-03 — QR codes are Ed25519-signed, not database lookups
The QR payload carries a detached **Ed25519 signature** produced by the server's private key. Scanner devices hold only the **public key**, so a stolen or rooted device cannot forge a valid ticket — which an HMAC shared-secret design would allow. Devices additionally sync a compact revocation manifest so refunded or voided tickets are rejected offline. Signature proves *authenticity*; manifest proves *current validity*. See [06 §6.5 QR code security](06-security-architecture.md#65-qr-code-security).

### ADR-04 — Duplicate entry is prevented by an atomic conditional UPDATE
Not by a `SELECT` then `INSERT`, which races when twenty volunteers scan simultaneously. Admission is:

```sql
UPDATE tickets
   SET admitted_count = admitted_count + :n
 WHERE id = :id AND admitted_count + :n <= admits_total;
```

Zero affected rows means duplicate or over-admission — rejected. This is correct under concurrency, handles partial family arrivals, and needs no application-level locking.

### ADR-05 — Every scan is recorded, including rejections
`check_ins` stores all scan attempts with a `result` enum (`admitted`, `duplicate`, `revoked`, `unpaid`, `invalid_signature`, `over_capacity`, `wrong_gate`). Rejections are the audit trail that resolves the inevitable "your app wouldn't let my family in" dispute at the gate.

### ADR-06 — Public identifiers are ULIDs; primary keys are BIGINT
Auto-increment PKs stay internal for index efficiency. Anything exposed in a URL, QR code, or API response uses a ULID, so competitors and attendees cannot enumerate registrations or infer sales volume from an ID.

### ADR-07 — Notifications go through a database outbox, not direct sends
A `notifications` table is written inside the same transaction as the business event, then drained by queue workers. A dead SMS gateway cannot roll back a paid registration, and every message has a durable delivery record for the "I never got my ticket" support case. Channel drivers (Email, SMS, WhatsApp) are provider-agnostic behind one interface.

### ADR-08 — Attendee identity is deduplicated on normalized mobile number
Mobile numbers are stored in E.164 (`+8801XXXXXXXXX`) and are the natural identity key for this audience. Alumni will register twice, misspell their own names, and use three different email addresses; a stable phone-based identity keeps the batch-year reports honest.

### ADR-09 — Tickets are immutable once issued
A ticket is never edited or deleted. Corrections happen by **voiding** and **reissuing**, with `replaces_ticket_id` linking the chain. This keeps the financial and attendance audit trail intact and makes the QR revocation model coherent.

### ADR-10 — T-shirt size belongs to the person, not the registration
A family of four needs four sizes. Sizes live on `attendees` and `registration_guests`, so the production order for the T-shirt vendor is a straight aggregate over people, not a guess derived from registrations.

---

## Scope boundaries for Phase 1

**In scope:** architecture, RBAC, schema, ERD, data flows, security model, scalability plan, roadmap.

**Out of scope, deliberately deferred:**
- API endpoint definitions → Phase 2
- Frontend page and component design → Phase 3
- Gateway sandbox credentials and merchant onboarding → Phase 4 (procurement starts now; lead times in Bangladesh run 2–6 weeks and are the single most common schedule risk on this project)
- WhatsApp Business API template approval → Phase 5 (Meta template review also has multi-day latency; templates must be drafted during Phase 2)

---

## Open questions for the client

These block or reshape parts of the design and should be answered before Phase 2 starts.

1. **Is the event single-session or multi-session?** The schema supports named sessions (gala dinner, cultural night, reunion lunch) with per-session admission. If the event is one continuous day, sessions collapse to a single default row and cost nothing — but multi-session cannot be retrofitted cheaply after ticket sales open.
2. **Are Family tickets fixed-size or variable-price?** Current design: `admits_total` is set per ticket, price derived from a base plus per-head increments. Confirm whether a family of six pays more than a family of three.
3. **Who is the merchant of record** for bKash/Nagad/Rocket — the school, an alumni association, or an individual? This determines settlement account, refund authority, and how long onboarding takes.
4. **What is the refund policy and cutoff date?** Refund tracking is designed; the business rules are not yet specified.
5. **Is a physical wristband or badge issued at the gate?** If yes, the check-in flow gains a badge-print step and the schema needs a `badge_serial` on admissions.
6. **Expected gate count and volunteer device count** on event day. Currently modelled for 6–10 gates and 20–30 devices; this sets the offline manifest size and sync strategy.
