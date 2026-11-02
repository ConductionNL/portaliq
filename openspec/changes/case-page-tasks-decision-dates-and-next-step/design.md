# Design: case-page-tasks-decision-dates-and-next-step

## Screens

The page follows the Zuiddrecht board **Zaak** ("Mijn Zuiddrecht: uw zaak", canvas `5NkFW28vZUUij43xzxHg5a`), from top to bottom:

| Board element | Source | Shown when |
|---|---|---|
| Warning banner under the title: "Wij hebben nog stukken van u nodig. Stuur ze voor {due}, dan nemen wij uiterlijk {legal} een besluit." | the open tasks of this case, the earliest due date, and `legalDecisionDate` | at least one open task |
| "Waar staat uw aanvraag?" status steps | case status and `portalStatusLabels` (unchanged) | always |
| Button at the current step, "Stuur de ontbrekende stukken" | `portalStatusActions[status]` | the current status names an action |
| "Volgende stap: besluit", greyed | the next status label | there is a next status |
| Gegevens: "Verwacht besluit", "Uiterlijk klaar op" | `plannedDecisionDate`, `legalDecisionDate` | each row only when its date is set |

With several open tasks the banner lists each task as a link under the sentence. Without `legalDecisionDate` the banner drops the second half of the sentence. The task link opens the task page drawn on **TaakAfronden**.

## Data

`portalCase` (schema.org `Thing`) gains two optional `date` properties: `plannedDecisionDate` (title "Verwacht besluit") and `legalDecisionDate` (title "Uiterlijk klaar op"). Schema version 0.1.0 to 0.2.0.

`portalCaseType` gains `portalStatusActions`: an object keyed by status value. Each entry has `label` (string, required), `kind` (`task`, `page` or `action`) and `target`: for `task` the task type the button opens (the first open task of that type on this case), for `page` a site route, for `action` the id of an action the case contribution declares. Schema version 0.2.0 to 0.3.0.

The case app owns both, like `portalStatusLabels` (ADR-022: the case app writes through OpenRegister; portaliq adds no endpoint).

## Tasks of a case

Tasks come from the contribution's tasks collection, which portal-task-delivery already reads. A tasks collection declares `caseField` (the field holding the case reference). The case page reads that collection filtered on `caseField = <this case>` and the open status, through the existing scoped collection read. A collection without `caseField` adds no tasks to the case page, so no task shows on the wrong case.

## Failure

When the task read fails, the page says "Uw taken konden niet worden geladen." in place of the banner. It never shows "nothing to do" on a failed read.
