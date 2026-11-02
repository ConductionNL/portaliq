---
kind: code
---

# Proposal: case-page-tasks-decision-dates-and-next-step

## Why

A resident opens her case and wants three answers: what do you need from me, by when, and when do I hear back. The case page gives none of them today. Open tasks live on Mijn taken and the overview, never on the case they belong to. The case card shows one "klaar uiterlijk" date, so a planned date and the legal latest date look the same. The current status step says where the case is, but never what to do next.

Open Inwoner shows the planned and the legal decision date (`src/open_inwoner/cms/cases/views/status.py:117`, `:118`) and a call to action per status type (`src/open_inwoner/openzaak/models.py:918`). NL Portal lists a case's tasks on its own page (`CaseDetailsPage.tsx:57`). The Zuiddrecht board **Zaak** draws all three on one page.

## What changes

- **Tasks on the case.** The case page lists the open tasks whose case reference is this case, above the status steps, in the warning-toned banner the board draws: "Wij hebben nog stukken van u nodig. Stuur ze voor 18 oktober, dan nemen wij uiterlijk 1 november een besluit." Each task links to its task page (board **TaakAfronden**).
- **Two decision dates.** `portalCase` gains `plannedDecisionDate` and `legalDecisionDate`, written by the case app. Gegevens shows "Verwacht besluit" and "Uiterlijk klaar op". With only one date, only that row shows.
- **A next step on the current status.** `portalCaseType.portalStatusActions` names, per status, a button label and where it leads: a task, a page or one of the case's actions. The current step shows the button ("Stuur de ontbrekende stukken"); the next step stays grey ("Volgende stap: besluit").

## Rows covered

- `cas-tasks-on-case`, `cas-expected-decision-date`, `cas-status-call-to-action` (added with this change, decision 101).

## Out of scope

- Writing tasks or dates. The case app (dossiq) owns both; portaliq renders what the case app writes.
- A task list per case for staff. Staff see tasks in the case app.
