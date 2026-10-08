# site-shared-dossier Specification

## Purpose
Anyone with a shared dossier link reads the documents in it that are public
now, on a page of the portal site. Journey J3.4 in hydra
`openspec/changes/woo-citizen-journey/journey-map.md`.

## Requirements

### Requirement: A shared dossier link must open a public page (REQ-SSD-001)

The site SHALL render the route `/gedeeld-dossier/{token}` without a CMS page
and without a sign-in. It SHALL read
`/index.php/apps/opencatalogi/api/collections/shared/{token}` without the
visitor's session and show the dossier's title as the page heading, its note,
and its items. A token opencatalogi cannot have made SHALL NOT be sent. A 404
SHALL show that the link no longer works and ask the visitor for a new link;
any other failure SHALL show that the dossier cannot be shown now. The page
SHALL be loaded on demand, outside the site's entry bundle.

#### Scenario: A visitor opens a shared link

- **GIVEN** a dossier shared by its owner, with one public document
- **WHEN** a visitor who is not signed in opens the link
- **THEN** the page shows the dossier's title, its note, and the document with its link and note

#### Scenario: The owner revoked the link

- **GIVEN** a shared link the owner revoked
- **WHEN** a visitor opens it
- **THEN** the page says the link no longer works and asks for a new one

### Requirement: The page must show only what the share answers (REQ-SSD-002)

The page SHALL keep of opencatalogi's answer only the title, the description,
and per item the title, the link and the note. It SHALL NOT render the owner or
any other field. A link that is not http, https or a path on this instance
SHALL render as text.

#### Scenario: The answer carries more than the page needs

- **GIVEN** an answer with an `owner` and an item whose link is `javascript:`
- **WHEN** the page renders it
- **THEN** neither the owner nor the `javascript:` link appears
