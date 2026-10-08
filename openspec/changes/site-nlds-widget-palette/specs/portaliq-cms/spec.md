## ADDED Requirements

### Requirement: Every NL Design System component MUST be placeable or carry a reason (REQ-SNW-010)

For each of the 101 components listed on nldesignsystem.nl on 2026-10-02, the site MUST offer a standalone widget, a field type inside the form widget, or a recorded reason why not (part of another widget, inline in text, page shell, not offered). The record MUST live beside the widget registry so a test can count it. A widget MUST render the component's NL Design System class structure and import that component's CSS package where one exists. Where none exists, the widget MUST style itself from `--utrecht-*` tokens only.

#### Scenario: The count adds up
- GIVEN the widget registry and its record of reasons
- WHEN the coverage test runs
- THEN each of the 101 components has exactly one placement

#### Scenario: A component without upstream CSS uses tokens only
- GIVEN the Progress Bar widget, for which NL Design System publishes no CSS
- WHEN its stylesheet is checked
- THEN every colour in it is a `var(--utrecht-...)` reference

### Requirement: New widgets MUST stay out of the site entry and off the dashboard keys (REQ-SNW-011)

Every widget added by this change MUST load on demand, with its CSS in its own chunk. The site entry MUST contain no module of these widgets, and `npm run build:site` MUST stay within the budget in `webpack.site.js`. A new widget key MUST NOT equal a key of the shared dashboard widget registry. A key MUST render at a public origin if and only if it is in the public widget map.

#### Scenario: A table key does not reach the dashboard table
- GIVEN the shared registry has a non-public `table` widget
- WHEN the NL Design System table widget is added
- THEN its key is `nlTable`
- AND `table` still renders as a placeholder on a public page

#### Scenario: The entry does not grow
- GIVEN the site built after any wave of this change
- WHEN the build reports its entry chunk
- THEN no module under `src/site/widgets/` is in it

### Requirement: A Mijn omgeving widget MUST show only the visitor's own data (REQ-SNW-012)

A widget in the Mijn omgeving group MUST read only the signed-in visitor's data, through the same scoped reads the signed-in pages use. A placement MUST NOT be able to name another subject, widen a scope or point at an arbitrary endpoint. For a signed-out visitor it MUST show a sign-in prompt and fetch nothing.

#### Scenario: A case list on a CMS page, signed out
- GIVEN a CMS page with an `nlCases` widget
- WHEN a signed-out visitor opens it
- THEN the widget shows a sign-in prompt and sends no case request

### Requirement: The language switch renders and the chosen language reaches the content (REQ-SNW-013)

The site shell SHALL hand `#portal.locales` to every `nlLanguageNav` placement after the authored
props, so the switch renders whenever the portal serves more than one locale and an author cannot
add a locale the portal does not serve. Every content read the site makes (`contentApi.js`) SHALL
send the chosen locale as `locale`, and site links SHALL keep it. A page without a translation in the
chosen locale SHALL render in the portal's default locale and SHALL say so.

#### Scenario: A visitor reads the portal in English
- **GIVEN** a portal with locales `nl` and `en`, and a page with an English translation
- **WHEN** a visitor chooses English in the language switch
- **THEN** the page SHALL render its English text, and the next page they open SHALL also be read with `locale=en`

#### Scenario: The switch offers only the portal's languages
- **GIVEN** a portal with locales `nl` and `en`, and an `nlLanguageNav` placement whose props list `de`
- **WHEN** the page renders
- **THEN** the switch SHALL offer Nederlands and English and not Deutsch
