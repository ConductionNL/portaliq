## ADDED Requirements

### Requirement: The organisation filter reads the organisation's name

The public site record MUST carry the id and the name of the portal's own organisation as `organisation: {id, name}`, or null when the portal names none that resolves. The search block's organisation filter MUST show that name for a bucket whose value is that id, and MUST still send the id as the filter value. A bucket of another organisation MUST keep the label the search API gave it.

#### Scenario: Zuiddrecht's publications
- GIVEN the Zuiddrecht portal belongs to the organisation "Gemeente Zuiddrecht" with id `bacda7a4-…`
- AND 13 publications name that id as their `organization`
- WHEN a visitor opens the Woo search
- THEN the "Organisatie" filter reads "Gemeente Zuiddrecht (13)"
- AND ticking it sends `organization=bacda7a4-…`
- @e2e exclude pinned by `tests/publication-documents.spec.mjs` and `ContentControllerTest::testSiteNamesItsOrganisation`; the live check on the rehearsal follows the merge
