# Proposal: create-names-its-action

## Why

Found testing the portal live on 2026-10-01. A create posts to `/portal/api/collections/{register}/{schema}`, and `ContributionController::authorisedCreateAction()` took the first `create` action declared for that register and schema. Two apps declare two creates on one schema:

- pipelinq: `createRequest` and `createComplaint` both write `ticket`. A complaint filed from the portal was saved as a request, through the request form's whitelist, so `complaintCategory` was dropped.
- dossiq: `createKlacht` and `createBezwaar` both write `portaalVerzoek`. A bezwaar was matched to `createKlacht`, and its `againstCaseId` and its case cross-reference guard were skipped.

The frontend sent nothing that said which form was filled in.

## What changes

- The portal frontend sends the action's id as `?actionId=` on every create (`portalApi.createObject`).
- The create path matches the action by id. An id the subject has no create action for on that register and schema is refused with 403 before anything is written.
- Without an id, exactly one matching action keeps working as before. Two or more are refused with 400 `action_required`, rather than guessed.
