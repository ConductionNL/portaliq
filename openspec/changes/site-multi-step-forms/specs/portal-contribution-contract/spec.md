## ADDED Requirements

### Requirement: A create or endpoint action MAY run in steps with a review, a draft and a confirmation (REQ-SMF-020)

The contribution contract MUST accept on a create action and on an endpoint action with `fields`: `steps` (`[{ id, title, description?, fields[], review? }]`), `draft` (`{ retentionDays }`, an integer 1 to 90) and `confirmation` (`{ title, body?, next? }`). A step MUST be kept only when every field it names is one of the action's fields. A step with `review: true` MUST carry no fields and MUST be rendered as the review of REQ-SMF-011. Fields in no step MUST go in a last step of their own. The site MUST render such an action with the step flow of REQ-SMF-010 and REQ-SMF-011.

#### Scenario: The Woo request in four steps
- GIVEN dossiq's `startWooVerzoekAlgemeen` declares steps "Uw vraag", "Periode en documenten", "Uw gegevens" and a review step "Controleren en versturen"
- WHEN the resident opens the action on a site page
- THEN the form shows "Stap 1 van 4: uw vraag" and a progress list of four steps

#### Scenario: A step naming a field the action lacks is dropped
- GIVEN a step whose `fields` include `iban` and the action has no field `iban`
- WHEN the contribution is normalised
- THEN that step is dropped and its other fields go to the last step

#### Scenario: The Woo endpoint action runs in steps
- GIVEN dossiq's `startWooVerzoek` is an endpoint action posting to `/api/portal/woo-verzoek` and declares the four steps
- WHEN the contribution is normalised
- THEN the action keeps its `steps`, `draft` and `confirmation`

### Requirement: An action may name its required fields (REQ-SMF-023)

Ruben decided on 3 October 2026: "Let an action name its required fields". A create action, and an endpoint action with `fields`, MAY declare `requiredFields`: a list of field names. The contract MUST keep only names that are in the action's own `fields`, that are not a file field and that the server does not fill itself (a `defaults` key, the `subjectField`); every other entry MUST be dropped. A malformed list MUST be dropped whole.

A field of the action is required when the action names it in `requiredFields`, or, on a create action, when the action's schema requires it. The contract MUST write that set as `fieldConfigs.<field>.required: true`, the flag the site and the server both read. A schema-required field MUST stay required, whatever the action declares. `fieldConfigs.<field>.required: true` alone still MUST NOT make a field required. The site MUST mark a required field as REQ-SMF-001 says: no "(niet verplicht)", `aria-required`, and its own `requiredMessage` (REQ-SMF-006) in the error summary when it declares one.

#### Scenario: learniq books a conference time
- GIVEN learniq's create action `bookConferenceSlot` on `conference-signup`, whose schema requires nothing, with `fields` `learnerRef`, `slotId` and `notes` and `requiredFields: [learnerRef, slotId]`
- WHEN the contribution is normalised
- THEN `learnerRef` and `slotId` carry `required: true`
- AND `notes` reads "(niet verplicht)"
- @e2e exclude normalisation, covered by PHPUnit `RequiredFieldsNormaliserTest::testBookConferenceSlotRequiresTheChildAndTheTime`

#### Scenario: learniq signs up for a conference round
- GIVEN learniq's create action `createConferenceSignup` with `requiredFields: [conferenceRoundId, learnerRef]`
- WHEN the contribution is normalised
- THEN `conferenceRoundId` and `learnerRef` carry `required: true`
- @e2e exclude normalisation, covered by PHPUnit `RequiredFieldsNormaliserTest::testCreateConferenceSignupRequiresTheRoundAndTheChild`

#### Scenario: dossiq's Woo request names five fields
- GIVEN dossiq's endpoint action `startWooVerzoekAlgemeen`, which names no schema, declares `requiredFields` `onderwerp`, `omschrijving`, `periodeVan`, `documentSoorten`, `verzoekerNaam` and `verzoekerEmail`
- WHEN the contribution is normalised
- THEN those six fields carry `required: true`
- AND `periodeTot`, `toelichting` and `verzoekerType` read "(niet verplicht)"
- @e2e exclude normalisation, covered by PHPUnit `RequiredFieldsNormaliserTest::testTheWooRequestNamesItsFiveFields`

#### Scenario: An action cannot require a field it does not ask for
- GIVEN an action whose `requiredFields` names `bsn`, which is not in its `fields`, and a file field
- WHEN the contribution is normalised
- THEN neither is kept in `requiredFields` and neither carries `required`
- @e2e exclude normalisation, covered by PHPUnit `RequiredFieldsNormaliserTest::testOnlyTheActionsOwnAskedFieldsCanBeRequired`

#### Scenario: A schema-required field cannot be made optional
- GIVEN a create action whose schema requires `dateFrom` and whose `fieldConfigs.dateFrom` says `required: false`
- WHEN the contribution is normalised
- THEN `dateFrom` carries `required: true`
- @e2e exclude normalisation, covered by PHPUnit `RequiredFieldsNormaliserTest::testASchemaRequiredFieldCanNeverBeOptional`

#### Scenario: Config alone still requires nothing
- GIVEN an endpoint action without a schema whose `fieldConfigs.periodeTot.required` is `true` and whose `requiredFields` leaves `periodeTot` out
- WHEN the contribution is normalised
- THEN `periodeTot` carries no `required`
- @e2e exclude normalisation, covered by PHPUnit `RequiredFieldsNormaliserTest::testTheWooRequestNamesItsFiveFields`

### Requirement: The server MUST refuse a submit that leaves a required field empty (REQ-SMF-024)

A required field is never only a marker in the form. Portaliq MUST check every required field of the action on the server before it writes or forwards anything: a signed-in create, an anonymous create, an endpoint action, a row or attached action and a guest action. A field that is absent, null, blank text or an empty list MUST refuse the submit with status 400, `error: required_missing` and `errors` holding one entry per empty field: the action's `requiredMessage`, or empty text for the site to word. Nothing MUST be written, audited or forwarded on a refusal. The site MUST show these errors in its error summary (REQ-SMF-002), under each field as well.

#### Scenario: A booking without a time is refused before the write
- GIVEN learniq's `bookConferenceSlot` requires `slotId` and declares `requiredMessage: "Kies een tijd"` on it
- WHEN a guardian's create arrives with `slotId` blank
- THEN portaliq answers 400 with `errors: {slotId: "Kies een tijd"}`
- AND no object is written
- @e2e exclude server guard, covered by PHPUnit `ContributionControllerRequiredFieldsTest::testACreateWithAnEmptyRequiredFieldIsRefusedBeforeTheWrite`

#### Scenario: A Woo request without a subject is refused before the forward
- GIVEN dossiq's Woo endpoint action requires `onderwerp`
- WHEN a resident sends it without `onderwerp`
- THEN portaliq answers 400 naming `onderwerp`
- AND nothing is audited or forwarded to dossiq
- @e2e exclude server guard, covered by PHPUnit `ContributionControllerRequiredFieldsTest::testAnEndpointActionIsRefusedBeforeTheForward` and `PortalRowActionControllerTest::testAnEmptyRequiredFieldIsRefusedBeforeTheForward`

#### Scenario: The refusal shows in the summary
- GIVEN a page that still holds an older manifest, so the form let `slotId` through empty
- WHEN the server refuses with `errors: {slotId: ""}`
- THEN the error summary links "Tijd is verplicht." and takes focus
- @e2e exclude client mapping, covered by node test `tests/site-form-widgets.spec.mjs` ("the server refusal of an empty required field lands in the summary of an action form")

### Requirement: A draft of a create or endpoint action MUST stay with portaliq and the resident (REQ-SMF-021)

For a create or endpoint action with `draft`, the site MUST offer "Opslaan en later verdergaan" to a signed-in resident. Portaliq MUST store the visible answers and the step reached as one draft per subject, contribution and action, readable only by that subject. Portaliq MUST NOT send a draft to the contributing app. It MUST delete the draft when the action is sent and after `retentionDays`. A signed-out visitor MUST NOT get a draft. File answers MUST NOT be kept in a draft.

#### Scenario: Sanne continues her Woo request
- GIVEN Sanne saved a Woo request draft at step 2 on 2 October 2026 and the action keeps drafts 30 days
- WHEN she opens the action on 5 October 2026
- THEN step 2 opens with her answers
- AND dossiq holds no case for it

#### Scenario: Another resident cannot read the draft
- GIVEN Sanne's draft exists
- WHEN another signed-in resident asks for drafts of the same action
- THEN they receive none

#### Scenario: A draft expires
- GIVEN a draft saved 31 days ago on an action with `retentionDays: 30`
- WHEN the purge job runs
- THEN the draft is gone

### Requirement: A create or endpoint action MAY word its own confirmation (REQ-SMF-022)

A create or endpoint action MAY declare `confirmation` with `title`, `body` and `next`. The site MUST fill `{identifier}` and `{deadline}` from the action's answer. It MUST leave out a sentence whose placeholder has no value. Without `confirmation` the site MUST show `successMessage` as before.

#### Scenario: No deadline yet
- GIVEN the confirmation body "Uw zaaknummer is {identifier}. U krijgt uiterlijk {deadline} antwoord."
- AND the answer carries `identifier: 2026-0003` and no `deadline`
- WHEN the confirmation renders
- THEN it reads "Uw zaaknummer is 2026-0003." and nothing about a deadline
