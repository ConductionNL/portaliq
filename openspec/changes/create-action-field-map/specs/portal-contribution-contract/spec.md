## ADDED Requirements

### Requirement: A create action may say where each answer lands

A `type: create` action MAY declare `fieldMap`, an object from answer name to a field path made of dot-separated names. When creating, portaliq SHALL apply the whitelist, `requiredFields` and the cross-reference guard to the answers under their own names. It SHALL then move every mapped answer to its path, creating intermediate objects, and leave unmapped answers where they are. A path that is not made of plain names SHALL be ignored. A draft SHALL be saved mapped, and read back with each mapped answer taken from its path. An action without `fieldMap` SHALL behave as before.

#### Scenario: The Woo request
@e2e exclude PHPUnit FieldMapTest and PortalDraftControllerTest
- GIVEN the action maps `onderwerp` to `title` and `periodeVan` to `wooRequest.periodeVan`
- WHEN a resident sends `onderwerp: "Verlichting fietspad"` and `periodeVan: "2025-01-01"`
- THEN the object is written with `title: "Verlichting fietspad"` and `wooRequest: {periodeVan: "2025-01-01"}`, and a draft of it reads back as `onderwerp` and `periodeVan`
