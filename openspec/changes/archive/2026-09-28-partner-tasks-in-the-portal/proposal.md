---
kind: code
depends_on: [portal-task-delivery]
---

# Proposal: partner-tasks-in-the-portal

Competitor gap register, row 3.19 "External (supply chain partner) task
assignment" (`procest/_gaps/gap-register.md` in
ConductionNL/market-intelligence, 2026-09-13). Rated partial, owner
portaliq, size M. Opened by the small-owner lane of the OpenSpec phase.

## Summary

A case handler asks an outside party for something: an advisory body for
an opinion, a contractor for a report, a co-signing municipality for a
decision. The ask reaches that party in the portal, with a deadline, and
the answer lands on the case. Portaliq owns the surface and the delivery;
the engine owns the task; the case app only contributes the ask and reads
the answer.

## Why

dossiq has a token page for external advisory bodies
(`src/manifest.d/consultation-public.json#ExternalConsultationResponse`)
and partner shares (`caseShare`). A token page is a URL in a mail, with no
inbox, no list of what is still open, no identity behind it, and no second
ask on the same token (register note, round 2 B24). The best competitor
in the register: xxllnc Zaken,
`backend/zaken/src/zsnl_domains/case_management/entities/task.py`
(ketenpartner) (`_round2/compare/M1-functionality.md`).

`portal-task-delivery` already renders and delivers a portal task to "the
authenticated party" and drains OpenRegister's delivery ledger. Its
scenarios are written for the resident audience, and its tasks come from
the flow engine. Two things are missing for a partner: the partner
audience on the same surface, and an ask a handler raises by hand from
the case, outside any flow.

## Scope

- The "Mijn taken" surface serves every audience the contribution registry
  knows (`getAudiences()`, contract v2), not only `client`. A partner
  signed in through eHerkenning sees the tasks addressed to their
  organisation.
- A contribution action `ask-partner`, declared by the case app in its
  contribution manifest for the internal side: a handler picks a partner
  (a `portalAccount` of audience `supplier` or `partner`, or an
  organisation not yet in the portal, by KvK number), writes the ask, sets
  a due date and the upload rules, and portaliq creates the engine task
  through OpenRegister's portal-task API as that handler. No flow needed.
- A partner not yet known becomes a pre-provisioned `portalAccount`
  (`portal-identity-space`, this repo) so the ask waits for the first
  login instead of a token.
- The answer: the task completion of `portal-task-delivery` stores the
  comment and uploads on the case object. Nothing new; the case app reads
  them there.
- Overdue: the engine's due date drives a reminder through the same
  delivery ledger (`kind: reminder` rows, already rendered).

## How dossiq consumes it

The register's dossiq half: "contribute the consultation task to the
partner audience; retire ExternalConsultationResponse". dossiq declares
`ask-partner` on its case contribution, places the resulting task list on
the case as the existing portal-task leaf, and retires the token page and
its manifest fragment. One task in dossiq's umbrella
`competitor-parity-2026-09`, against the archived
`move-portals-to-portaliq` provider.

## ADRs

- ADR-046: one shared portal, apps contribute; the audience vocabulary is
  open, so `partner` is a value, not a new contract.
- ADR-108: a citizen-facing or partner-facing surface belongs to portaliq.
- ADR-005 and the invariants of `portal-task-delivery`: the assertion never
  reaches the browser; every read is subject-scoped; fail closed.
- ADR-041: the ask is created through OpenRegister's API as the handler,
  not by an event into dossiq.

## Existing specs it extends

The delta `portal-task-delivery` (the surface, the proxy, the worker) and
`portal-contribution-contract` (multi-audience discovery, endpoint
actions).

## Out of scope

- A partner replying with a structured form. The engine's `answers` object
  exists; the portal sends comment and files today, as
  `portal-task-delivery` already scopes.
- Federation between two Nextcloud instances. A partner that runs its own
  fleet instance is a later change; here the partner logs in to ours.
