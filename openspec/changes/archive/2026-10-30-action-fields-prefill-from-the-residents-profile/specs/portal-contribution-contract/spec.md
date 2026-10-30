## ADDED Requirements

### Requirement: An action field may start from the signed-in resident's name or e-mail address

An action field config MAY declare `prefill` with the value `name` or `email`. Portaliq MUST drop any other value. For a signed-in resident the site MUST start an empty field that declares `prefill` from the resident's own details: `name` from the display name, `email` from the e-mail address. A value from a saved draft, a preset or the resident's own typing MUST NOT be replaced. Without a session or without the detail the field MUST stay empty. `prefill` MUST NOT add a field to the action's whitelist.

#### Scenario: A Woo request knows who is asking
- GIVEN Henk Bakker is signed in and his details hold his name and e-mail address
- AND the request form's name field declares `prefill: name` and its e-mail field `prefill: email`
- WHEN he reaches the step "Uw gegevens"
- THEN both fields show his name and e-mail address and he can change them
- @e2e exclude pinned by `tests/profile-prefill.spec.mjs` and `ActionConfigNormaliserTest::testAPrefillSourceIsKeptOnlyWhenKnown`; the live check needs dossiq's declaration

#### Scenario: A saved draft wins
- GIVEN a saved draft holds another e-mail address
- WHEN the resident opens the form again
- THEN the e-mail field shows the draft's address
- @e2e exclude pinned by `tests/profile-prefill.spec.mjs` "an empty field starts from the profile; a filled one keeps its value"

#### Scenario: Another source is refused
- GIVEN a field declares `prefill: bsn`
- WHEN portaliq normalises the action
- THEN the field config carries no `prefill`
- @e2e exclude pinned by `ActionConfigNormaliserTest::testAPrefillSourceIsKeptOnlyWhenKnown`
