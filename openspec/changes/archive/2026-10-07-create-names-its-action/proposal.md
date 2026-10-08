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
- The anonymous create path had the same first-match shape. Every active landing-page form is its own anonymous create action on `landingPageSubmission`, so with two forms live, the second form's answers were filed under the first form's whitelist and `formId`. The site's landing-page form (`FormBlock.vue`) now sends `?actionId=submit-{formId}`, and the anonymous path matches among anonymous actions only, with the same 403 and 400 answers.
- The Vue site's signed-in forms write through the shared adapter (`src/shared/portalApi.js`), so they send the id as well.
