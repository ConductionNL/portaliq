## ADDED Requirements

### Requirement: A task that names its app action must open that action

When a task row carries `metadata.completeVia` with a non-empty `app` and
`action`, "Mijn taken" SHALL NOT offer the generic upload form for it. It SHALL
look the action up in the subject's own contributions aggregate and, when that
app contributes an endpoint action with that id, SHALL render that action's
form with the task uuid preset in the action's `taskField` (default `taskId`),
never asked of the resident, and SHALL send the answers through the portal's
endpoint forward. When the subject's aggregate holds no such action, the page
SHALL say the form is not available and SHALL offer no upload form. A
completion refused with code `complete-via-app` SHALL read as a sentence.

#### Scenario: The checklist task opens the app's form

- GIVEN a task with `metadata.completeVia` = `{app: dossiq, action: completeChecklistTask}`
- AND the subject's aggregate holds dossiq's endpoint action `completeChecklistTask`
- WHEN the resident opens the task
- THEN the page shows that action's form and no file field
- AND submitting it forwards to `dossiq/completeChecklistTask` with `taskId` = the task uuid

#### Scenario: An action the subject may not run is not offered

- GIVEN the same task and an aggregate without that action
- WHEN the resident opens the task
- THEN the page says the form for this task is not available and shows no upload form
