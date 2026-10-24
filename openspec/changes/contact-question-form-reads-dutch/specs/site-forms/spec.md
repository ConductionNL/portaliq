# site-forms Delta: contact-question-form-reads-dutch

## ADDED Requirements

### Requirement: The contact question form reads in the page's language

The contact block SHALL render its form in the page's language, with the same words as the other site forms, and an optional field's label SHALL read its name, a space and the optional mark.

#### Scenario: A Dutch resident opens the contact question form
@e2e exclude needs a signed-in portal session and pipelinq's action; covered by tests/contact-form.spec.mjs
- **GIVEN** a resident signed in on a Dutch page
- **WHEN** she opens `/contact/vraag` and the form has a field that is not required
- **THEN** the form says "Een veld zonder "niet verplicht" moet u invullen."
- **AND** that field's label reads "<name> (niet verplicht)"
