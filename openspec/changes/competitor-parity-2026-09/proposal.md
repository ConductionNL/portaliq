---
kind: umbrella
depends_on: []
---

# Proposal: competitor-parity-2026-09

The portaliq half of the OpenSpec phase of the competitor parity programme.
Source of record: the gap register at `procest/_gaps/` in
ConductionNL/market-intelligence (`README.md`, `gap-register.md`,
`gap-register.json`, `ownership-rules.md`, written 2026-09-13). Ruben's rule,
in the ownership rules: dossiq reaches 100% comparability with the
competition, and logic that belongs to another app is specified in that app;
dossiq consumes it. portaliq is one of those owner apps, so this umbrella
indexes the logic portaliq owes.

The register puts six rows on portaliq. Four have a change in this repo. Two
need none, and the last section says why. Nothing here is implemented: every
change below carries its own `proposal.md`, `design.md`, `specs/` and
`tasks.md`.

## The changes

Sizes are the register's. The first three proposals state the same size
themselves; `change-proposal-queue` states none, so its M is the register's
rating.

| change | rows | size | dossiq consumer |
|---|---|---|---|
| `partner-tasks-in-the-portal` | 3.19 | M | contribute the consultation task to the partner audience, and retire `ExternalConsultationResponse` and its manifest fragment |
| `portal-identity-space` | Q1.14 | M | scope the portal provider's `cases` collection by a requester claim, and dispatch the provision and claim events at intake; the token share stays the anonymous fallback |
| `embedded-intake-form` | Q1.16 | M | nothing beyond the intake binding it already has; a submission from an embed arrives through the same contribution contract |
| `change-proposal-queue` | 2.21 | M | the accept and reject actions, and the field write |

`change-proposal-queue` predates the register. It cites round 2 finding B24
rather than `procest/_gaps/`, and the register's `covered` column names it as
already on `development` when the register was written. It is listed here
because it closes a register row portaliq owns.

## Build order

Two changes can start now. Two wait on a portaliq change that is still open.

1. `portal-identity-space`. It extends `portal-contribution-contract` and
   `supplier-portal`, both on `development`, and nothing open blocks it. It
   also unblocks the partner half, so it goes first.
2. `change-proposal-queue`. No open dependency.
3. `partner-tasks-in-the-portal`. Its front matter waits on
   `portal-task-delivery`, open in this repo. Its scope also reads on
   `portal-identity-space`: a partner who is not yet in the portal becomes a
   pre-provisioned `portalAccount` rather than a token.
4. `embedded-intake-form`. It waits on `portal-shared-runtime` and
   `portal-headless-content-api`, both open in this repo.

## Halves another app carries

Two portaliq rows in the register have no portaliq change, and both are
right.

- **1.1, a citizen web form per case type.** The register's slug reads "none
  needed". The Forms half is dossiq's intake binding
  (`caseType.intakeFormRef` and `FormsIntakeService` in dossiq's
  `leaf-integrations`); the portal half is buildiq's journey designer. The
  portal journey targets the same intake, so portaliq owes no mechanism here.
- **6.7, messaging with citizens through a portal.** The register's slug
  reads "none needed (re-rate)". The citizen inbox is portaliq's and shipped
  with the archived `2026-07-23-portal-inbox-v2`; dossiq contributes
  `portaalBericht` to the citizen audience, and its `move-portals-to-portaliq`
  was archived on 2026-09-09 with one task open. The row is a re-rate, not
  work.
