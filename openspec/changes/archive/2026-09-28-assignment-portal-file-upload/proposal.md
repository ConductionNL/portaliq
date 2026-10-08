---
kind: code
---

# Proposal: assignment-portal-file-upload

## Summary

A leaf app can declare a real file field on a portal `create` or `update`
action. Portaliq's generic form renders it as a file picker, creates the
record, uploads each picked file into the record's OpenRegister folder and
writes the file references into the declared field itself. Today every
whitelisted field renders as a text box, so learniq's "Hand in an
assignment" action exists in the manifest and cannot carry the work.

## Motivation

Learniq round 2, recon C (`learniq-mi/learniq/_round2/recon/C-tests-grading-assignments.md`),
journey step 2b "Pupil submits work via portaliq": **declared, not usable**.

- Section 1: learniq's `student` audience declares `createSubmission` with
  `fields: [assignmentId, attachmentRefs]`, but `SchemaForm.jsx:112-150` and
  `ActionConfigNormaliser.php:50` know no file field type. Every field is a
  text box, a textarea or a select. A pupil can create a bare Submission and
  cannot attach coursework.
- Section 1: the one file primitive that exists, the per-collection
  `filesUpload` flag, attaches to an object that already exists from its
  detail card. No leaf app sets it; learniq's own `SubmitWorkView.vue` does
  create, upload, then write `attachmentRefs`, which is exactly the step the
  portal path cannot perform.
- The text box is also a hole. A client can type any string into
  `attachmentRefs`, including a path to a file that is not theirs.
- Section 4 names this change first for the portal (M), and decision D15
  (Ruben, 2026-09-27) puts the portal file upload in wave 1, before portal
  test taking.
- Competitor evidence, recon C section 2: row 6.1 "Assignments with
  submissions" (Moodle `mod/assign`, Woots paper-scan upload) and row 5.13
  "Homework visible to pupils and parents". Hand-in with a file is the floor
  of every assignment tool in the corpus.

## Affected Projects

- [x] Project: `portaliq`: a `type: file` field config on create and update
  actions, a scoped upload endpoint that writes the reference into the
  declared field, and the file picker in the generic portal form.

## Scope

### In Scope

- `fieldConfigs.<field>.type: file` on a `create` or `update` action, with
  optional `multiple`, `accept` and `maxSizeMb`, sanitised fail-closed.
- A declared file field is never written from a create or update body. Only
  the upload endpoint writes it, with a reference the server produced.
- `POST /portal/api/collections/{register}/{schema}/{id}/fields/{field}?action=<id>`:
  proves the subject owns the object the way the action writes it, checks
  type and size, attaches through `PortalFileWriter`, then writes the file id
  into the field (appended when `multiple`).
- `SchemaForm.jsx` renders the picker, creates the record, uploads the files
  one by one and reports any file that did not attach.
- The contribution keys a leaf app uses, documented in design.md and the PR.

### Out of Scope

- Learniq's wiring (setting `fieldConfigs.attachmentRefs` on
  `createSubmission`). That is a separate learniq change.
- Scoping on an array field. Portaliq's direct scope compares a scalar;
  learniq's `studentSubmissions` scopes by the array `learnerRefs`. That gap
  is recorded under Open Questions, not fixed here.
- Cold-start register folder provisioning (portaliq#29, OpenRegister). The
  new endpoint shares `PortalFileWriter` and inherits that issue.
- Virus scanning, file previews and deleting an uploaded file from the portal.

## Approach

Extend the existing v3 field-config normaliser with one field type, add a
small `PortalProtected` controller for the upload (the contribution
controller is already past its complexity budget), and reuse the scoped
reader, `PortalFileWriter` and `PortalObjectWriter` for the three steps.
The SPA gains one API call and a file branch in `SchemaForm`.

## New Dependencies

None.

## Impact

- `lib/Contribution/ActionConfigNormaliser.php`: keeps the file field keys.
- `lib/Controller/ContributionController.php`: strips file fields from the
  create and update bodies.
- New `lib/Controller/PortalFieldFileController.php` and one route.
- `src/portal/components/SchemaForm.jsx`, `PageView.jsx`, `lib/portalApi.js`,
  `i18n/en.json`, `i18n/nl.json`.

## Cross-Project Dependencies

Learniq consumes the new contract in a follow-up change. No other app
declares a file field today, so nothing changes for them.

## Risks

### Risk 1: The first upload on a fresh instance fails (portaliq#29)
**Severity:** High. **Mitigation:** the new path uses the same
`PortalFileWriter::attachFile()` that portaliq#29 describes. The endpoint
answers 502 `upload_failed`, the form names the file that did not attach,
and the record itself is kept. Named in the PR body; the fix belongs to
OpenRegister.

### Risk 2: A parallel upload loses a reference
**Severity:** Low. **Mitigation:** the append reads, then writes. The SPA
uploads one file at a time; a hand-crafted parallel client can at worst lose
its own reference, never touch another subject's record.

## Rollback Strategy

Revert the PR. No schema or data migration; references already written stay
valid strings in the leaf app's field.

## Open Questions

- Learniq's `studentSubmissions` and `createSubmission` scope by
  `learnerRefs`, an array. Portaliq's direct scope compares a scalar, so a
  row learniq created itself with `learnerRefs: [uuid]` never matches. The
  learniq wiring change has to scope by a scalar field, or portaliq needs
  array-membership scoping as its own change. Provisional decision: record
  it, build neither here.
