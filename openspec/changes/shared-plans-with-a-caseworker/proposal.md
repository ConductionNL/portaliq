---
kind: code
depends_on: [personal-action-list, own-contacts-and-invitations]
---

# Proposal: shared-plans-with-a-caseworker

## Why

Sanne and her caseworker Mark agreed on a goal: in three months she has an overview of all her debts and a fixed repayment arrangement. They agreed five steps to get there, with dates and who does what. Today that plan lives in Mark's notes and on a sheet of paper in Sanne's drawer. When Mark uploads a new budget plan, Sanne does not know. When the end date comes near, nobody is told.

Open Inwoner offers shared plans with a goal, actions, files and participants (`src/open_inwoner/plans/views.py:192` PlanDetailView, `:429` PlanGoalEditView, `:575` PlanActionCreateView). Three Zuiddrecht boards draw portaliq's version: **Plannen** (the list and "Een nieuw plan starten" from a template), **Plan** (one plan with goal, actions, notes, files with versions, participants and the end date) and **ActieBewerken** (the action dialog inside the plan).

Decision 105 (8 October 2026) reopened `cmp-tsk-plan`, decided no on 28 September, because the boards draw it.

## What changes

- **Samenwerken.** A page under Mijn Zuiddrecht lists the resident's plans with counts Lopend, Actie vereist and Afgerond, and one card per plan with goal, end date, open actions, who shared it, participants and progress.
- **A new plan from a template.** "Nieuw plan" offers "Leeg plan" and the templates the municipality sets up (goal, first actions, duration). The resident picks participants from approved contacts.
- **One plan page.** Goal with "Doel aanpassen", the action table of `personal-action-list` scoped to the plan, notes with author and date, files with versions, participants with "Deelnemer toevoegen" and "Stuur {naam} een bericht", the end date, and "Download als PDF".
- **The end date is watched.** Fourteen days before the end date of a plan with open actions, the plan shows "Actie vereist" and an alert, and every participant gets one mail and one portal notification.
- **Templates for staff.** An editor manages plan templates in the admin.

## Rows covered

- `cmp-tsk-plan` (reopened by decision 105), screens Plannen, Plan, ActieBewerken.

## Depends on

- `personal-action-list` for the action table, the dialog and the reminder job. This change adds `plan` to `portalAction`.
- `own-contacts-and-invitations` for participants: only approved contacts can be added, and a caseworker is a Begeleider there.

## Out of scope

- Plans a caseworker starts from the back office. The caseworker works in the portal as a participant.
- Linking a plan to a case. A plan stands on its own.
