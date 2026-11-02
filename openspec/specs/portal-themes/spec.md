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
