<?php
// SPDX-License-Identifier: EUPL-1.2
//
// STANDALONE shell for the built-in SITE renderer (ADR-084).
//
// THIS TEMPLATE EMITS THE WHOLE DOCUMENT, and that is the point.
//
// It previously rendered through a Nextcloud layout. Even `RENDER_AS_BASE` —
// the barest one that still ships assets — pulls in `server.css` (587 rules)
// and the instance's theme chain (`lasuite.css`, `defaults.css`,
// `brand-override.css`, …). Measured against the reference implementation with
// that layout in place:
//
//     .ac-header__navigation-main   reference 1280x96@0    ours 1235x96@69
//     .logo-text                    reference Avenir       ours Marianne
//
// The heights and the nav typography already matched — the design system was
// working. What did not match was everything Nextcloud's own CSS still had an
// opinion about: the content column's width and offset, and bare `h1`. An
// ancestry walk showed every wrapper at width 1280 with zero padding, so the
// inset was not something this app could reset from its own root; it came from
// rules on `#content` and `h1` that outrank anything scoped here.
//
// Out-specifying `server.css` selector by selector is a losing game and an
// unreadable one. A public government portal should not be loading the host
// platform's stylesheet at all, so it no longer does: `RENDER_AS_BLANK` gives
// this file the entire response, and it links exactly the assets the portal
// needs — the NLDS token set, the NLDS component CSS, and the site bundle.
//
// WHAT THIS COSTS, stated plainly: `Util::addScript`/`addStyle` and the
// initial-state channel all live in the layout, so this file resolves its own
// URLs and passes config through a JSON `<script>` block instead. That block is
// `type="application/json"` deliberately — it is DATA, not code, so no CSP
// nonce is involved and nothing inline executes.

declare(strict_types=1);

use OCA\Portaliq\Service\PortalThemeResolver;

/** @var array $_ */
/** @var \OCP\IURLGenerator $urlGenerator */
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

// NOTE ON THE SCRIPT TAG (see the bottom of this file): Nextcloud's CSP is
// `script-src-elem 'strict-dynamic' 'nonce-…'` and it applies to this response
// even though no Nextcloud layout rendered it. A plain `<script src>` is
// BLOCKED — measured: the bundle never loaded and the page sat at its
// server-rendered title with an empty mount. `strict-dynamic` also means a
// host allowlist would be ignored, so `'self'` is not an escape either; the
// nonce is the only way in.
//
// `emit_script_tag()` is core's own template helper and stamps both the nonce
// and `defer`. Reaching for the nonce manager directly would mean importing
// `OC\Security\CSP\…` — private API, and it is not in `OCP` at all: trying
// that first returned a 500, "Could not resolve … ContentSecurityPolicyNonceManager".

$portalConfig = ($_['portalConfig'] ?? []);
$themeStylesheet = (string)($_['themeStylesheet'] ?? '');
$nldsStylesheet = (string)($_['nldsStylesheet'] ?? '');
$locale = (string)($_['locale'] ?? 'nl');

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
if ($themeStylesheet !== '') {
    // THE BRIDGE GOES FIRST, AND THE ORDER IS THE WHOLE MECHANISM.
    //
    // A shipped token set is a Layer-3 `--nldesign-*` override list — correct
    // for theming Nextcloud, and inert here, because this page paints from the
    // vendored `--utrecht-*` / `--tilburg-*` component roles. Measured with the
    // real `frankendesk` set applied: its violet resolved perfectly at
    // `--nldesign-color-primary` while `--utrecht-document-color` was unset and
    // the header painted transparent. The theme loaded and did nothing.
    //
    // `public-bridge.css` maps one family onto the other, once, for all 46
    // sets. It is linked BEFORE the set so that a set carrying component roles
    // of its own — `vng`, `conduction-new` — still wins on declaration order
    // and keeps its bespoke values. `var()` resolves against the element's
    // final computed value, so the bridge referencing tokens the set declares
    // afterwards is correct.
    $tokenStylesheets[] = $asset(PortalThemeResolver::THEME_APP, 'css/public-bridge.css');

    // The theme CHAIN — ancestors first. A set that declares `extends` in the
    // catalogue is a DELTA of its parent, so `frankendesk` ships the logo and
    // whatever Conduction deliberately changes instead of a hand-maintained
    // copy of `lasuite` that drifts the moment either side is edited.
    foreach (explode(',', $themeStylesheet) as $sheet) {
        if ($sheet !== '') {
            $tokenStylesheets[] = $asset(PortalThemeResolver::THEME_APP, 'css/' . $sheet . '.css');

            // NO DARK VARIANT IS LINKED, AND THIS IS THE SECOND MEASUREMENT
            // THAT SAYS SO.
            //
            // Task 2.6 gave the site a token-driven surface layer, which was
            // the recorded prerequisite, so the link was tried again. Measured
            // on `conduction-klant` with `prefers-color-scheme: dark`:
            //
            //   surfaces that changed:   0 of 8
            //   text below AA:           19 of 38  (was 0 of 38 in light)
            //   worst:                   1.03:1, #141414 footer text on #0a172f
            //
            // Strictly worse than no dark mode, on a public government portal.
            //
            // THE CAUSE IS EXACT, and it is the same shape as this app's
            // `--navigation-bar-height` finding: an alias resolves where it is
            // DECLARED. The generated dark file scopes its overrides to `body`
            // and redefines base colours there — `--c-white: #141414` — while
            // the light set declares `--conduction-color-background-default:
            // var(--c-white)` on `:root`. Read back live:
            //
            //   --c-white                              root #FFFFFF  body #141414
            //   --conduction-color-background-default  root #FFFFFF  body #FFFFFF
            //   --utrecht-document-background-color    root #FFFFFF  body #FFFFFF
            //
            // The surfaces therefore stay white while the direct text colours
            // darken, which is exactly how a footer ends up at 1.03:1.
            //
            // NO CHANGE HERE CAN FIX IT. Re-declaring the bridge on `body`
            // still reads an alias that resolves at `:root`; the chain breaks
            // at its first `:root`-declared link, whichever end it is read
            // from. The prerequisite is now the theme app's: the dark sets have
            // to redefine the ALIASES (or scope their block to `:root` inside
            // the media query), which is defect 1 of ConductionNL/nldesign#353
            // — recorded as fixed in the generator, but not present in the
            // artefacts this instance ships.
        }
    }
}

if ($nldsStylesheet !== '') {
    $tokenStylesheets[] = $asset($appId, 'css/' . $nldsStylesheet . '.css');
}

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
// `site-theme` is THIS APP'S own, and it is last of the non-token sheets on
// purpose: it names the foreground of the bands that paint their own
// background, which the vendored component CSS leaves unset (measured: a hero
// title inheriting black on a cobalt band, 2.31:1). Its values are tokens, so a
// theme still decides the colour.
foreach (['nlds/nlds-components', 'nlds/nlds-vendor-a', 'nlds/nlds-vendor-b', 'nlds/nlds-app', 'nlds/nlds-controls', 'site-theme'] as $sheet) {
    $stylesheets[] = $asset($appId, 'css/' . $sheet . '.css');
}

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

// The theme app's self-hosted brand faces. A token set may NAME a typeface —
// the Conduction set has asked for Figtree since it was written — but naming
// one is not serving one: every portal rendered Roboto, silently, because the
// declaration fell through its own fallback chain and nothing reported it.
//
// Self-hosted rather than linked to a font CDN: a public government portal
// must not make its visitors' browsers announce themselves to a third party to
// read a page.
$stylesheets[] = $asset(PortalThemeResolver::THEME_APP, 'css/fonts-conduction.css');

// INTER, FROM ITS ONE CANONICAL DECLARATION.
//
// The La Suite layer already self-hosts Inter (@fontsource/inter v5.3.0, OFL,
// ten faces, licence alongside), and it is the family the `lasuite` and
// `frankendesk` sets name. `fonts-conduction.css` briefly declared a competing
// variable face under the same family name; being linked later it WON the
// cascade, and because that file carried almost no glyphs the citizen portal
// was drawn in DejaVu Sans while every instrument short of Chromium's
// `CSS.getPlatformFontsForNode` reported Inter loaded and in use.
//
// Linked for every portal rather than only the La Suite chain: a browser
// downloads a face only when something actually uses the family, so a portal
// on another theme pays nothing, and one fewer conditional is one fewer way
// for a theme to lose its typeface.
$stylesheets[] = $asset(PortalThemeResolver::THEME_APP, 'css/systems/lasuite/fonts.css');

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

// The token layer, last, so a theme's value beats the component CSS's own
// `:root` fallback for the same custom property.
foreach ($tokenStylesheets as $href) {
    $stylesheets[] = $href;
}

// PER-PORTAL TOKEN OVERRIDES — the last layer, so a portal differs from its
// theme by exactly what it names.
//
// A theme is shared. Without this layer two portals on one theme cannot differ,
// and an organisation wanting its own accent has to commission a whole token
// set for one colour. The cascade is bridge → theme → portal.
//
// Emitted inline rather than as a linked asset because it is per-portal data,
// not a file: linking it would mean a route, a cache key and an invalidation
// path for a handful of declarations that change when the portal record does.
// It is a <style> element, which the CSP allows with the same nonce mechanism
// the script tag uses — and it contains only name/value pairs that
// `PortalTokenCss` has validated against an allow-list, never authored CSS.
$portalTokenCss = (string)($_['portalTokenCss'] ?? '');

// The tab icon. A portal's declared logo wins; otherwise the theme app's own
// favicon, which every NLDS theme ships.
// The theme app ships a per-brand logo under `img/logos/<theme>.svg`; the
// reference application serves an SVG favicon the same way. `img/favicon.ico`
// does NOT exist there — linking it would have traded a 404 on
// /favicon.ico for a 404 on a path of our own invention, which is not a fix.
$favicon = (string)($portalConfig['logo'] ?? '');
if ($favicon === '' && $themeStylesheet !== '') {
    $themeName = basename($themeStylesheet);
    try {
        $logo = $appManager->getAppPath(PortalThemeResolver::THEME_APP)
            . '/img/logos/' . $themeName . '.svg';
        if (is_file($logo) === true) {
            $favicon = $asset(PortalThemeResolver::THEME_APP, 'img/logos/' . $themeName . '.svg');
        }
    } catch (\Throwable) {
        // No theme app: fall through to this app's own mark.
    }
}

if ($favicon === '') {
    $favicon = $asset($appId, 'img/app.svg');
}


// NOTHING IS EMITTED HERE. This partial only RESOLVES: `$asset`, `$stylesheets`,
// `$portalTokenCss`, `$favicon` and `$locale` are left for the including
// template to render. Two documents include it — the public site and the page
// editor — because the editor's canvas mounts the real blocks, and a block only
// looks like itself under the same stylesheets and the same token cascade.
// Duplicating this list in the editor would guarantee they drift, and the
// editor is precisely where a drift would be invisible: it would look right and
// preview something the public route does not render.
