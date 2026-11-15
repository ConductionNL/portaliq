## ADDED Requirements

### Requirement: A contributed page may name the menu group it belongs to (REQ-SRM-005)

The site's resident menu MUST put every contributed page that declares a `group` under one heading with that name, whichever app contributes it, in the order the groups first appear. A page without a `group` MUST sit under its app's display name, else its app id. When two items in the menu read the same, an app's item MUST carry its app's name.

#### Scenario: Two apps share one heading
- GIVEN dossiq's "Mijn zaken" and pipelinq's "Mijn verzoeken" both declare `group: "Mijn zaken en verzoeken"`
- AND dossiq's "Mijn uren" declares no group
- WHEN a signed-in resident opens a `/mijn` page
- THEN the menu shows "Mijn zaken en verzoeken" with both items, then "Dossiq" with "Mijn uren"
- @e2e exclude pinned by `tests/site-resident-menu.spec.mjs` ("pages with the same group share one heading across apps")

#### Scenario: Two items of one name in one group are told apart
- GIVEN dossiq and pipelinq both contribute "Mijn zaken" in the group "Mijn zaken en verzoeken"
- WHEN the menu renders
- THEN the items read "Mijn zaken (Dossiq)" and "Mijn zaken (Pipelinq)"
- @e2e exclude pinned by `tests/site-resident-menu.spec.mjs` ("two items of one name in a shared group are told apart by their app")
