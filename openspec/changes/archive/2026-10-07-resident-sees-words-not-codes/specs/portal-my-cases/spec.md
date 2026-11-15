## ADDED Requirements

### Requirement: A case on "My cases" shows its status in words, never a code

Each case on the site's "My cases" page MUST show its status as the status's public label when the contributing app projects one (`statusPublicLabel`, else `statusLabel`), else the `status` value itself. A value that is a uuid MUST NOT be shown: the row then shows no status. No other identifier of the case (its case type, its status type) MUST appear in the row.

#### Scenario: A Woo request in dossiq
- GIVEN a case with `status: 3c0f5a00-...-b001` (the status type's uuid) and `statusPublicLabel: "Ontvangen"`
- WHEN the resident opens "Mijn zaken"
- THEN the row reads the title, "Dossiq" and "Ontvangen", and no uuid
- @e2e exclude pinned by `tests/my-cases-page.spec.mjs` ("site: a case row shows the status label and no uuid")

#### Scenario: A status known only by its uuid
- GIVEN a case whose only status value is a uuid
- WHEN the row renders
- THEN it shows no status
- @e2e exclude pinned by `tests/my-cases-page.spec.mjs` ("a case status reads as its public label, never as a uuid")
