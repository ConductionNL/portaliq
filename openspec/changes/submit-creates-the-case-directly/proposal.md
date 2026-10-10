---
kind: code
depends_on: []
---

# Proposal: submit-creates-the-case-directly

portaliq's half of decision 179 (Ruben, 10 October 2026), with Ruben's answers in decisions 180 and 181: "We dont intake to an intake, we intake into a case, or ticket or something else. We should not have a separate in-between layer." Cross-app change: `hydra/openspec/changes/form-submits-into-its-destination-object`, architecture in hydra ADR-117. Needs `openregister/form-destination-validator` and `dossiq/a-request-form-opens-the-case-at-once`.

## Why

Every portal and embed submit lands in `portalIntakeSubmission`. `PortalIntakeDeliveryJob` turns it into a case every 60 seconds, 50 at a time, with `_rbac: false`. A failure is final. The form is checked against its own fields, never against the case schema, so a refusal arrives after the resident already holds an `AANVRAAG-` reference. The confirmation cannot name the case number, the term start or the deadline, because none exist yet (dossiq Q-dossiq-L1-4).

Landing pages do the same in a second shape: `landingPageSubmission` stores the visitor's answers, and a relay event tells the contributing app. When that app is missing, the answers sit in portaliq and nobody is told.

## What changes

1. **Submit and embed create the destination.** `PortalIntakeController::submit` and `PortalEmbedController::submit` call OpenRegister's `FormSubmitService` with the binding's resolved destination and the portal subject. The response carries the case reference, `receivedAt` and the confirmation fields.
2. **The binding names its destination and is checked.** `portalFormBinding` stores `destination { register, schema }` (replacing `caseRegister`/`caseSchema`/`deliverTo`). Saving or publishing a binding runs OpenRegister's validator; `PortalBindingPreview` shows the findings.
3. **A refusal comes back on the field.** A 422 from the destination renders per field in the form. Nothing is stored.
4. **Spam protection keeps its order**: honeypot, `PortalEmbedThrottle` and rate limits, `PortalChallengeService`, then the submit. Embed submits gain the honeypot.
5. **Woo request forms submit into the dossiq Woo case**, superseding `woo-intake-delivers-to-dossiq`'s delivery job.
6. **Landing-page forms submit into the contributing app's object** (pipelinq: a lead). `landingPageSubmission` and its relay listener go.
7. **Payment follows the object.** `intake-pay-on-submit` reads the case's payment status instead of the queue object (decision 181).
8. **Drain, then remove.** `occ portaliq:intake:drain` pushes every `queued` and `failed` entry through the submit service, copies `AANVRAAG-…` to the case's `externalReference`, and reports. Then `PortalIntakeQueue`, `PortalIntakeDeliveryJob`, `PortalWooRequestDelivery`, the `portalIntakeSubmission` and `landingPageSubmission` schemas and the job entry in `info.xml` are removed.
9. **A saved form is a draft of the destination object** (decision 180). "Save and carry on later" saves the case itself, with OpenRegister metadata status `draft`: it may miss required answers but is never type-invalid. Sending moves it out of `draft` through the submit service, which is when it gets its reference and received moment. `portalDraft` and the planned `journeyRun` are drained into draft destination objects, then removed. A signed-in resident finds the draft by account; an anonymous one by the resume link, which now points at the draft object.

## Changes this supersedes or amends

`woo-intake-delivers-to-dossiq`, `form-governance-availability-retention-and-routing` (REQ-FGV-002 to 005), `embedded-intake-form` (REQ-EIF-003, 004), `intake-pay-on-submit` (REQ-IPS-003, 005), `form-statements-intro-and-confirmation-mail` (REQ-FCI-003), `resident-identity-in-forms` (REQ-RIF-002, 005), `portal-shared-runtime` task 69, `intake-conditional-questions-and-drafts` (REQ-ICQ-005). Each is noted in tasks section 6 for its owning lane.

## Rollback

The queue path stays in code until the drain reports zero. Switching the controllers back restores it.
