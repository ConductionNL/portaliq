# Design: partner-tasks-in-the-portal

Kind: code. One audience widening, one contribution action, one identity
hand-off.

## D1. Audience on the task surface

`PortalTaskProxyController` resolves the bearer session and mints the
`X-Portal-Subject` assertion with the session's `audience`. Today the
surface is reached from the `client` navigation only. The change: the
"Mijn taken" route is registered for every audience, and the engine's
subject-scoped read already filters by `subjectRef`, so a `supplier` or
`partner` session sees its own tasks and nothing else. No new read path.

## D2. `ask-partner`

A contract v2 endpoint action on the internal side. The case app declares
it in its contribution manifest:

```json
{ "id": "ask-partner", "kind": "internal", "minRole": "handler",
  "fields": ["partner", "title", "description", "dueAt", "uploadRules"] }
```

Portaliq renders the dialog in the case app through the existing
`portal-contribution-endpoint-actions` runtime. On submit, portaliq:

1. Resolves `partner` to a `portalAccount`: an existing one by id, or a
   pre-provisioned one from a KvK number and a contact email
   (`portal-identity-space`, `PortalAccountService::provision()`).
2. Calls OpenRegister's portal-task create endpoint server-to-server as
   the handler, with `subjectRef` = the account's `subjectRef`, the case
   as the task's object, and the due date and upload rules frozen on the
   task.
3. The delivery ledger gets its rows; the worker of `portal-task-delivery`
   sends the inbox message and the mail.

The handler's own write right on the case is the authorization; portaliq
checks it before the create and refuses with 403 otherwise.

## D3. Not yet in the portal

A pre-provisioned account is `status: pending`. The mail from the worker
carries the login link; on first eHerkenning login the account is matched
on `(identityType, identityRef)` and activated, and the task is already
waiting. Until then the case app's task list shows the ask as "sent,
awaiting first login".

## D4. The answer

Unchanged: the completion endpoint of `portal-task-delivery` stores the
comment and files on the case object and the engine marks the task done.
The case app's existing task leaf reads it.

## Risks

- A handler asking the wrong organisation. The dialog shows the KvK
  number and the registered name from the KvK lookup before submit.
- A partner with several people. One `portalAccount` per eHerkenning
  identity; the organisation claim groups them, and the task is addressed
  to the organisation's `subjectRef`, so any of them can answer. The task
  records who did.
