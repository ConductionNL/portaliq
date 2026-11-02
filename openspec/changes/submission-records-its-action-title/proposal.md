---
kind: code
---

# Proposal: submission-records-its-action-title

## Why

The admin Submissions list (board PtInzendingen) names each submission by what the resident did: "Melding openbare ruimte", "Grof afval laten ophalen", "Woo-verzoek indienen". portaliq shows the declared action id instead (`createExample`, `task.complete`). PT-DATA2 added the read side: `portalSubmission.actionTitle` (portalSubmission 0.3.0, register 0.104.0) and the `action-title` cell widget, which shows the title and falls back to the id. Nothing writes the title yet, so every live row still reads as an id.

## What changes

- The proof-log record (`portalSubmission`) written after a successful submission carries `actionTitle`: the name of the action as the resident read it at the moment of submission.
- For a contributed create action (the direct create and the create branch of an endpoint action) that name is the action's declared `label`, as the contributing app gave it for this subject.
- For a completed task (portaliq's own `task.complete`) it is the task's title as "Mijn taken" showed it.
- The minimal fallback record written when the full proof-log write fails carries the same title.
- Existing records are not changed. They keep showing their action id.

## Out of scope

- The submission detail page (`PortalSubmissionDetail`) still titles itself by `actionId`. Pointing it at the title is a manifest change for a later lane.
- No new strings, no repair step, no change to `appinfo/info.xml`.

## Impact

- `lib/Service/SubmissionReceiptService.php`: `record()` gains an optional trailing `actionTitle` argument.
- `lib/Controller/ContributionController.php`: the two `record()` calls pass the matched action's label.
- `lib/Controller/PortalTaskProxyController.php`: the completion `record()` call passes the task's title.
- Other apps: none. A contributing app that already declares a `label` on its actions gets its rows named without a change.

## Rollback

Revert the commit. Records written in the meantime keep their `actionTitle`; the field is optional and additive.
