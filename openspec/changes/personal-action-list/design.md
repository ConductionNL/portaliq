# Design: personal-action-list

## Screens

The dialog follows the Zuiddrecht board **ActieBewerken** ("Mijn Zuiddrecht: actie bewerken", canvas `5NkFW28vZUUij43xzxHg5a`). The board places the action table and dialog inside the plan "Schuldhulp op orde"; this change uses the table and the dialog on their own page "Mijn acties", without the plan's goal, notes and participants.

| Board element | Here |
|---|---|
| Table "Acties": Actie (title, kind), Status, Uiterlijk (with "nog 6 dagen"), Wie (initials, name), Bewerken | the Mijn acties table |
| "Actie toevoegen", "2 van 5 klaar" | above the table |
| Dialog "Actie bewerken" with the fields listed in the proposal | `src/site/modals/ActionEditModal.vue` (ADR-004) |
| Geschiedenis lines "6 oktober 2026, 14:02 Sanne de Vries: Status van Te doen naar Bezig" | from the action's audit trail |

## Data

New schema `portalAction` (schema.org `Action`): `owner` (subjectRef), `assignee` (subjectRef, the owner or an approved contact), `title`, `description`, `kind` (`once`, `recurring`), `status` (`todo`, `doing`, `done`), `endDate` (date), `file` (an OpenRegister file reference), `reminderSentAt`.

History is OpenRegister's audit trail of the object (ADR-022: no own history table). The dialog reads it and shows field, old value, new value, who and when.

## Access

Read and write go through portaliq's own contribution with `scopeField: owner`, plus a second read scope on `assignee`. An assignee who is not the owner may change status, file and description, not the assignee or delete.

## Reminder

A daily background job finds open actions with `endDate` three days ahead and no `reminderSentAt`, sends the notice through the notification path (template `action-due`, listed in the mail templates screen), and sets `reminderSentAt`.
