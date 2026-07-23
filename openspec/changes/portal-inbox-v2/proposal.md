# Proposal: portal-inbox-v2 (one unified, mark-readable inbox across contributions)

## Why

A single inbox across organisations is a top-tier citizen wish: *"ONE
inbox/overview across organisations"* (Logius zaakgericht-werken-op-mijnoverheid,
rated HIGH by agent E). Today the portal inbox is, per agent A, *"PARTIAL —
`kind:inbox` collection over `portalMessage` read-only; NO mark-read, NO
cross-app aggregation."* Each contributing app that declares a `kind: inbox`
collection surfaces its own messages in isolation; a subject with messages from
procest, pipelinq, and decidesk sees three disconnected lists and cannot mark any
message read.

Demand is quantified: `berichtenbox` (message inbox) appears in **161 tender
requirements across 74 tenders**. The Berichtenbox pattern citizens already know
(MijnOverheid) is a *single* merged inbox with read state — anything less reads as
a regression from what they expect.

WMEBV also puts content-shape requirements on notifications: art 2:10 (deferred
to **2027**) requires a notification to state its **aard** (nature),
**rechtsgevolg** (legal effect), and **termijn** (deadline). This change makes the
inbox *ready* to render those fields when an app supplies them, without waiting
for 2027.

## What Changes

- **`GET /portal/api/inbox`** — a unified inbox endpoint that aggregates every
  `kind: inbox` collection across ALL the subject's contributions, subject-scoped
  with the SAME per-row tenant + trust verification as a normal collection read.
  Rows are merged and sorted by `receivedAt` descending, each tagged with its
  source `appId` and label so the SPA can show provenance.
- **`PATCH /portal/api/inbox/{register}/{schema}/{id}/read`** — marks a message
  `read: true`. It may only touch rows the subject owns (the same ownership +
  tenant + trust re-verification as the scoped update), and may only change the
  `read` field — tamper-proof, no other field writable, no existence oracle.
- **Unread count** included in the `GET /portal/api/contributions` response so the
  shell can show a badge without a second round-trip.
- **Optional message metadata** — `aard`, `rechtsgevolg`, `termijn` — rendered in
  the SPA when an app supplies them on a message (WMEBV art 2:10 readiness for
  2027). Absent fields render nothing; no app is forced to supply them now.
- **SPA** — an inbox page with unread badges and a read-state toggle.

## Out of scope

- Sending / composing messages from the portal (reply-in-thread) — a later slice.
- Making the 2:10 metadata mandatory — this is readiness only; population is the
  contributing app's choice.
- The out-of-band email nudge — that is `portal-notifications-dispatch`.

## Dependencies

- Builds on the existing `kind: inbox` collection vocabulary, the scoped read /
  update boundary (`portal-scoped-crud`), and the `portalMessage` schema
  (`read`, `receivedAt` already present). Additive; the per-app inbox collections
  keep working unchanged.
