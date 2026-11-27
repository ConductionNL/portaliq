# landing-page-provisioning Delta: anonymous-form-file-upload

## ADDED Requirements

### Requirement: A landing page's form sends its files with a one-time token

A `form` row MAY declare fields of type `file` with `accept`, `maxSizeMb` (at most 50, twenty when absent) and `multiple`. The submit SHALL never write a file field's value from the request body. A successful submit of a form with file fields SHALL answer an `uploadToken`: random, kept only by its hash, valid for thirty minutes, bound to the object the submission created and to the form's file fields, one file per field or ten for a field that allows several. `POST /portal/api/form-upload/{field}` SHALL take one file with that token, without a portal session, rate limited per client; it SHALL check the file against the field's size and type before anything is written, and SHALL attach an accepted file to the created object's own folder, tagged with its field. A missing, unknown, expired or used token and a field the form does not declare SHALL get one and the same refusal. The file SHALL be written through Nextcloud's filesystem, so an installed antivirus scans it; a refused write SHALL keep nothing.

#### Scenario: A visitor sends a file with a landing-page form
- **GIVEN** an active form with a text field and a file field taking `.png` up to 1 MB
- **WHEN** a visitor without a session submits the form and then sends a small PNG with the answer's token
- **THEN** the submit answers an `uploadToken` and the upload answers the attached file and its field

#### Scenario: A token takes one file per field
- **GIVEN** the same submission, its file field already sent
- **WHEN** a second file is sent with the same token to the same field
- **THEN** the upload is refused and nothing is written

#### Scenario: A file the field does not take is refused before it is written
- **GIVEN** a fresh submission of the same form
- **WHEN** a PDF is sent to the field that takes `.png`
- **THEN** the upload is refused as a type the field does not take, and the token keeps its upload

#### Scenario: An upload without a valid token is refused
- **GIVEN** no token, or a token nobody minted
- **WHEN** a file is sent to the upload route
- **THEN** the answer is a 403 and nothing is read or written
