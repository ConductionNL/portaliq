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

### Requirement: The site MUST link the faces the theme app bundles (REQ-STB-002)

When the serving portal's theme resolves to a set and the installed theme app ships `css/fonts.css`, the site MUST link that stylesheet from the theme app, so a family the set names through `--nldesign-font-family` is declared on the page. It MUST be linked after this app's own font stylesheets and before the theme app's uploaded-font stylesheet and the token set. It MUST NOT be linked when the theme app ships no such file or when no set resolves. The stylesheet and the font files it names MUST be readable by a signed-out visitor.

#### Scenario: The example gemeente is drawn in Source Sans 3
- GIVEN a portal on the example gemeente set, which names "Source Sans 3"
- AND the theme app ships `css/fonts.css` declaring Source Sans 3 with `fonts/source-sans-3-latin-*.woff2`
- WHEN a signed-out visitor opens the portal's home page
- THEN the theme app's `css/fonts.css` is linked
- AND headings and body text render in Source Sans 3, not the fallback

#### Scenario: Order of the font stylesheets
- GIVEN a portal on a resolvable set whose theme app ships `css/fonts.css`
- WHEN the site template renders
- THEN `fonts.css` is linked after `nlds-fonts.css` and before the uploaded-font stylesheet and the set

#### Scenario: No bundled faces, no link
- GIVEN the installed theme app has no `css/fonts.css`, or the portal's theme does not resolve
- WHEN the site template renders
- THEN no link to the theme app's `css/fonts.css` is emitted
