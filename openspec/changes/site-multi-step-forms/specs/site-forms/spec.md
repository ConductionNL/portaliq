## ADDED Requirements

### Requirement: A site form MUST mark the fields that are not required (REQ-SMF-001)

Every form the site renders (the published intake form, a create or update action, the landing page form) MUST show "(niet verplicht)" inside the label or legend of each field that is not required. It MUST NOT mark required fields with a symbol. A required field MUST carry `aria-required="true"`. When a form mixes required and optional fields, it MUST say above the first field that a field without "niet verplicht" must be filled in.

#### Scenario: The explanation is optional
- GIVEN the absence action of `LearniqAbsence.dc.html`, where `reason` is not required
- WHEN the guardian opens the form
- THEN the label reads "Wilt u iets toelichten? (niet verplicht)"
- AND no label on the form carries an asterisk
- AND the text above the fields reads: Een veld zonder "niet verplicht" moet u invullen.

#### Scenario: A required field is announced as required
- GIVEN a required field on a published intake form
- WHEN a screen reader reaches its input
- THEN the input has `aria-required="true"`

### Requirement: A failed submit MUST show an error summary that takes focus (REQ-SMF-002)

When a submit or a step fails validation, on the client or on the server, the form MUST show an error summary above its fields. The summary heading MUST receive keyboard focus. The summary MUST list one link per error, in field order. Activating a link MUST move focus to that field, or to the first input of a field group. Each field's own message MUST stay under the field and be referenced by its `aria-describedby`. While errors stand, the document title MUST start with "Fout: ".

#### Scenario: A guardian forgets the last day
- GIVEN the absence form with "Tot en met welke dag?" left empty
- WHEN the guardian presses "Melding versturen"
- THEN a summary with the heading "Er ontbreekt nog iets" appears above the fields and has focus
- AND it holds the link "Kies de laatste dag dat Vera afwezig is"
- AND following the link puts focus in the "Tot en met welke dag?" field

#### Scenario: A server refusal lands in the same summary
- GIVEN a published form whose server validation rejects the field `email`
- WHEN the resident submits
- THEN the summary lists the server's message for `email` as a link
- AND the message also shows under the `email` field

### Requirement: A date field MUST be asked as day, month and year (REQ-SMF-003)

A field whose input is a date MUST render as a fieldset whose legend is the question, with three labelled inputs: Dag, Maand and Jaar. The inputs MUST use a numeric keyboard on a phone. The form MUST send the date as `yyyy-mm-dd`. An incomplete or impossible date MUST fail with a message that gives an example. A date-time field MAY keep the browser's own input.

#### Scenario: A Woo request asks for a start date
- GIVEN the Woo request step "Periode en documenten" of `DossiqWoo.dc.html`
- WHEN the resident fills Dag 1, Maand 3, Jaar 2026
- THEN the answer sent is `2026-03-01`

#### Scenario: 31 February is refused
- GIVEN a date group filled with 31, 2, 2026
- WHEN the resident goes to the next step
- THEN the step does not advance and the summary names the date field

### Requirement: A file field on an action MUST look like a button and list the chosen file (REQ-SMF-004)

A file field on a create or update action MUST render a real file input behind a label styled as a secondary button. It MUST show a hint with the size limit when the action declares one. After a choice it MUST list each chosen file by name with a control to remove it. It MUST remain operable by keyboard and announce the chosen file.

#### Scenario: A guardian adds a photo of the appointment card
- GIVEN an action with a file field, drawn as "Bijlage" in `LearniqAbsence.dc.html`
- WHEN the guardian chooses `afsprakenkaart.jpg`
- THEN the form lists "afsprakenkaart.jpg" with a remove control
- AND the button reads "Bestand of foto kiezen"

### Requirement: An action field MAY ask for choice cards or named days (REQ-SMF-005)

The contribution contract MUST accept `fieldConfigs.<field>.widget` with the values `choices` and `dateChoices`, and drop any other value. `choices` on a field with options MUST render one radio card per option in a fieldset, sending the same value the select would. `dateChoices` on a date field MUST offer today and the next days the action names (1 to 5, default 2), named in the site's language, plus "Een andere dag", which opens the date group. Neither value changes what is sent or how it is validated.

#### Scenario: The reason as three cards
- GIVEN learniq's absence action declares `fieldConfigs.reasonKind.widget: choices`
- WHEN the guardian opens the form
- THEN "Waarom is Vera afwezig?" shows three radio cards: "Ziek", "Dokter of tandarts", "Een andere reden"

#### Scenario: An unknown widget falls back
- GIVEN an action declares `fieldConfigs.reasonKind.widget: slider`
- WHEN the contribution is normalised
- THEN the field config has no `widget` and the field renders as a select
