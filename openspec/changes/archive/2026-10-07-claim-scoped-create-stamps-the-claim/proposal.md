# Proposal: claim-scoped-create-stamps-the-claim

## Why

Found testing a primary school parent portal end to end (2026-09-30). A guardian who reported their child absent got `write_failed`. OpenRegister refused the write: "Property 'submittedByRef' should match format 'uuid' but '99930md1…' does not".

learniq's guardian action declares `scopeField: submittedByRef` and `scopeClaim: guardianRef`: the report must carry the guardian's learniq uuid, the same claim every parent collection reads by. The create path ignored `scopeClaim` and stamped the scope field with the portal account's own `subjectRef`. The same applies to learniq's pupil actions (`scopeClaim: learnerRef`) and to any other app that scopes a create by a claim. The read path, the update path and the endpoint forward already resolve the claim; only create did not.

## What changes

- A create action that declares `scopeClaim` stamps its `scopeField` with the claim resolved server side from the subject's own portal account (`PortalObjectReader::resolveScopeValue`). Without `scopeClaim` the stamp stays the `subjectRef`.
- An absent claim refuses the create with 403 before anything is written.
