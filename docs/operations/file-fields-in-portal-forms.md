---
title: File fields in portal forms
sidebar_label: File fields in forms
---

# File fields in portal forms

A pupil hands in an essay. A resident adds a photo to a report. Both need a
form that takes a file. In the portal, a leaf app gets one by declaring a
field as a file field on its create or update action.

This page is the contract a leaf app implements.

## What the leaf app declares

Mark a whitelisted field as a file field in `fieldConfigs`:

```php
'actions' => [
    [
        'id'           => 'createSubmission',
        'type'         => 'create',
        'label'        => 'Hand in an assignment',
        'register'     => 'learniq',
        'schema'       => 'submission',
        'scopeField'   => 'learnerRef',
        'fields'       => ['assignmentId', 'attachmentRefs'],
        'fieldConfigs' => [
            'attachmentRefs' => [
                'type'      => 'file',
                'label'     => 'Your work',
                'multiple'  => true,
                'accept'    => ['.pdf', '.docx', 'image/*'],
                'maxSizeMb' => 20,
            ],
        ],
    ],
],
```

| Key | What it does |
| --- | --- |
| `type` | `file` turns the field into a file picker. Any other value is ignored. Only create and update actions get a picker. |
| `multiple` | `true` lets people pick several files. The references are appended. |
| `accept` | Extensions (`.pdf`) or MIME types (`application/pdf`, `image/*`). A file must match one. Leave it out to take any file. |
| `maxSizeMb` | The limit per file, 1 to 50. Leave it out for 20. |

The schema property holds a string for one file, or an array of strings for
several. Portaliq writes the Nextcloud file id as a string.

## What happens when someone saves the form

1. The portal creates the record. The file field is not in that request.
2. It uploads each picked file, one at a time, to
   `POST /portal/api/collections/{register}/{schema}/{id}/fields/{field}?action={actionId}`.
3. Portaliq checks that the person owns the record, checks the file against
   `accept` and `maxSizeMb`, stores it in the record's folder and writes the
   file id into the field.

When a file does not attach, the record stays and the form names the file.
The person can try that file again from the same form.

## What portaliq refuses

- A value typed into a file field in a create or update request. It is
  removed before the write, so nobody can point the field at another file.
- An upload for a field that is not a declared file field, or for an action
  the person does not have (403).
- An upload to a record the person does not own (404, the same answer as a
  record that does not exist).
- An upload through a create action to a record older than 30 minutes (403
  `upload_window_closed`). Use an update action to add files later.
- A file outside `accept` (415), above `maxSizeMb` (413), or a field that
  already holds 20 files (409).

## Known limit

On a fresh instance the first upload into a register fails until that
register's folder exists (portaliq#29). The answer is 502 `upload_failed` and
the form names the file.

Next: add the `fieldConfigs` entry to your provider, then open your portal as
a test subject and hand in a file.
