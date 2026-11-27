# Proposal: anonymous-form-file-upload

## Why

site-nlds-widget-palette T11b left one field type open: `file`. A landing-page form submits anonymously, but every portal file upload (`portalFieldFile#upload`, `contribution#uploadFile`) needs a portal session, so a file field needs a new anonymous upload path (b-portaliq question, 25 Nov 2026 14:21Z). Ruben decided (25 Nov 2026 15:15 CET): option (a), a one-time upload token bound to the just-created submission, size/type limits from the field, rate limited, virus-scan hook if present.

## What changes

- The `form` widget's field palette gains `file` (NL Design System File Input) with `accept`, `maxSizeMb` and `multiple`.
- `FormSubmitActions` declares each file field of a form as a normalised file config (`fieldConfigs.<id>.type: file`) on its anonymous create action. The submit leaves a file field's value out of the body, as for every action.
- After a successful submit into the destination, the create answer carries `uploadToken`: minted by `FormUploadGrants`, kept by its SHA-256 hash in the distributed cache for thirty minutes, bound to the created object and the form's file fields, one file per field (ten when the field allows several).
- `POST /portal/api/form-upload/{field}` (public, 20 per minute per client) takes one multipart `file` with the token in `X-Portaliq-Upload-Token`. It checks the file against the field's size and type (the same policy as signed-in uploads) before anything is written, uses the upload, and attaches the file to the object's own OpenRegister folder, tagged `portaliq-form-field:<field>`. A missing, unknown, expired or used token and an undeclared field get one 403.
- The scan: the file is written through Nextcloud's filesystem, so Nextcloud's antivirus app, when installed, scans it like any write; its refusal answers 502 and nothing is kept.
- FormBlock draws the file input, checks the files before sending (required, size, type), sends them after the submit with the token, and says so under the success when a file did not arrive.

## Out of scope

- Writing a file reference into the destination object's body: an anonymous write to an existing object is not opened; the file is on the object, tagged with its field.
- Live check with a real browser and a destination app: the live lane.
