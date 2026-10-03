---
kind: code
depends_on: [portal-intake-form-as-an-object, partner-tasks-in-the-portal]
---

# Proposal: what-the-citizen-may-write-on-their-own-case

Round 4 discovery sweep, cluster 48 "What the citizen may write on their
own case" (`procest/_round4/discovery/build-plan.md` in
ConductionNL/market-intelligence, 2026-09-14). Owner portaliq, size M,
decision D16, depends on the intake form as its own object and on CT-6.
Candidates: `C-intake-20`, `C-intake-40`, `C-documents-29`,
`C-access-and-privacy-41`, `C-tasks-and-phases-12` (`candidates.json`,
lane lines `intake.tsv:16`, `:29`, `documents.tsv:46`,
`access-and-privacy.tsv:68`, `tasks-and-phases.tsv:40`). Ledger row 1.14,
the Awb 4:5 request to complete a submission, is named in the candidate
notes.

## Summary

Today the portal is read plus a message. A citizen who left something out
phones the desk. This change gives them the three acts they actually
need: correct the request, add the missing document, and answer the
question the case worker asked. What they may touch is declared on the
case type, never decided by the portal.

## Why

dossiq rates all five `no`, and its own note says why: "portal access is
read plus message". The lane's clauses name the statutory shape. An
aanvulling op de aanvraag stops the Awb 4:5 clock, and it is the
commonest citizen act there is. A citizen correcting their own aanvraag
is both a service improvement and an Awb 4:5 shortcut, and nothing in the
corpus lets them.

D16, as Ruben answered it: the portal flag lives on the field, and the
form owns order and channel. So the writable set is not a portaliq
setting. It is the same field flag the intake form reads, used on a
running case.

## The passers that prove it

Five systems pass a member, four driven and one documented. Proving
system: itop.

| candidate | relevance | driven | documented | evidence the lane cited |
|---|---|---|---|---|
| `C-tasks-and-phases-12` | must | valtimo | | Portaaltaak (`zgw-integration/spec.md`, `task-management/spec.md`, `D-valtimo-19`) |
| `C-documents-29` | must | xxllnc-zaken | | PIP (`citizen-portal/spec.md`, `D-xxllnc-95`) |
| `C-intake-40` | must | | visma-circle | `/software/online-dienstverlening` (`D-visma-14`) |
| `C-intake-20` | should | itop | | `TriggerOnPortalUpdate` (`D-itop-2`) |
| `C-access-and-privacy-41` | should | odoo | | `project_collaborator.py` with an edit flag (`journeys.md` 5, `D-odoo-17`) |

`C-intake-40` has no driven passer. Under Ruben's answer to D21,
documented-only candidates are admitted and labelled, so it is in scope
and marked here as documented.

## What portaliq builds

- **A writable set that comes from the case type.** The portal renders
  what the field flags allow for this audience, on this case, in this
  status. It keeps no list.
- **Amending a submitted request**, until the case type says the window
  has closed, with the change recorded as the citizen's.
- **Adding a document to a running case** from the portal, through the
  file surface the case app already declares.
- **A task published to the citizen**, answered in the portal, with the
  answer returned to the process that raised it.
- **A citizen write as its own event**, distinct from a staff write, so a
  rule can fire on "de indiener heeft gereageerd" without guessing.
- **The citizen's status vocabulary**, rendered from the public label the
  contribution supplies.

## How dossiq consumes it

dossiq declares which fields a citizen may change and until when, raises a
citizen task through the contribution, and listens for the portal write
event to run its own rules. It renders none of this. The status labels are
dossiq's too: `citizen-status-labels` (row Q6.19, pending) adds
`statusType.publicLabel` and hands it to the portal. Portaliq renders that
label and does not fall back to a vocabulary of its own.

## Existing specs it extends

`partner-tasks-in-the-portal` (portaliq#535), which delivers a task to an
outside party and returns the answer to the case. This change adds the
`client` audience to it. It also extends `portal-contribution-contract`
for the write path and the events, and the sibling change
`portal-intake-form-as-an-object` for the form the amendment is rendered
from.

## ADRs

- ADR-046: the case app declares, portaliq serves. The writable set is a
  declaration.
- ADR-041: the portal write reaches the case app as a typed event.
- ADR-082: a citizen-facing write surface is throttled.
- ADR-085: the amendment is rendered from the same form grammar as the
  intake.

## Out of scope

- The field flag itself. D16 puts it on the field, in the record type.
- The internal status vocabulary and its public label. dossiq owns both,
  through `citizen-status-labels`.
- Partner and supplier writes. `partner-tasks-in-the-portal` and
  `supplier-portal` already carry those audiences.
