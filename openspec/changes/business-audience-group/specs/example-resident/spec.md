## MODIFIED Requirements

### Requirement: The example residents are in the portal's resident group
portaliq's Zuiddrecht fragment MUST declare the group `inwoners` with the residents Sanne, Henk, Linda and Fatima as members, and its change on the portal MUST set `authentication.registration.residentGroup` to `inwoners` with the `nextcloud` way in. Business contacts MUST NOT be members of `inwoners`: they act for a company, and their portal accounts have audience `supplier`. The fragment MUST declare the group `ondernemers` with the business contacts Jan-Willem, Mark and Karin as members, and a change `ondernemers-op-het-portaal` on the portal MUST set `authentication.registration.businessGroup` to `ondernemers`.

#### Scenario: The residents' group
@e2e exclude Node: tests/example-resident.spec.mjs
- GIVEN the shipped `zuiddrecht.json`
- WHEN its groups and changes are read
- THEN `inwoners` MUST hold exactly `sanne`, `henk`, `linda` and `fatima`, and the change MUST name `inwoners` as the resident group

#### Scenario: The business contacts' group
@e2e exclude Node: tests/example-resident.spec.mjs
- GIVEN the shipped `zuiddrecht.json`
- WHEN its groups and changes are read
- THEN `ondernemers` MUST hold exactly `janwillem`, `mark` and `karin`, none of them MUST be in `inwoners`, and the change `ondernemers-op-het-portaal` MUST name `ondernemers` as the business group
