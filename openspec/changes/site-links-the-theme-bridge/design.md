# Design: site-links-the-theme-bridge

## Context

`templates/site.php` assembles the stylesheets in this order on `development`:

1. the vendored NLDS sheets (`css/nlds/nlds-components`, `-vendor-a`, `-vendor-b`, `-app`, `-controls`)
2. `css/site-theme.css`
3. `css/nlds/nlds-fonts.css`, the licensed fonts when present, the theme app's font route
4. the token layer: the set (`themeStylesheet`), then `nldsStylesheet`

The comment above the token layer explains why it goes last. A token declared later wins over the same custom property declared earlier. `nlds-app.css` declares `--conduction-primary-top-nav-background-color: #fff` on `:root`. With the tokens first, the navigation bar rendered white.

## D1. The bridge goes first in the token layer, not first in the document

`portal-theme-blocks-and-contributed-pages` D1 orders "bridge, set chain, vendored sheets, `site-theme.css`". That puts the bridge before the vendored sheets. Measured on `development` (b150def5) against thematiq `development` (d94f60d1):

- the bridge declares 78 `--utrecht-*`, `--tilburg-*` and `--conduction-*` names;
- `nlds-app.css` declares 2 of them on `:root`: `--conduction-primary-top-nav-background-color` and `--conduction-primary-top-nav-color`;
- its other matches are scoped to `.tilburg-theme` or `.rotterdam-theme`, classes no element on the site carries (`grep -rn tilburg-theme src templates` is empty).

With the bridge before the vendored sheets, those two `:root` declarations would win. The header bar would stay white on every bridged set. That is the defect the token-layer comment already records.

So the order is:

```
vendored sheets, site-theme.css, fonts, [bridge], set, nldsStylesheet
```

The bridge still loads before the set, which is what thematiq's doc asks. A set that declares its own role wins on order.

## D2. Linked only with a resolved set

`stylesheetFor()` returns null for an unknown, uncatalogued, missing or refused set. Then the site renders unstyled, by requirement ("A portal's theme MUST change what a visitor sees"). The bridge carries fallbacks such as `#1b1b23` for text. Linking it without a set would quietly restyle unthemed portals. So the bridge follows the set: no set, no bridge.

## D3. Existence is checked on disk

Nextcloud answers a missing app asset with 401, not 404 (recorded in `site.php` for the licensed fonts). A theme app without the file must produce no link. The resolver checks `is_file(<theme app>/css/public-bridge.css)`, as `stylesheetFor()` does for a set.

## D4. One resolver method

`PortalThemeResolver::bridgeStylesheet(): ?string` returns `'public-bridge'` or null. It uses the existing private `themeAppPath()`. `PortalPageController` passes it to the template as `themeBridgeStylesheet` next to `themeStylesheet`. The template prepends it to `$tokenStylesheets` only when `themeStylesheet` is not empty.

## Risks

- A set that relies on the vendored default for a role the bridge now sets will change look. That is the intent for the 40 bare sets. For the 12 sets with roles, the set wins on order. The live check compares `vng` before and after.
- `site-theme.css` uses `--nldesign-hero-*` with `--utrecht-*` fallbacks. Those keep working.
