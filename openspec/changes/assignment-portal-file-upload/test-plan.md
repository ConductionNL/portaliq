# Test Plan: assignment-portal-file-upload

Live browser runs are out of reach for this change: the attach reaches
OpenRegister's `FileService`, which fails on a fresh CI instance
(portaliq#29), and the lane may not touch the shared instance. Every scenario
is pinned at the seam where it is decidable.

## Test Cases

### TC-1: A sound file field survives normalisation
- **spec_ref**: `openspec/changes/assignment-portal-file-upload/specs/portal-contribution-contract/spec.md#requirement-an-action-must-be-able-to-declare-a-file-field`
- **type**: regression
- **preconditions**: a create action whitelisting `attachmentRefs` with a file config
- **steps**: normalise the actions
- **expected result**: `type`, `multiple`, lowercased `accept`, `maxSizeMb` kept
- **test command**: `vendor/bin/phpunit --filter ActionConfigNormaliserFileFieldTest`

### TC-2: Malformed file keys fail closed; endpoint actions lose the type
- **spec_ref**: same requirement
- **type**: security
- **preconditions**: malformed `accept`, out-of-range `maxSizeMb`, `type: upload`, an endpoint action
- **steps**: normalise
- **expected result**: bad entries dropped, size clamped, no `type` where not allowed
- **test command**: `vendor/bin/phpunit --filter ActionConfigNormaliserFileFieldTest`

### TC-3: A typed file field value never reaches the writer
- **spec_ref**: `...#requirement-a-file-field-must-never-be-written-from-a-request-body`
- **type**: security
- **preconditions**: create and update actions declaring a file field
- **steps**: post a body carrying a typed reference
- **expected result**: the writer receives the body without the file field
- **test command**: `vendor/bin/phpunit --filter ContributionControllerFileFieldTest`

### TC-4: Upload attaches and appends the reference
- **spec_ref**: `...#requirement-a-subject-must-be-able-to-upload-into-a-declared-file-field-of-an-object-they-own`
- **type**: api
- **preconditions**: owned object created a minute ago with one reference
- **steps**: call `upload()` with a file part
- **expected result**: attach called once; update writes both references; 200 body names file, field, value
- **test command**: `vendor/bin/phpunit --filter PortalFieldFileControllerTest`

### TC-5: Refusals come before any attach
- **spec_ref**: same requirement
- **type**: security
- **preconditions**: undeclared field, unnamed action, foreign object, closed create window, trust too low, no session
- **steps**: call `upload()`
- **expected result**: 403 / 403 / 404 / 403 / 403 / 401; `attachFile` never called
- **test command**: `vendor/bin/phpunit --filter PortalFieldFileControllerTest`

### TC-6: Accept, size, window and merge rules
- **spec_ref**: same requirement
- **type**: security
- **preconditions**: policy with a fixed clock
- **steps**: extension and MIME matches, oversize, missing timestamp, full field
- **expected result**: 415 / 413 / window closed / 409 decisions as specified
- **test command**: `vendor/bin/phpunit --filter PortalFileFieldPolicyTest`

### TC-7: The form renders a picker and runs create then upload
- **spec_ref**: `...#requirement-the-generic-portal-form-must-render-a-file-field-as-a-file-picker`
- **type**: functional
- **preconditions**: file field with `multiple` and `accept`; fake api
- **steps**: render SchemaForm; run the submit module with two files, second fails
- **expected result**: `<input type="file" multiple accept=".pdf">`; create body lacks the field; both uploads target the new id; the failed name is returned
- **test command**: `node --test tests/schema-form-file-field.spec.mjs`

## Coverage Summary

- Declare a file field: TC-1, TC-2
- Never written from a body: TC-3
- Scoped upload into the field: TC-4, TC-5, TC-6
- Form renders a picker: TC-7

## Out of Scope

- A live Playwright run of the hand-in, until portaliq#29 lets the attach
  succeed on a fresh instance.
- Learniq's side of the hand-in (its own change).
