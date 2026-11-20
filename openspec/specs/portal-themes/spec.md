# portal-themes Specification

## Purpose
The themes overview in the admin: per portal whether its theme is readable, with the contrast, a link and a note.

## Requirements

### Requirement: The themes overview says per portal whether its theme is readable

The app SHALL answer `GET /api/themes/portals` (administrators only) with a row per published portal: `id`, `slug`,
`title`, `domain` (the first hostname, or empty), `theme`, `themeName` and `contrast`. `contrast` SHALL be `readable`
when the theme's contrast verdict was evaluated and passes, `poor` when it was evaluated and fails, and `unchecked` when
no verdict could be given (no theme, a theme that does not resolve, or no theme app to measure with). The verdict SHALL
be `PortalThemeContrast::evaluate()` over the theme's tokens, the same one a theme choice is checked with. The answer
SHALL also carry `poorCount`, the number of `poor` rows.

#### Scenario: a theme whose footer text fails 4.5:1

- **GIVEN** a published portal whose theme's footer text measures below 4.5:1 on its footer background
- **THEN** its row has `contrast: "poor"` and `poorCount` is at least 1

#### Scenario: no theme app

- **GIVEN** the theme app is not installed
- **THEN** every row has `contrast: "unchecked"`, never `readable`

### Requirement: The Themes page shows the contrast, a link and the note

The Themes page table SHALL read `/api/themes/portals` and show Portal (the domain as a second line), Thema, Contrast as a
pill (readable green, poor amber, unchecked neutral) and a "Huisstijl wijzigen" link to the portal's page. Under it a
warning note SHALL explain a poor theme while `poorCount` is above 0.

#### Scenario: PtThemas

- **GIVEN** three portals, one with a theme whose footer text fails
- **THEN** that row's pill reads "Slecht leesbaar" and the note under the table explains what that means and where to
  change the house style

### Requirement: A secondary button on a portal site takes the hover step on hover

On a portal site an outlined (secondary) Utrecht button SHALL use the secondary hover tokens on hover:
`--utrecht-button-secondary-action-hover-color` for its text and `--utrecht-button-secondary-action-hover-border-color`
for its border, through the component's private `--_utrecht-button-hover-*` values, so a danger hint still wins. At rest
it SHALL keep its rest colours. The site theme's rest rule carries `!important` to beat the vendored stylesheets, so the
hover rule SHALL carry the same weight.

#### Scenario: a secondary button on hover
@e2e exclude the hover colour only differs from the rest colour when a theme set from the theme app is active on the portal, which the e2e instance does not install; pinned by tests/site-secondary-button-hover.spec.mjs, which reads the site stylesheet

- **GIVEN** a portal whose theme defines a hover step for the secondary button
- **WHEN** a visitor points at a secondary button such as "Aanvraag intrekken"
- **THEN** its text and border take the secondary hover colour
- **AND** when the pointer leaves, they return to the rest colour
