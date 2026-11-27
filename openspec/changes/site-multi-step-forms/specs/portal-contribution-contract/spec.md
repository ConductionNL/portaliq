## ADDED Requirements

### Requirement: A create or endpoint action MAY run in steps with a review, a draft and a confirmation (REQ-SMF-020)

The contribution contract MUST accept on a create action and on an endpoint action with `fields`: `steps` (`[{ id, title, description?, fields[], review? }]`), `draft` (`{ retentionDays }`, an integer 1 to 90) and `confirmation` (`{ title, body?, next? }`). A step MUST be kept only when every field it names is one of the action's fields. The site MUST render such an action with the step flow of REQ-SMF-010 and REQ-SMF-011.

#### Scenario: The Woo request in four steps
- GIVEN dossiq's `startWooVerzoekAlgemeen` declares steps "Uw vraag", "Periode en documenten", "Uw gegevens" and a review step "Controleren en versturen"
- WHEN the resident opens the action on a site page
- THEN the form shows "Stap 1 van 4: uw vraag" and a progress list of four steps

#### Scenario: A step naming a field the action lacks is dropped
- GIVEN a step whose `fields` include `iban` and the action has no field `iban`
- WHEN the contribution is normalised
- THEN that step is dropped and its other fields go to the last step

#### Scenario: A review step carries no fields
- GIVEN an action whose last step "Controleren en versturen" declares `review: true`
- WHEN the contribution is normalised and the action renders
- THEN that step carries no fields
- AND it is rendered as the review of REQ-SMF-011

#### Scenario: Fields in no step go in a last step of their own
- GIVEN an action whose `fields` hold `toelichting` and whose steps do not name it
- WHEN the contribution is normalised
- THEN `toelichting` is placed in a last step of its own

#### Scenario: The Woo endpoint action runs in steps
- GIVEN dossiq's `startWooVerzoek` is an endpoint action posting to `/api/portal/woo-verzoek` and declares the four steps
- WHEN the contribution is normalised
- THEN the action keeps its `steps`, `draft` and `confirmation`

### Requirement: An action may name its required fields (REQ-SMF-023)

Ruben decided on 3 October 2026: "Let an action name its required fields". A create action, and an endpoint action with `fields`, MAY declare `requiredFields`, a list of field names. The contract MUST keep only names in the action's own `fields` that are not a file field and that the server does not fill itself, and drop a malformed list whole. It MUST write as `fieldConfigs.<field>.required: true` the names kept and, on a create action, the fields its schema requires.

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

#### Scenario: A field the server fills cannot be required
- GIVEN an action whose `requiredFields` names `status`, a key of its `defaults`, and its `subjectField`
- WHEN the contribution is normalised
- THEN neither is kept in `requiredFields` and neither carries `required`

#### Scenario: A malformed list is dropped whole
- GIVEN an action whose `requiredFields` is the text `"onderwerp"` instead of a list
- WHEN the contribution is normalised
- THEN the action keeps no `requiredFields` and no field carries `required` from it

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

#### Scenario: The site marks a required field
- GIVEN an action that names `slotId` in `requiredFields` and declares `requiredMessage: "Kies een tijd"` on it
- WHEN the form renders and is sent with `slotId` empty
- THEN `slotId` carries no "(niet verplicht)" and has `aria-required` (REQ-SMF-001)
- AND the error summary links "Kies een tijd" (REQ-SMF-006)

### Requirement: The server MUST refuse a submit that leaves a required field empty (REQ-SMF-024)

A required field is never only a marker in the form. Portaliq MUST check every required field of the action on the server before it writes or forwards anything, for every kind of action. A field that is absent, null, blank text or an empty list MUST refuse the submit with status 400, `error: required_missing` and `errors` holding one entry per empty field. Nothing MUST be written, audited or forwarded on a refusal.

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

#### Scenario: Every kind of action is checked
- GIVEN a required field left empty
- WHEN it arrives on a signed-in create, an anonymous create, an endpoint action, a row or attached action, or a guest action
- THEN each answers 400 with `error: required_missing` before anything is written or forwarded

#### Scenario: Without its own message the entry is empty
- GIVEN a required `onderwerp` without a `requiredMessage`
- WHEN the submit is refused
- THEN `errors.onderwerp` is empty text, for the site to word
- AND the site shows the message in its error summary (REQ-SMF-002) and under the field

#### Scenario: The refusal shows in the summary
- GIVEN a page that still holds an older manifest, so the form let `slotId` through empty
- WHEN the server refuses with `errors: {slotId: ""}`
- THEN the error summary links "Tijd is verplicht." and takes focus
- @e2e exclude client mapping, covered by node test `tests/site-form-widgets.spec.mjs` ("the server refusal of an empty required field lands in the summary of an action form")

### Requirement: A draft of a create action MUST be the record itself, in status draft, and only its resident's (REQ-SMF-021)

For a create action with `draft`, the site MUST offer "Opslaan en later verdergaan" to a signed-in resident. The draft MUST be the object the action creates, saved in its destination with OpenRegister status `draft` (decision 180, amended by submit-creates-the-case-directly), owned by `portal:<subjectRef>` so only that resident reads, saves, sends or discards it (decision 195). Portaliq MUST keep no draft store of its own.

#### Scenario: Sanne continues her Woo request
- GIVEN Sanne saved a Woo request draft on 2 October 2026 and the action keeps drafts 30 days
- WHEN she opens the action on 5 October 2026
- THEN the first step with a missing answer opens with her answers; in Mijn zaken the draft reads "nog niet ingediend" with "Formulier afmaken" (board MijnZaken)
- AND dossiq holds the case in status `draft`, without a case number, hidden from staff work lists unless they filter for drafts

#### Scenario: A draft may miss answers but not hold a wrong kind
- GIVEN Sanne's draft without a start date and with the text "morgen" in the date field `periodeTot`
- WHEN she saves the draft
- THEN the missing start date is accepted
- AND `periodeTot` is refused on its field

#### Scenario: A saved draft reads "Voorlopig" and its files go with it
- GIVEN Sanne picked one file and saved the draft
- WHEN the draft shows on the site
- THEN it reads "Voorlopig" (decision 200), without a case number or received moment (decision 201)
- AND the file is uploaded into the draft

#### Scenario: No draft for a signed-out visitor or an endpoint action
- GIVEN a signed-out visitor on a create action with `draft`, or a resident on an endpoint action
- WHEN the form renders
- THEN it offers no "Opslaan en later verdergaan" (anonymous drafts wait on Q-openregister-F2; an endpoint action has no destination object)

#### Scenario: Another resident cannot read the draft
- GIVEN Sanne's draft exists
- WHEN another signed-in resident asks for drafts of the same action
- THEN they receive none

#### Scenario: A draft expires
- GIVEN a draft last changed 31 days ago on an action with `retentionDays: 30`
- WHEN Sanne opens the action
- THEN no draft is offered and the draft is discarded

#### Scenario: Without its own retention a draft keeps 30 days
- GIVEN an action with `draft` that names no `retentionDays`, and a draft last changed 31 days ago
- WHEN Sanne opens the action
- THEN the draft reads as gone (decision 201)

#### Scenario: Sending makes it definitive
- GIVEN Sanne's draft now holds every required answer
- WHEN she sends the action
- THEN the same object leaves `draft` and gets its case number, and no second object is created

### Requirement: A create or endpoint action MAY word its own confirmation (REQ-SMF-022)

A create or endpoint action MAY declare `confirmation` with `title`, `body` and `next`. The site MUST fill `{identifier}` and `{deadline}` from the action's answer. It MUST leave out a sentence whose placeholder has no value. Without `confirmation` the site MUST show `successMessage` as before.

#### Scenario: No deadline yet
- GIVEN the confirmation body "Uw zaaknummer is {identifier}. U krijgt uiterlijk {deadline} antwoord."
- AND the answer carries `identifier: 2026-0003` and no `deadline`
- WHEN the confirmation renders
- THEN it reads "Uw zaaknummer is 2026-0003." and nothing about a deadline
