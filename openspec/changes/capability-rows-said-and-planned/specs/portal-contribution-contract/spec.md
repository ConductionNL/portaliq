## ADDED Requirements

### Requirement: An app joins the portal by convention alone (REQ-RSP-003)

Portaliq SHALL find a contributing app by a provider class at a conventional name in that app, and SHALL read its contribution without either app depending on the other's code. An installed app without such a class SHALL simply contribute nothing.

#### Scenario: A newly installed app appears in the portal
- **GIVEN** an app that ships a portal contribution provider at the conventional class name
- **WHEN** it is installed and enabled next to portaliq
- **THEN** its collections and actions appear for the audiences it names, with no change in portaliq or in that app

#### Scenario: An app without a provider
- **WHEN** an app without a contribution provider is installed
- **THEN** the portal shows nothing from it and nothing fails

### Requirement: A contributed page is built from typed blocks (REQ-RSP-004)

A contribution SHALL be able to declare pages made of typed blocks: a data list, an action form, a detail card, rich text and a call to action. The portal SHALL render the blocks in the order given. A contribution without pages SHALL get one default page per listable collection.

#### Scenario: A page with a list, a text and an action
- **GIVEN** a contribution page with a rich text block, a collection block and an action block
- **WHEN** a resident opens that page
- **THEN** the text, the list and the form show in that order

### Requirement: A contribution says how its data reads (REQ-RSP-005)

A contribution SHALL be able to say, without portaliq code, which columns a collection shows, which field reads as a badge, which value reads as an amount of money, the label of each field and where a dropdown in an action takes its options from. A setting for a field the collection does not expose SHALL be dropped.

#### Scenario: Columns, a badge and an amount
- **GIVEN** a collection declaring the columns reference, status and amount, status as a badge and amount as euros
- **WHEN** a resident opens the list
- **THEN** the list shows those three columns, the status as a badge and the amount as "€ 12,50"

#### Scenario: A dropdown takes its options from a source
- **GIVEN** an action whose field "type" names an options source
- **WHEN** the resident opens the action form
- **THEN** the dropdown lists the options that source answers

### Requirement: An anonymous visitor reads public contributed content (REQ-RSP-006)

Portaliq SHALL show a visitor who is not signed in the read-only content that contributing apps declare for anonymous visitors, and nothing that needs a higher trust level.

#### Scenario: Public opening hours from a contributing app
- **GIVEN** an app that declares a read-only collection for anonymous visitors
- **WHEN** a visitor who is not signed in opens the page that shows it
- **THEN** they see that collection, and no collection meant for signed-in residents
