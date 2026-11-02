## ADDED Requirements

### Requirement: The language switch offers the portal's locales and the choice reaches the content

The site MUST hand the language switch the serving portal's own `locales`, never a placement's. A language the visitor picks MUST travel on the address as `lang` and MUST be sent as `locale` on every content read.

#### Scenario: a portal with two locales shows the switch
- GIVEN a portal whose `locales` are `["nl", "en"]`
- WHEN a page that places the language switch is shown
- THEN the switch renders a link for "Nederlands" and one for "English"
- AND the link for the language in effect is marked current
- @e2e exclude pinned by `tests/language-nav.spec.mjs`, which renders the switch with the props the grid hands it

#### Scenario: a placement cannot add a language
- GIVEN a placement whose authored props name a locale the portal does not have
- WHEN the grid hands the switch its props
- THEN the switch offers only the portal's locales
- @e2e exclude pinned by `tests/language-nav.spec.mjs`

#### Scenario: the chosen language reaches the content
- GIVEN a visitor opened the site with `?lang=en`
- WHEN the site loads the portal, its menus, its glossary and the page
- THEN each request carries `locale=en`
- AND a link inside the site keeps `lang=en`
- @e2e exclude pinned by `tests/language-nav.spec.mjs`, which records the request URLs
