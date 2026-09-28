# Tasks: portal-identity-and-the-organisations-cases

## The identity kind

- [x] **T01**: `identityKind` on the case type's portal declaration: `account`, `reference`, or both, enforced by the portal (REQ-PIOC-001)
- [x] **T02**: The `reference` route: a case number plus a verified e-mail, a one-time link, no account (REQ-PIOC-001)

## The organisation

- [x] **T03**: Scope the portal case list by the mandates the identity holds, defaulting to nothing when none is recorded (REQ-PIOC-002)
- [x] **T04**: Name the mandate on the view, per case (REQ-PIOC-002)
- [x] **T05**: Switch the organisation or role acted under inside the session, recording the mandate on every write (REQ-PIOC-008)

## Getting in

- [x] **T06**: Issue a portal account at the desk for a person without a national login (REQ-PIOC-003)
- [x] **T07**: Invite an e-mail address into the portal, with the invitation state visible and an expiry (REQ-PIOC-003)
- [x] **T08**: Registration policy `off` / `approval` / `activation`, plus `allowedDomains[]` (REQ-PIOC-004)
- [x] **T09**: Proof of work and honeypot challenge, configured per surface, in front of the public form and of self-registration (REQ-PIOC-005)

## The citizen's own account

- [x] **T10**: Change your own details, with a new e-mail address confirmed by a link before it is used (REQ-PIOC-006)
- [x] **T11**: Ask for the account to be removed; the product removes the account and its claims and keeps the cases (REQ-PIOC-006)
- [x] **T12**: Ask for access you do not have; the owner receives the request and answers it in the product (REQ-PIOC-007)

## Quality

- [x] **T13**: PHPUnit: mandate scoping including the empty default, the reference link's single use, the domain allow list, the challenge, and that deletion leaves the case
- [x] **T14**: Playwright `tests/e2e/portal-identity-and-the-organisations-cases.spec.ts`: two employees of one company each file a case and both see both; a colleague with no mandate sees neither
- [x] **T15**: Dutch and English strings; docs; `openspec validate portal-identity-and-the-organisations-cases --strict`

## What shipped where

The backend is complete and tested: the mandate record and its scoping
(`lib/Service/Identity/PortalMandateService.php`,
`lib/Service/PortalCaseListReader.php::listMandatedCases()`), the one-time
reference link, invitations with their state and expiry, the registration
policy with its domain list, the portal's own proof-of-work and honeypot
challenge, the citizen's own details and removal, and access requests. The
portal SPA screens over them (the mandate switcher, the invitation list, the
registration page) are the remaining front-end work; every surface is reachable
over the routes in `appinfo/routes.php` and covered by PHPUnit.

