---
kind: code
depends_on: [own-contacts-and-invitations]
---

# Proposal: personal-action-list

## Why

Sanne has to upload three months of bank statements by 14 October, and book an appointment with a budget coach. Nobody at the municipality asked her through a case task; these are her own steps, agreed with her caseworker. Today she keeps them on paper.

Open Inwoner gives every resident a to-do list of actions with status, end date, file and reminder (`src/open_inwoner/accounts/views/actions.py:61`, `:223`; reminders `notifications/actions/notify.py:15`). The Zuiddrecht board **ActieBewerken** draws the action dialog: title, description, kind, status, end date, assignee, one file and the history.

## What changes

- **Mijn acties.** A page under Mijn Zuiddrecht lists the resident's own actions with columns Actie, Status, Uiterlijk, Wie, and an edit pencil per row. "Actie toevoegen" opens the same dialog empty.
- **The action dialog follows the board.** Titel, Omschrijving (niet verplicht), Soort (Eenmalig, Terugkerend), Status (Te doen, Bezig, Klaar), Uiterlijk klaar op, Toegewezen aan, one Bestand (PDF, JPG or PNG, at most 10 MB), Geschiedenis, Opslaan, Annuleren, Actie verwijderen.
- **Shared with a contact.** An action can be assigned to an approved contact (own-contacts-and-invitations); that contact sees and edits only that action, and the history names who changed what.
- **A reminder.** Three days before the end date of an open action the assignee gets a portal notification and a mail ("Uw actie {actie} loopt bijna af").

## Rows covered

- `tsk-personal-actions` (decision 101), screen ActieBewerken.

## Depends on

- `own-contacts-and-invitations` for assigning an action to someone else. Without it, actions are the resident's own only.

## Out of scope

- Plans with goals and participants. The board draws the dialog inside a plan; plans are `cmp-tsk-plan`, decided-no.
- Case tasks. Tasks the organisation asks for stay in portal-task-delivery.
