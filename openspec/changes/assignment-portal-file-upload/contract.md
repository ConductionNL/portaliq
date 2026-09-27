# Contract: assignment-portal-file-upload

Two surfaces: the manifest keys a leaf app declares, and the portal endpoint
the portal SPA calls. Leaf apps consume the first; only portaliq's own SPA
calls the second.

## Consumers

- `learniq`: `createSubmission` in `lib/Portal/PortalContributionProvider.php`
  declares `attachmentRefs` as a file field (follow-up learniq change).
- Any other leaf app with a portal provider that wants a hand-in with a file
  (dossiq bezwaar attachments, pipelinq ticket attachments). None declares one
  today.

## Manifest keys (leaf app side)

On a `type: create` or `type: update` action, a whitelisted field becomes a
file field through its `fieldConfigs` entry:

```php
[
    'id'           => 'createSubmission',
    'type'         => 'create',
    'register'     => 'learniq',
    'schema'       => 'submission',
    'scopeField'   => 'learnerRef',
    'fields'       => ['assignmentId', 'attachmentRefs'],
    'fieldConfigs' => [
        'attachmentRefs' => [
            'type'      => 'file',          // the only accepted value
            'label'     => 'Your work',
            'multiple'  => true,            // default false
            'accept'    => ['.pdf', '.docx', 'image/*'], // optional
            'maxSizeMb' => 20,              // optional, 1 to 50, default 20
            'required'  => true,            // honoured only when the schema requires it
        ],
    ],
]
```

| Key | Type | Rule |
|---|---|---|
| `type` | string | Only `file` is kept, and only on a `create` or `update` action. Anything else is dropped and the field renders as before. |
| `multiple` | bool | `true`, `'true'` or `1` is true; anything else is false. |
| `accept` | string[] | Each entry is an extension (`.pdf`) or a MIME type (`application/pdf`, `image/*`), lowercased. Malformed entries are dropped; at most 20 are kept. Absent or empty means any file. |
| `maxSizeMb` | int | Clamped to 1..50. Non-numeric is dropped. Absent means 20. |

The field MUST be in `fields`. The schema property SHOULD be a string (one
reference) or an array of strings (several). Portaliq writes the Nextcloud
file id as a string.

A file field's value is never taken from a create or update body. A client
that sends `attachmentRefs: ["anything"]` has it removed before the write.

## Endpoints

### `POST /apps/portaliq/portal/api/collections/{register}/{schema}/{id}/fields/{field}?action={actionId}`

**Auth**: portal bearer session (`Authorization: Bearer <portal token>`),
enforced by `PortalAuthMiddleware`. No Nextcloud session.

**Request:** `multipart/form-data` with one part named `file`.

**Response (200):**
```json
{
  "file": { "id": "4711", "name": "essay.pdf", "size": 183204 },
  "field": "attachmentRefs",
  "value": ["4702", "4711"]
}
```

**Errors:**
| Code | Body `error` | Condition |
|------|--------------|-----------|
| 400 | `no_file` | No readable `file` part. |
| 401 | (`authenticated: false`) | No valid portal session. |
| 403 | `forbidden` | `action` names no create or update action of this subject for this register and schema, the field is not a declared file field of it, or the subject's trust is below the action's `minTrust`. |
| 403 | `upload_window_closed` | A `create` action, and the object was created more than 30 minutes ago. |
| 404 | `not_found` | The object is not the subject's, or does not exist. One answer, no oracle. |
| 409 | `too_many_files` | The field already holds 20 references. |
| 413 | `file_too_large` | Larger than the field's `maxSizeMb`. |
| 415 | `file_type_refused` | Matches no `accept` entry. |
| 502 | `upload_failed` | OpenRegister refused the attach (see portaliq#29). |
| 502 | `write_failed` | The file attached but the reference could not be written. |

## Error Codes

| Code | Meaning | Condition |
|------|---------|-----------|
| 400 | Bad request | No file part |
| 401 | Unauthenticated | No portal session |
| 403 | Forbidden | Not an authorised file field, trust too low, or create window closed |
| 404 | Not found | Not the subject's object |
| 409 | Conflict | Field holds the maximum number of references |
| 413 | Payload too large | Over `maxSizeMb` |
| 415 | Unsupported media type | Outside `accept` |
| 502 | Bad gateway | OpenRegister attach or write failed |

## Versioning

Additive to contribution manifest v3. A manifest without `type: file` is
normalised exactly as before. No existing endpoint changes shape.

## Breaking Change Policy

Removing or renaming a key above is a breaking change for every leaf app
that declares it. It goes through an OpenSpec change in portaliq that lists
the consuming providers, and each consumer lands its update first.

## SLA

Same as the other portal write endpoints: rate limited to 20 uploads per
minute per client, synchronous, bounded by the upload size.
