# Design: submission-records-its-action-title

## D1. The title comes from the action that authorised the submission

Every receipt follows a submission that portaliq has just authorised against the subject's own aggregated manifest: `CreateActionMatcher` for a create, `authorisedEndpointAction()` for an endpoint forward. That matched declaration already carries the action's `label` (`{id, label, ...}`, portal-contribution-contract), so the title is read from it. No second lookup, no action registry, no extra call to the contributing app.

A task completion is not a contributed action. It is portaliq's own `task.complete`, and the thing the resident completed is the task. Its title is the task's title as "Mijn taken" showed it (`displayTitle`, else `title`), the same value the completion's data copy already records.

## D2. The text is stored as the resident read it, not as a key

The registry asks each contributing app for its contribution in the portal's language (portal-contribution-contract, "The portal API MUST ask contributing apps in the portal's language"), and the app translates its labels with its own `IL10N`. portaliq never holds a key for those labels, so there is nothing to store but the text. The proof log also records what happened, and what happened is that the resident submitted the action under that name, in that language. An administrator reading the list in another language sees the resident's words, which is what the board draws.

## D3. Existing submissions keep their id; there is no backfill

`portalSubmission` is the append-only burden-of-proof log (supplier-portal). A backfill would rewrite evidence after the fact, and the value it would write is a guess: the label a subject saw depends on the subject's audience, the portal's language and the contributing app's version at the time, none of which the record holds. Existing rows therefore keep showing their action id through the cell's fallback, and the change ships no repair step and no `appinfo/info.xml` change.

## D4. What is kept

A title is kept only when it is a string. It is trimmed and cut at 200 characters, the length the contract already allows a free-text label (`valueLabels`, `fieldConfigs`). An empty title writes no `actionTitle` key at all, so the row reads as one written without a title and the list shows the id. The minimal fallback record, written when the full proof-log write fails, carries the same title so the list names the failed row too.

## D5. One optional argument, at the end

`SubmissionReceiptService::record()` gains `string $actionTitle = ''` after `$audience`. Every existing caller, mock and positional callback keeps working; the three callers that know a title pass it by name.
