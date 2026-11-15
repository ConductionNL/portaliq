## ADDED Requirements

### Requirement: Only the address of a footer contact line is a link

A footer contact line with an address whose text starts with a label and a colon ("E-mail: ...")
MUST render the label as text and only the words after it as the link. A line with an address and no
such label MUST link as a whole; a line without an address MUST be text.

#### Scenario: De Wilgenboom's e-mail line
@e2e exclude Render check in node: tests/site-look/home-boards.spec.mjs; live screenshot on :8092 in the PR
- GIVEN the contact line "E-mail: [e-mailadres]" with `href: "mailto:info@example.org"`
- WHEN the footer renders
- THEN "E-mail: " is text and only "[e-mailadres]" is a link

### Requirement: A hero action may be a text link or carry a chevron

A hero action with `style: "link"` MUST render as an underlined text link without a box; an action
with `chevron: true` MUST show a chevron after its label. Any other value keeps today's button.

#### Scenario: Vaartveld's hero
@e2e exclude Render check in node: tests/site-look/home-boards.spec.mjs
- GIVEN the actions "Kom kennismaken" with a chevron and "Lees wat er speelt op school" as a link
- WHEN the hero renders
- THEN the first is a button with a chevron and the second an underlined link beside it

### Requirement: The hero aside and date tiles follow the set

The month of a date tile MUST take the set's `--thematiq-date-month-text-transform`, and nothing
when the set names none. A framed event list inside the hero aside card MUST draw no frame of its
own.

#### Scenario: The academy's next course days
@e2e exclude CSS checked in node: tests/site-look/home-boards.spec.mjs; live screenshot on :8092 in the PR
- GIVEN the academy home with its course list in the hero aside, on the warmtepompacademie set
- WHEN it renders
- THEN the list sits in one white card and the months read "OKT", "NOV"
