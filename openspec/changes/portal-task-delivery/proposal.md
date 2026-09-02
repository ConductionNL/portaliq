# Proposal: portal-task-delivery (the resident task leg, portaliq's half)

## Why

OpenRegister's flow engine can now ask a party OUTSIDE the instance to act on a
case: `feature/flow-portal-task` (openregister#3282) ships subject-scoped portal
task reads, a completion endpoint that stores uploads on the case object, and a
delivery ledger (`openregister_portal_deliveries`) with one row per channel
(`portal-inbox` / `mail`) per ask. OpenRegister deliberately SENDS NOTHING:
"an external task is never delivered through a Nextcloud channel" is structural
on that side. Somebody has to render and send. That somebody is portaliq
(ADR-046: one shared external portal, apps hook in; ADR-108: citizen-facing
surfaces belong to portaliq).

Without this change the engine half is a ledger nobody drains and an API nobody
calls: a task addressed to a resident exists, and the resident never learns of
it and has no place to complete it.

## What

Portaliq's half of the seam, in three pieces:

1. **The "Mijn taken" portal surface.** The public portal SPA gets a fixed,
   cross-app "Mijn taken" page (Dutch-first, B1): the authenticated party's open
   portal tasks, a detail view (title, description, due date, upload rules), and
   a completion form honouring the task's frozen upload constraints. Every read
   and the completion go through a portaliq backend proxy: portaliq resolves the
   bearer session, mints the short-lived `X-Portal-Subject` assertion SERVER-SIDE
   (the resident's browser never holds it), and forwards server-to-server to
   `/apps/openregister/api/portal-tasks[...]`. Refusals come back in plain
   language.
2. **The delivery worker.** A recurring background job drains
   `PortalTaskDeliveryService::pending()` in-process (openregister is
   co-installed in portaliq's normal shape, ADR-046). A `portal-inbox` row
   becomes a `portalMessage` in the party's own unified inbox; a `mail` row
   becomes a privacy-minimal email through portaliq's existing mail path
   (portalAccount lookup + IMailer, the NotificationDispatchJob conventions).
   Each row is settled with `markDelivered()` / `markFailed(reason)`;
   idempotent per row; one row's failure never blocks the rest.
3. **The deep link.** The inbox message carries the task uuid, and the unified
   inbox renders a "Bekijk taak" action that opens the task's detail on the
   "Mijn taken" page.

## The security invariants

- The assertion secret stays server-side (`portaliq/jwt_signing_secret`, the
  same dedicated secret sessions already require; openregister verifies with
  its `portal_assertion_secret` falling back to that same value). No response
  ever carries the assertion; the browser only ever holds its bearer session.
- No CORS, no direct browser call to openregister: the proxy is the only path.
- Fail-closed: no bearer session means 401 and an empty surface; an unmatched
  party gets openregister's unrevealing 404 passed through, never a hint that
  the task exists.
- The worker adds no read authority: it writes a message into the party's OWN
  inbox scope (`subjectRef` stamped server-side by PortalObjectWriter) and
  mails the party's OWN portalAccount address, nothing else.

## Out of scope

- The REST admin fallback consumer (`GET /apps/openregister/api/portal-tasks/
  deliveries` + settle routes) for a split-instance deployment where
  openregister is NOT co-installed. The seam exists and is documented in
  design.md; portaliq's normal shape is co-installed, so only the in-process
  consumer is built (the brief: document, do not build both).
- Answer-field forms beyond comment + upload + outcome. The engine accepts an
  `answers` object; the portal sends the comment and files today, and a
  schema-driven answer form is a follow-up once a flow actually declares one.
- Reminder scheduling. `kind: reminder` rows are rendered like asks; WHEN a
  reminder is requested stays the engine's decision.
