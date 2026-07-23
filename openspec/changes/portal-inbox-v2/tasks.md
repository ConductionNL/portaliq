# Tasks: portal-inbox-v2

> Unified `GET /portal/api/inbox` aggregation, tamper-proof mark-read, unread
> count on contributions, and 2:10-readiness metadata in the SPA.
> Implementation-ordered; small and verifiable.

## Implementation Tasks

- [ ] T01 — Add an inbox aggregation method to the reader layer (`lib/Service/PortalObjectReader.php` / a new `PortalInboxReader`): iterate every `kind: inbox` collection in the subject's contributions, read each subject-scoped with the existing per-row tenant + trust boundary, merge, sort by `receivedAt` desc, tag each row with `appId`/label.
- [ ] T02 — Add `GET /portal/api/inbox` route in `appinfo/routes.php` (before the `/portal/{path}` catch-all) and a `ContributionController::inbox()` method returning the aggregated inbox; fail closed to empty.
- [ ] T03 — Add `PATCH /portal/api/inbox/{register}/{schema}/{id}/read` route + `ContributionController::markRead()`: re-verify ownership/tenant/trust before any write (reuse the scoped-update boundary), set ONLY `read: true`, identical 404 on foreign/absent id, no existence oracle.
- [ ] T04 — Add the subject unread count to `ContributionController::index()` (the `/portal/api/contributions` payload), computed within the same scoped pass.
- [ ] T05 — SPA: add an inbox page component with unread badges and a read-state toggle, calling the new endpoints via `src/portal/lib/portalApi.js`; wire it into `src/portal/App.jsx` navigation.
- [ ] T06 — SPA: render optional `aard`/`rechtsgevolg`/`termijn` on a message when present (nothing when absent); add NL/EN labels via i18n.
- [ ] T07 — Unit test the aggregation: multi-app merge, `receivedAt` sort, provenance tags, per-row trust/tenant drop, fail-closed empty on OR error.
- [ ] T08 — Unit test mark-read: own message sets only `read`; foreign/absent id 404 with save never called; extra body fields ignored.
- [ ] T09 — Unit test the unread count on the contributions payload (own unread only).
- [ ] T10 — Add Playwright e2e: open the inbox, see merged provenance-tagged rows + unread badge, toggle read state, and see 2:10 metadata render when present (closes the `@e2e exclude` markers on the inbox scenarios).
- [ ] T11 — Document the inbox endpoints, mark-read, unread count, and 2:10-readiness metadata in `README.md`; add the requirements to the canonical `openspec/specs/supplier-portal` spec on sync.
- [ ] T12 — Run `composer check:strict` and `npm run lint` green; run Hydra gates (route-auth, route-reachability, spec-coverage, e2e-coverage, no-admin-idor).
