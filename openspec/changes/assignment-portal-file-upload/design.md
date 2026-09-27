# Design: assignment-portal-file-upload

## Architecture Overview

Three layers change, each reusing what is there.

```
leaf app manifest            portaliq server                         portal SPA
fieldConfigs.x.type=file --> ActionConfigNormaliser keeps it  ---->  SchemaForm renders <input type=file>
                             ContributionController.create/update    POST create (x left out)
                               strips x from the body          <---- 
                             PortalFieldFileController.upload  <---- POST .../{id}/fields/x?action=a  (per file)
                               1 match action + field + trust
                               2 prove ownership (scoped read)
                               3 PortalFileFieldPolicy: window, accept, size
                               4 PortalFileWriter.attachFile
                               5 PortalObjectWriter.updateObject {x: refs}
```

The existing per-collection `filesUpload` block stays as it is. It attaches
a file to a record from its detail card and writes nothing into the record.
The new path is the one a form needs: the record does not exist until the
form is saved, and the leaf app reads the references from a field.

## API Design

### `POST /portal/api/collections/{register}/{schema}/{id}/fields/{field}?action={actionId}`

Multipart part `file`. Full request, response and error table in
`contract.md`. Route name `portalFieldFile#upload`, registered next to
`contribution#uploadFile` and before the `/portal/{path}` catch-all.

## Database Changes

None. Portaliq owns no tables and this change adds no schema.

## Nextcloud Integration

- Controllers: new `PortalFieldFileController` (implements `PortalProtected`,
  `#[PublicPage]`, `#[NoCSRFRequired]`, `#[AnonRateLimit(limit: 20, period: 60)]`,
  the same posture as `contribution#uploadFile`).
- Services: new `PortalFileFieldPolicy` (pure rules: create window, accept,
  size, merged reference value, reading the multipart part). Reused:
  `PortalContributionRegistry`, `PortalSessionService`, `PortalObjectReader`,
  `PortalFileWriter`, `PortalObjectWriter`, `PortalSchemaReader`,
  `AuditTrailService`.
- OCP: `IRequest::getUploadedFile()`, `ITimeFactory` for the create window.
  File bytes land through OpenRegister's `FileService` inside
  `PortalFileWriter` (ADR-022); portaliq never touches `IRootFolder`.

## Decisions

### D1: A field type, not a second block

The recon offered two routes: a real file field type in the form, or
activating the `filesUpload` flag for a create-then-attach flow. The flag
only attaches to the record's folder; the leaf app then sees a file it has
no reference to, and the pupil has to open the record after saving it.
A field type keeps it one form and puts the reference where learniq's
teacher screens already read it (`MarkSubmissionView` lists
`attachmentRefs`). Chosen: the field type.

### D2: The server writes the reference, never the client

The alternative, uploading and then letting the client PATCH the returned id
into the field, would keep the field client-writable. That is the hole the
text box has today: any string, including a path to another person's file.
So a file field is removed from every create and update body, and only the
upload endpoint writes it, with the id `PortalFileWriter` returned.

### D3: Ownership is proven the way the action writes

`create()` stamps the scope field with the subject's `subjectRef`, even when
the action declares a `scopeClaim`; `update()` resolves the claim. The upload
proves ownership with the same value its action would have written, then
writes through `PortalObjectWriter::updateObject()`, which re-verifies it a
second time before saving. A foreign or absent id is one 404.

### D4: A create action's upload window is 30 minutes

A create action exists to make a new record. Without a window, the same
action would let a pupil add files to a submission the teacher already
marked. The window uses the record's own `@self.created`; an absent or
unreadable timestamp refuses (fail closed). An `update` action has no window:
it may already edit the record, and the leaf app's lifecycle guards decide.

### D5: Accept and size are enforced on the server

`accept` matches like the browser attribute: an extension entry against the
lowercased file name, a MIME entry (with `type/*`) against the type
`finfo` detects in the bytes. Any match passes. `maxSizeMb` defaults to 20
and is capped at 50. The browser checks the same rules first so a pupil
learns before the record is saved.

### D6: A separate controller

`ContributionController` already suppresses five PHPMD class thresholds.
The upload is one route with its own ordering argument, the same reason
`PortalTimelineController` is its own class.

### D7: The SPA submit flow is a pure module

`src/portal/lib/fileFieldSubmit.js` splits the file fields out of the form,
creates, then uploads each file in turn and returns the names that failed.
`SchemaForm` calls it. A pure module is testable with `node --test` and no
browser, which matters while portaliq#29 keeps the live attach red on CI.

## Security Considerations

- Authentication: portal bearer through `PortalAuthMiddleware`; 401 without.
- Authorisation order, each refusal before the next step: action named and
  owned by the subject's own aggregate (403), field declared as a file field
  (403), `minTrust` (403), ownership read (404), create window (403),
  accept and size (415, 413), then the attach, then the write.
- IDOR: the id in the path only selects; the scoped read decides.
- No oracle: foreign and absent objects give the same 404 body.
- Input: the file name is reduced to its basename; `accept` and `maxSizeMb`
  are sanitised by the normaliser and re-read from the normalised manifest.
- Audit: a successful write records verb `update` on the object.
- Inherited: the attach runs without a Nextcloud user and fails on a register
  whose folder does not exist yet (portaliq#29). It fails closed with 502.

## NL Design System

The picker is a native `<input type="file">` inside the form's existing
`portaliq-field` wrapper, with a `<label for>` and the help line, so it takes
the portal theme tokens already applied to form fields. No new colours.

## File Structure

```
appinfo/routes.php                                   + portalFieldFile#upload
lib/Contribution/ActionConfigNormaliser.php          file keys in fieldConfigEntry
lib/Controller/ContributionController.php            strip file fields on create/update/anonymous
lib/Controller/PortalFieldFileController.php         new
lib/Service/PortalFileFieldPolicy.php                new
src/portal/lib/portalApi.js                          + uploadFieldFile()
src/portal/lib/fileFieldSubmit.js                    new
src/portal/components/SchemaForm.jsx                 file input branch, t prop
src/portal/components/PageView.jsx                   passes t to SchemaForm
src/portal/i18n/en.json, nl.json                     new strings
tests/Unit/Contribution/ActionConfigNormaliserFileFieldTest.php
tests/Unit/Controller/ContributionControllerFileFieldTest.php
tests/Unit/Controller/PortalFieldFileControllerTest.php
tests/Unit/Service/PortalFileFieldPolicyTest.php
tests/schema-form-file-field.spec.mjs
```

## Seed Data

Not applicable: no schema is introduced or changed. Portaliq's own example
provider does not declare a file field; the learniq follow-up change carries
the first real declaration.

## What the leaf app adds (learniq follow-up)

In `studentActions()`, on `createSubmission`:

```php
'fieldConfigs' => [
    'attachmentRefs' => [
        'type'      => 'file',
        'label'     => 'Your work',
        'multiple'  => true,
        'accept'    => ['.pdf', '.doc', '.docx', '.odt', '.pptx', '.jpg', '.png'],
        'maxSizeMb' => 20,
    ],
],
```

Two learniq-side facts decide whether the hand-in lands:

1. `createSubmission` and `studentSubmissions` scope by `learnerRefs`, an
   array. Portaliq's direct scope compares a scalar: `create()` stamps a
   string into it and the reader casts the stored value to a string. A row
   learniq's own UI created with `learnerRefs: [uuid]` never matches. The
   learniq change needs a scalar scope field (for example `learnerRef`, as
   GradeEntry has), or portaliq needs array-membership scoping first.
2. `Submission.required` includes `learnerIds` and `tenant_id`, which the
   portal create does not send. Learniq needs `defaults`, a server-side
   stamp, or a relaxed `required` for the portal path.

## Trade-offs

- Sequential uploads are slower than one multi-file request, and they keep
  the append race out of the normal path. Chosen for correctness.
- No automatic retry on 502, unlike `uploadFile()`: a retried append could
  write the same file twice.
- A record with no attached file survives a failed upload. Deleting it would
  hide a real record the pupil made; naming the failed files lets them retry.
