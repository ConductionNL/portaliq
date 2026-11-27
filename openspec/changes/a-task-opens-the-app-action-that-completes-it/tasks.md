# Tasks: a-task-opens-the-app-action-that-completes-it

- [x] 1.1 `completeViaAction` resolves the named endpoint action from the subject's own aggregate (`tests/tasks-page.spec.mjs`)
- [x] 1.2 `TasksPage.vue` renders the action's form with the task uuid preset and forwards it (`tests/tasks-page.spec.mjs`)
- [x] 1.3 A named action the subject may not run shows a sentence, no upload form (`tests/tasks-page.spec.mjs`)
- [x] 1.4 `refusalKey('complete-via-app')` (`tests/tasks-page.spec.mjs`)
- [ ] 2.1 Live: an external inspector completes a dossiq checklist task from "Mijn taken" (live pass, decision 139)
  - Run 25 Nov 2026 on a sandbox Nextcloud 34.0.6 (MariaDB) with dossiq development and the Zuiddrecht example set: dossiq's `POST /api/cases/{id}/checklist-runs/external` made an external task for inspector `pieter` with `completeVia` dossiq `completeChecklistTask`; in an inspector session "Mijn taken" lists it and opening it shows dossiq's "Checklist invullen" form with the task preset. Fixed here: the forward dropped the action's `taskField` because the declared `fields` whitelist did not name it, so dossiq answered "taskId is required"; with the fix the same route completes the task (state completed, outcome conform, by `party:pieter`).
  - Still open: the form renders `responses` as one text field, while dossiq takes a list of answers per checklist item (`itemId`, `value`/`numericValue`), so an inspector cannot fill it from the page; this needs a checklist field (dossiq to describe the items, portaliq to render them).
