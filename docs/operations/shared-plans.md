---
title: Shared plans with a caseworker
sidebar_label: Shared plans
---

# Shared plans with a caseworker

A resident and their contacts work on a plan together: a goal, actions with dates and people, a note and an end date. Everyone in a plan sees the same thing. The page is **Samenwerken** in Mijn omgeving.

## Switch it on

Set `plansEnabled` to true on the portal record. It needs `contactsEnabled`, because participants are chosen from the resident's approved contacts. Residents of that portal then get **Samenwerken** in their menu at `/mijn/samenwerken`.

## Templates

A template is a `portalPlanTemplate` object: `portal`, `title`, `summary`, `goal`, `goalDetail`, `durationDays` and `actions` (each with `title`, `kind` and `offsetDays`). Only a template with `published: true` is offered. Starting a plan from a template fills in the goal, creates the actions with end dates counted from today (at most 20) and sets the end date `durationDays` after today. A resident can also start an empty plan with their own name.

There is no admin screen for templates yet: they are objects in the `portaliq` register.

## Who may do what

| | Owner | Participant |
|---|---|---|
| See the plan, its actions and note | yes | yes |
| Change the goal and its explanation | yes | yes |
| Add and change actions, edit the note | yes | yes |
| Change the title and the end date | yes | no |
| Add and remove participants | yes | no |
| Mark the plan done, delete it | yes | no |

A participant is always an approved contact of the owner; adding anyone else is refused. The owner cannot be removed. A done plan is read-only and is hidden from the list a year after it was marked done. A resident who is not in a plan gets the same answer for it as for a plan that does not exist.

## The end date

In the last 14 days before the end date, a running plan with open actions shows **Actie vereist** and an alert with the date and the number of open actions. The daily job sends every participant one message in their portal inbox (rule `plan-ending`) and records it on the plan. Changing the end date clears the record, so the new date earns a new reminder.

## PDF

**Download als PDF** gives every participant a PDF with the goal, end date, participants, actions and the note. OpenRegister renders it; without OpenRegister's renderer the route answers 503.

## Routes

All need a portal session: `GET/POST /portal/api/plans`, `GET /portal/api/plans/templates`, `GET/PATCH/DELETE /portal/api/plans/{id}`, `GET /portal/api/plans/{id}/pdf`, `POST /portal/api/plans/{id}/participants`, `DELETE /portal/api/plans/{id}/participants/{ref}`, `POST /portal/api/plans/{id}/actions` and `PATCH /portal/api/plans/{id}/actions/{actionId}`.

## Not built yet

- Files with versions on a plan.
- The mail for the end reminder (only the portal message goes), and the `plan-ending` template in the mail templates screen.
- The action dialog of the personal action list: actions are changed in the plan page itself.
- "Stuur een bericht" to a participant.
- An admin screen for templates.
