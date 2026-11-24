## ADDED Requirements

### Requirement: The documents block groups rows and adds the school's own papers

The documents block MAY declare `groupBy` with a row field; it MUST then render one heading per distinct value, in the order the provider returned them, with that value's rows under it. It MAY declare `extraGroups`, each naming a heading and the register and schema of media whose OpenRegister object permissions are set per group; those MUST render as a group of their own and MUST contain only media OpenRegister's own permission check lets the signed-in person's Nextcloud account read. Portaliq MUST NOT filter the media by an audience rule of its own, and a session that is not a Nextcloud account MUST be shown none of them. A row with `isNew` MUST carry a "Nieuw" badge, and a row with `status` a pill in words.

#### Scenario: Fatima's two children and the school's papers
- **GIVEN** learniq returns Vera's reports with group "Groep 7" and Sami's with group "Groep 4", and the school's paper schema holds the school guide, readable for the parents' group
- **WHEN** Fatima opens Rapporten en documenten
- **THEN** she reads the headings "Groep 7", "Groep 4" and "Van school", the guide under "Van school"
- @e2e exclude component behaviour covered by `node --test tests/site-documents-groups.spec.mjs`; the page by learniq's `tests/e2e/portal-design/wilgenboom.spec.ts`

#### Scenario: A paper for another group
- **GIVEN** a letter in the school's paper schema whose object permissions admit the groep 5 parents' group only
- **WHEN** Fatima (groep 7 and groep 4) opens the page
- **THEN** the letter is not shown
- @e2e exclude OpenRegister's permission answer asserted in PHPUnit (`AudienceMediaReaderTest`)

### Requirement: The documents block may offer a note, a bundle download and an upload

The documents block MAY declare `note` (authored text, shown under the list), `bulkDownload` (a label, shown as one button above the list when the collection's `documents` declares a `bundle` method; portaliq streams the first entry that method answers) and `upload` (a create action of the contributing app with a file field). The bundle and the upload MUST go through the contributing app's declared endpoints and the signed-in person's own scope.

#### Scenario: All valid certificates
- **GIVEN** the employer's documents block declares a bundle download "Alle geldige certificaten downloaden"
- **WHEN** Linda presses it
- **THEN** one file is downloaded from learniq's bundle endpoint for her organisation
- @e2e exclude spec-only proposal; asserted in learniq's proof run
