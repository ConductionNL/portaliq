## ADDED Requirements

### Requirement: The e-mail ask speaks the portal's words, or stays away

A portal MAY declare `contactPrompt`. With `show: false` the site MUST NOT show the prompt for a
missing e-mail address. With `text`, `button` or `dismiss` the site MUST show those words instead of its own.

#### Scenario: A pupil portal without the prompt
@e2e exclude Unit test in node: tests/site-look/mijn-overview-follows-the-boards.spec.mjs; PHPUnit PortalShellTest
- GIVEN a portal with `contactPrompt: {show: false}`
- WHEN a pupil without an e-mail address opens `/mijn`
- THEN no prompt stands above the overview

### Requirement: Pills are tinted

A data badge MUST draw its tone's ground and text colour without an outline, unless the theme names an outline colour.

#### Scenario: "Nog inleveren" as a tinted pill
@e2e exclude Unit test in node: tests/site-look/mijn-overview-follows-the-boards.spec.mjs
- GIVEN the vaartveld theme
- WHEN a homework row shows "Nog inleveren"
- THEN the pill has the warning tint and no border line
