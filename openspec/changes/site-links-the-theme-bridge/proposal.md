# Proposal: site-links-the-theme-bridge

## Why

Forty of the theme app's 52 token sets do nothing on the site. Den Haag, Tilburg, Amsterdam and Rijkshuisstijl are among them. Each of those sets declares only `--nldesign-*` tokens. The site paints from `--utrecht-*`, `--tilburg-*` and `--conduction-*` roles. Nothing maps one family onto the other, so a portal on the `denhaag` set loads the file and keeps the default look.

The theme app already ships that mapping: `css/public-bridge.css` (thematiq#355, merged). Portaliq never links it. Verified on `development` (b150def5):

- `templates/site.php` builds `$tokenStylesheets` from `themeStylesheet` (the set) and `nldsStylesheet` only.
- `git grep public-bridge origin/development` finds the name only in two unbuilt change documents: `portal-theme-blocks-and-contributed-pages` (task 2) and `site-reaches-portal-parity` (design, "does **not** link it yet").
- thematiq's `docs/features/public-portals-as-consumers.md` says the bridge "is linked **before** the set". For portaliq that is not yet true.

Counted in the theme app on `development` (d94f60d1): 52 files under `css/tokens/`, 12 declare a `--utrecht-*` token, 40 do not.

This change is small. It is a few lines in `templates/site.php`, one resolver method and their tests. It is written as its own change because the larger change that names it has not moved, and because its stated order is wrong (see design D1).

## What changes

- When a portal's theme resolves to a set, the site links the theme app's `css/public-bridge.css` directly before that set.
- The bridge sits in the token layer, after the vendored NLDS sheets and `css/site-theme.css`. It does not go first in the document.
- No set, no bridge. A portal without a resolvable theme renders exactly as today.
- No file, no link. When the installed theme app has no `css/public-bridge.css`, nothing is linked.
- The 12 sets that declare their own component roles (`vng`, `rotterdam`, `conduction`, the example school sets and others) keep their values: they load after the bridge and win on order.

## Relation to other changes

- `portal-theme-blocks-and-contributed-pages` task 2 links "bridge, set chain, vendored sheets, `site-theme.css`". This change takes over the bridge half of that task and corrects its position. The token-only `site-theme.css` half stays there.
- `site-mijn-omgeving-components` needs `--denhaag-*` tokens. Those are fed by the theme app, not by this change. This change is the precondition: without the bridge, no `--nldesign-*` value reaches any component role.

## Not in this change

- Dark mode. `site.php` keeps it off on purpose (the generated dark sets leave surfaces white, see thematiq's consumer doc, section 3).
- Writing component roles into the 40 sets. thematiq#355 rejected that: one mapping beats forty copies.
- Any change in the theme app. The bridge exists. This change only consumes it.

## Affected projects

- portaliq: `templates/site.php`, `lib/Service/PortalThemeResolver.php`, `lib/Controller/PortalPageController.php`, tests.
- thematiq (nldesign): none. Read-only consumer of `css/public-bridge.css`.
