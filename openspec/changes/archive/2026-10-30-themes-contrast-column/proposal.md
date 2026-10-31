# themes-contrast-column

## Why

The PtThemas board lists each portal with its domain, its theme, whether that theme is readable, and a link to change
the house style, with a note under the table explaining a theme that is not. The live page listed portal, theme and
status straight from the portal records: there was no contrast column, no link and no note, because contrast is not a
stored field.

## What changes

- A new admin endpoint `GET /api/themes/portals` answers, per published portal, its title, first domain, theme id and
  name, and a contrast verdict (`readable`, `poor` or `unchecked`), with the count of poor ones.
- The verdict is the one portaliq already gives when a theme is chosen (`PortalThemeContrast`, asking the theme app's
  own WCAG contrast service for the page and footer text at 4.5:1). No second contrast calculation.
- The Themes page table reads that endpoint: Portal (with the domain under it), Thema, Contrast as a pill and a
  "Huisstijl wijzigen" link to the portal. A warning note under it explains a poor theme, shown only while one is.
