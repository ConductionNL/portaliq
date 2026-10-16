# Proposal: a-task-opens-the-app-action-that-completes-it

## Summary

"Mijn taken" opens the app action a task names (`metadata.completeVia` =
`{app, action}`) instead of the generic upload form, with the task uuid preset
in the action's `taskField` (default `taskId`). The answers go through the
portal's own endpoint forward (`POST /portal/api/actions/{app}/{action}`), so
the app runs its own checks and completes the task.

## Why

The page completed every task straight through OpenRegister's portal seam,
which skips an app's own checks: a dossiq checklist task must be checked
against the checklist frozen on the task. OpenRegister now refuses a direct
completion of such a task (openregister#4569, 409 `complete-via-app`); this
change is the portal half, so the resident lands on the right form instead of
on a refusal. It is generic (decision 182): any app that completes its own
tasks names its action, and portaliq knows no app by name.

## What changes

- `completeViaAction(task, aggregate)` in `src/site/pages/inbox/tasks.js`:
  the named endpoint action from the subject's OWN aggregate, or null when the
  subject may not run it.
- `TasksPage.vue` renders that action's `SchemaForm` with the task uuid preset
  and sends through `forwardAction`; a named action the subject may not run
  shows a sentence and no upload form.
- `refusalKey('complete-via-app')` reads as a sentence.
- No server change: the proxy already relays a seam 409 with its body.
