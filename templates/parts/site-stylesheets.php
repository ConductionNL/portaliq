<?php
// SPDX-License-Identifier: EUPL-1.2
//
// THE STYLESHEETS OF THE SITE, SHARED BY BOTH SHELLS (site-honest-without-javascript
// REQ-SHJ-002): `site.php` (the JavaScript site) and `site-plain.php` (the plain
// version the server renders) include this one file inside their <head>, so the
// plain page wears exactly the theme the site wears. It resolves the asset urls
// and emits the stylesheet links and the theme <style> lines; it leaves $appId,
// $appManager, $url, $appRoot, $themeApp, $asset and $themeStylesheet defined
// for the including template.

declare(strict_types=1);

use OCA\Portaliq\Service\PortalThemeResolver;

/** @var array $_ */
$appId = 'portaliq';

// Cache-buster from the FILE'S OWN MTIME, not the app version.
//
// Nextcloud's `?v=` is keyed on the app version, which does not move when you
// rebuild a bundle — so a rebuilt asset keeps its URL and browsers keep the
// old bytes until someone hard-reloads. Measured here: the served bundle
// carried the fix, the disk carried the fix, and the page still ran the
// previous build. Keying on mtime means every rebuild is a new URL and the
// question never arises.
$appManager = \OCP\Server::get(\OCP\App\IAppManager::class);
$url = \OCP\Server::get(\OCP\IURLGenerator::class);
$appRoot = $appManager->getAppPath($appId);

// Which id the theme app is installed under here — it is mid-rename from
// `nldesign` to `thematiq`, and both spellings are in the field. Null when no
// theme app is installed at all, which is a normal deployment: the portal then
// renders unthemed rather than linking assets into an app that is not there.
$themeApp = \OCP\Server::get(PortalThemeResolver::class)->themeAppId();

$asset = static function (string $app, string $path) use ($url, $appManager): string {
    $href = $url->linkTo($app, $path);
    try {
        $file = $appManager->getAppPath($app) . '/' . $path;
        $stamp = is_file($file) ? (string)filemtime($file) : '0';
    } catch (\Throwable) {
        // An app we cannot locate still gets a URL — just an unversioned one.
        $stamp = '0';
    }

    return $href . '?v=' . urlencode($stamp);
};

$themeStylesheet = (string)($_['themeStylesheet'] ?? '');
$nldsStylesheet = (string)($_['nldsStylesheet'] ?? '');

// STYLESHEET ORDER IS LOAD-BEARING, AND THE TOKENS GO LAST.
//
// They used to go first, on the reasoning that "the component CSS resolves
// against the serving portal's values". That reasoning does not hold for custom
// properties: `var()` is resolved where it is USED, not where it is declared, so
// a token declared after the rule that reads it still applies. What order DOES
// decide is which declaration of the same custom property wins.
//
// And the component CSS declares its own. Measured: `nlds-app.css` sets
// `--conduction-primary-top-nav-background-color: #fff` on `:root`, so with the
// token file first the navigation bar rendered WHITE instead of rgb(0, 120, 200)
// — the design system overriding the theme with its own fallback.
//
// This did not surface earlier only because the theme file then in use scoped
// its tokens to `.vng-theme`, a class on the renderer's root DIV. A custom
// property is inherited from the NEAREST ancestor that declares it, so the div
// beat `html` whatever the stylesheet order was. Moving the tokens into
// nldesign — where a shipped set must be one flat `:root` block, because a
// shared applier rewrites that selector to scope it — removed that proximity
// advantage and exposed the ordering for what it always was.
$stylesheets = [];
$tokenStylesheets = [];
// THE BRIDGE GOES FIRST IN THE TOKEN LAYER, directly before the set, and only
// with one (site-links-the-theme-bridge). It maps the `--nldesign-*` layer
// every set defines onto the component roles this page paints from; 40 of
// the theme app's 52 sets define nothing else. It must still come AFTER the
// vendored sheets: `nlds-app.css` declares two of its names on `:root`
// (`--conduction-primary-top-nav-background-color` and `-color`), and the
// later declaration wins. A set that declares a role of its own loads after
// the bridge and keeps its value.
$themeAppSheets = (array)($_['themeAppSheets'] ?? []);
$themeBridgeStylesheet = (string)($themeAppSheets['bridge'] ?? '');
$themeFontStylesheet = (string)($themeAppSheets['fonts'] ?? '');
if ($themeStylesheet !== '' && $themeApp !== null) {
    if ($themeBridgeStylesheet !== '') {
        $tokenStylesheets[] = $asset($themeApp, 'css/' . $themeBridgeStylesheet . '.css');
    }

    // A set that extends another loads its parent first, so the child wins
    // on order (REQ-PTB-003).
    foreach ((array)($_['themeParents'] ?? []) as $parentSheet) {
        $tokenStylesheets[] = $asset($themeApp, 'css/' . (string)$parentSheet . '.css');
    }

    $tokenStylesheets[] = $asset($themeApp, 'css/' . $themeStylesheet . '.css');
}

if ($nldsStylesheet !== '') {
    $tokenStylesheets[] = $asset($appId, 'css/' . $nldsStylesheet . '.css');
}

// THE LOGO URL HAS TO BE ABSOLUTE, and the reason is a CSS resolution rule
// that only bites once a token crosses an app boundary.
//
// Token sets declare `--nldesign-logo-url: url('../../img/logos/x.svg')`,
// which is correct relative to the token file. But the rule that CONSUMES it
// lives in this app's `nlds-app.css`, and the browser resolves a relative
// `url()` inside a custom property against the stylesheet doing the consuming.
// Measured: the header requested
//   /custom_apps/portaliq/img/logos/opencatalogi.svg
// — this app's directory, where no such file exists — and the portal rendered
// with NO logo at all while every token involved held exactly the right value.
//
// So the app that knows where the theme app lives resolves it, once, here.
$themeLogoUrl = (string)($_['themeLogoUrl'] ?? '');
// The set's light logo for the dark footer band, absolute for the same reason
// (site-chrome-follows-the-design); '' when the set ships none.
$themeLogoInverseUrl = (string)(($_['themeAppSheets'] ?? [])['logoInverse'] ?? '');
$themeEmblemUrl = (string)(($_['themeAppSheets'] ?? [])['emblem'] ?? '');
// The emblem in grey, for a set whose watermark carries no tint
// (example-site-zuiddrecht); '' when the set ships none.
$themeEmblemGreyUrl = (string)(($_['themeAppSheets'] ?? [])['emblemGrey'] ?? '');

// NO DARK LAYER IS LINKED HERE, AND THAT IS A MEASURED DECISION.
//
// The theme app generates `css/tokens/dark/{set}.css` and linking it is one
// line. Both versions of that file were rendered and measured on this page:
//
//   the artefact as generated before  0 of 1,152,000 pixels changed. It rewrites
//                                     `--nldesign-color-*`, and this page is
//                                     painted from `--utrecht-*`. A dark mode
//                                     that changes nothing reads as a working one.
//   the artefact after the theme app  53% of pixels changed and 10 of 11 text
//   was fixed to darken those too     nodes fell below 4.5:1 — #e5e5e5 headings
//                                     left on the white bands, ratio 1.26.
//
// The reason is upstream of the theme app: this site has no token-driven
// surface layer. Its bands paint their own backgrounds and the page itself is
// unpainted (the white is the browser canvas — no rule sets it). Darkening the
// text tokens while the surfaces stay light is strictly worse than having no
// dark mode, and this is a public government portal.
//
// So dark mode waits for the surfaces to read their tokens. Painting `body`
// from `--utrecht-document-*` was tried and verified harmless (0 pixels changed
// in light mode) but insufficient on its own — the inner bands stayed white.
foreach (['nlds/nlds-components', 'nlds/nlds-vendor-a', 'nlds/nlds-vendor-b', 'nlds/nlds-app', 'nlds/nlds-controls'] as $sheet) {
    $stylesheets[] = $asset($appId, 'css/' . $sheet . '.css');
}

// The site's own rules, after the vendored sheets so a rule that ties on
// specificity wins on order: the footer bands restate the vendored positional
// rules against role classes (portal-theme-blocks-and-contributed-pages
// REQ-PTB-005). Token references only.
$stylesheets[] = $asset($appId, 'css/site-theme.css');

// LAST, AND THE POSITION IS THE WHOLE MECHANISM.
//
// The vendored sheets above were captured from the reference application,
// where they are served from that site's ROOT, so their `@font-face` urls are
// root-relative (`/static/fonts/…`). A root-relative url resolves against the
// ORIGIN, not the stylesheet, so from `/apps/portaliq/css/nlds/` they ask
// Nextcloud for a path this app does not own — measured: four requests, four
// 404s, `document.fonts` reporting `Roboto 400/500/700 error`, and the whole
// portal quietly drawn in Arial.
//
// `nlds-fonts.css` re-declares the same families and weights with urls
// relative to itself. Same family + weight + style means the LAST declaration
// wins, so this link must stay after the vendored ones.
$stylesheets[] = $asset($appId, 'css/nlds/nlds-fonts.css');

// THE LICENSED FACES ARE LINKED ONLY WHEN THEY EXIST, AND THE CHECK IS THE FIX.
//
// `css/fonts/licensed/` is a gitignored drop-in slot, so on every deployment
// without a Monotype licence — which is every deployment this repository ships
// to — it is empty. The slot used to be declared inside `nlds-fonts.css`,
// which is linked unconditionally, so the browser requested a file that was
// not there. Nextcloud answers a missing app asset with **401**, not 404, so
// an anonymous visitor to a public government portal collected a red
// `401 (Unauthorized)` on every single page load; the failure then fell back
// to the vendored root-relative Avenir face and logged a `404` as well.
// Measured in the CI trace for e2e S24, which exists to catch precisely this.
//
// A sheet linked only when its resources are on disk cannot fail that way at
// all. `nlds-fonts.css` keeps a neutralised Avenir face that ends in a shipped
// Roboto file, so the unlicensed rendering is unchanged and silent; this sheet
// comes after it, so a licensed deployment still gets the real face.
if (is_file($appRoot . '/css/fonts/licensed/avenir-lt-55-roman.woff2') === true) {
    $stylesheets[] = $asset($appId, 'css/nlds/nlds-fonts-licensed.css');
}

// THE FACES AN ADMINISTRATOR UPLOADED IN THE THEME APP (nldesign-theme-integration
// 2.3), from its own public stylesheet: `FontController::css()` is public on
// purpose, because a CSS font load carries no session. It is empty until a
// face is uploaded, so a portal without custom fonts pays one cached request.
//
// It does not replace `nlds-fonts.css`: that file re-declares the vendored
// design system's own faces (root-relative urls, see above), a different set
// of fonts solving a different problem. Linked only when the installed theme
// app has font uploads at all.
// THE FACES THE THEME APP BUNDLES (site-links-the-theme-bridge REQ-STB-002).
// A set names its family through `--nldesign-font-family` (Source Sans 3 for
// the example gemeente); the theme app's `css/fonts.css` declares the faces
// with urls relative to itself, so it is linked from the theme app, as a
// static file a guest can read. Only when the controller names it: shipped,
// and the portal on a resolved set. After this app's own faces, so a bundled
// family wins over a same-named vendored one; before the uploaded faces
// below, so an administrator's upload wins over both.
if ($themeFontStylesheet !== '' && $themeApp !== null) {
    $stylesheets[] = $asset($themeApp, 'css/' . $themeFontStylesheet . '.css');
}

$fontRoute = \OCP\Server::get(PortalThemeResolver::class)->fontStylesheetRoute();
if ($fontRoute !== null) {
    $stylesheets[] = $url->linkToRoute($fontRoute);
}

// The token layer, last, so a theme's value beats the component CSS's own
// `:root` fallback for the same custom property.
foreach ($tokenStylesheets as $href) {
    $stylesheets[] = $href;
}

?>
    <?php foreach ($stylesheets as $href) { ?>
    <link rel="stylesheet" href="<?php p($href); ?>">
    <?php } ?>
    <?php $themeTokenCss = (string)($_['themeTokenCss'] ?? ''); ?>
    <?php if ($themeTokenCss !== '') { ?>
    <!-- The portal's own token overrides, after its theme (REQ-PTB-002). Already filtered by PortalTokenCss. -->
    <style><?php print_unescaped($themeTokenCss); ?></style>
    <?php } ?>
    <?php if ($themeLogoUrl !== '') { ?>
    <!--
        Emitted AFTER the token stylesheets so it wins, and inline because the
        value is only knowable at request time — see the note where
        $themeLogoUrl is resolved.
    -->
    <style>:root{--nldesign-logo-url:url("<?php p($themeLogoUrl); ?>")}</style>
    <?php } ?>
    <?php if ($themeLogoInverseUrl !== '') { ?>
    <style>:root{--nldesign-logo-inverse-url:url("<?php p($themeLogoInverseUrl); ?>")}</style>
    <?php } ?>
    <?php if ($themeEmblemUrl !== '') { ?>
    <style>:root{--nldesign-emblem-url:url("<?php p($themeEmblemUrl); ?>")}</style>
    <?php } ?>
    <?php if ($themeEmblemGreyUrl !== '') { ?>
    <style>:root{--nldesign-emblem-grey-url:url("<?php p($themeEmblemGreyUrl); ?>")}</style>
    <?php } ?>
