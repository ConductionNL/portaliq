## ADDED Requirements

### Requirement: The site MUST link the theme app's public bridge before a resolved token set (REQ-STB-001)

When the serving portal's theme resolves to a token set, the site MUST link the theme app's `css/public-bridge.css` directly before that set. The bridge MUST load after the vendored NLDS sheets and `css/site-theme.css`, so a vendored `:root` default cannot override it. When no set resolves, the site MUST NOT link the bridge. When the installed theme app has no `css/public-bridge.css`, the site MUST NOT emit a link for it.

#### Scenario: A Den Haag portal wears Den Haag colours
- GIVEN a portal whose `theme` is `denhaag`
- AND the `denhaag` set declares `--nldesign-color-primary` and no `--utrecht-*` token
- WHEN a visitor opens the portal's home page
- THEN the computed `--utrecht-document-color` equals the set's `--nldesign-color-text`
- AND the header bar's computed background is not the vendored white

#### Scenario: The bridge sits between the vendored sheets and the set
- GIVEN a portal on a resolvable set
- WHEN the site template renders
- THEN `public-bridge.css` is linked after `nlds-app.css` and `site-theme.css`
- AND it is linked directly before the set's stylesheet

#### Scenario: A set with its own component roles keeps them
- GIVEN a portal on the `vng` set, which declares `--utrecht-*` roles
- WHEN the site renders with the bridge linked
- THEN each `--utrecht-*` role the set declares computes to the set's value

#### Scenario: No theme, no bridge
- GIVEN a portal whose theme names no catalogued set
- WHEN the site template renders
- THEN neither a token set nor `public-bridge.css` is linked

#### Scenario: A theme app without the bridge file
- GIVEN the installed theme app has no `css/public-bridge.css`
- WHEN a portal on a resolvable set renders
- THEN the set is linked and no link to `public-bridge.css` is emitted
