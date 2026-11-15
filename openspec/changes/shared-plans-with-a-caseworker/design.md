# Design: shared-plans-with-a-caseworker

## Screens

Canvas `5NkFW28vZUUij43xzxHg5a`.

### Plannen (`/mijn/samenwerken`)

| Board element | Here |
|---|---|
| Heading "Samenwerken", "Nieuw plan", intro "Plannen die u samen met uw begeleider of uw contacten maakt. Iedereen in een plan ziet het doel, de acties en de bestanden, en kan acties afvinken." | page head |
| Filter "Uw plannen": Lopend 2, Actie vereist 1, Afgerond 1; "2 lopende plannen. U maakte er 1 zelf, 1 is met u gedeeld." | counts from the resident's plans |
| Card: title, tag "Actie vereist" or "Lopend", "Loopt over 12 dagen af", "Doel:", Einddatum, "Open acties 3, waarvan 1 voor u deze week", "Gedeeld: Door u gemaakt" or "Door Linda Smit met u gedeeld", initials and names of participants, "2 van 5 acties klaar" | one card per plan |
| Dialog "Een nieuw plan starten": "Waar wilt u mee beginnen?" with "Leeg plan" and templates ("Vult het doel in en zet 5 acties klaar ... Einddatum na 8 weken."), "Met wie maakt u dit plan? (niet verplicht)", approved contacts as checkboxes, "Plan starten" | `src/site/modals/PlanStartModal.vue` (ADR-004) |
| "De voorbeelden zet de gemeente klaar. Een afgerond plan blijft een jaar zichtbaar onder "Afgerond"." | footnote |

### Plan (`/mijn/samenwerken/{plan}`)

| Board element | Here |
|---|---|
| Title, "Download als PDF", "Plan bewerken", tag, "Gemaakt door u op 1 september 2026 · Gedeeld met Mark Jansen · Laatst gewijzigd 6 oktober door Mark Jansen" | head; last change from the audit trail |
| Alert "Dit plan loopt over 12 dagen af, op 20 oktober 2026" with "Er staan nog 3 acties open. Verleng het plan met uw begeleider, of rond de acties af. U krijgt hier ook een e-mail over." | shown in the last 14 days with open actions |
| "Doel" with "Doel aanpassen" and the goal and its explanation | `goal`, `goalDetail` |
| "Acties", "Actie toevoegen", "2 van 5 klaar. Verander de status van een actie met het potlood.", the table with caption "Acties in het plan Schuldhulp op orde" | the action table of `personal-action-list`, filtered on `plan` |
| "Notities", "Notitie aanpassen", text, "Mark Jansen, 6 oktober 2026" | one shared note per plan, last editor and date |
| "Bestanden", "Bestand toevoegen", "Versie 2, nieuwste", "Nieuwe versie uploaden", "Eerdere versies (1)", "Een nieuwe versie vervangt het bestand niet. De vorige versie blijft hieronder staan." | plan files with versions |
| "Deelnemers": maker "U, maker van het plan", "Begeleider, Gemeente Zuiddrecht", "Deelnemer toevoegen", "Stuur Mark een bericht" | `participants`; the message link opens Berichten addressed to that contact |
| "Einddatum 20 oktober 2026", "Loopt over 12 dagen af", "U krijgt een e-mail en een melding op uw overzicht als het plan of een actie bijna afloopt.", "Meldingen instellen" | `endDate`; the link goes to the resident's notification settings |
| "Plan bewaren", "Download het plan met doel, acties en notities als PDF, bijvoorbeeld voor een gesprek." | PDF |

### ActieBewerken

The dialog of `personal-action-list` opened from the plan. Its lead reads "In het plan {plan}. Wijzigingen ziet {deelnemers} ook." and "Toegewezen aan" lists the plan's participants.

## Data

New schemas in `lib/Settings/portaliq_register.json` (ADR-022: objects in OpenRegister, history from its audit trail, files as OpenRegister files):

- `portalPlan` (schema.org `Project`): `owner`, `participants` (subject refs, each an approved contact of the owner), `title`, `goal`, `goalDetail`, `endDate`, `status` (`running`, `done`), `template`, `note` (`text`, `editedBy`, `editedAt`), `doneAt`, `endReminderSentAt`.
- `portalPlanTemplate`: `title`, `summary`, `goal`, `goalDetail`, `durationDays`, `actions` (`title`, `kind`, `offsetDays`), `portal`, `published`.
- `portalAction` gains `plan` (optional reference). An action with a plan is visible to every participant of that plan.

A file added to a plan is an OpenRegister file on the plan object. A new version is a new file linked to the earlier one through `previousVersion`; nothing is overwritten.

## Access

Read and write go through portaliq's own contribution scoped on `owner` and on `participants`. Every participant may add and edit actions, the note and files, and change the goal. Only the owner may add or remove participants, set the end date, mark the plan done or delete it. A done plan is read-only and is hidden from the list one year after `doneAt`.

## Reminder

The daily job of `personal-action-list` also finds running plans whose `endDate` is 14 days ahead or less, with open actions and no `endReminderSentAt`, and sends every participant one notification and one mail (template `plan-ending`), then sets `endReminderSentAt`. Changing the end date clears it.

## PDF

The PDF holds the title, goal, end date, participants, the action table and the note, rendered on the server with the portal's house style. Files are listed by name, not embedded.
