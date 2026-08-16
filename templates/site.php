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
    $tokenStylesheets[] = $asset(PortalThemeResolver::THEME_APP, 'css/' . $themeStylesheet . '.css');
}

if ($nldsStylesheet !== '') {
    $tokenStylesheets[] = $asset($appId, 'css/' . $nldsStylesheet . '.css');
}

foreach (['nlds/nlds-components', 'nlds/nlds-vendor-a', 'nlds/nlds-vendor-b', 'nlds/nlds-app', 'nlds/nlds-controls'] as $sheet) {
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

// The token layer, last, so a theme's value beats the component CSS's own
// `:root` fallback for the same custom property.
foreach ($tokenStylesheets as $href) {
    $stylesheets[] = $href;
}

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

?><!DOCTYPE html>
<html lang="<?php p($locale); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php p($portalConfig['title'] ?? 'Portaal'); ?></title>
    <?php
    // FAVICON. Without one the browser requests /favicon.ico against the
    // ORIGIN, which on a Nextcloud host is not this app's to answer — measured,
    // a 404 on every page load and a blank tab icon. The portal's own logo when
    // it has one, otherwise the theme app's, so a tab is identifiable either
    // way.
    ?>
    <link rel="icon" href="<?php p($favicon); ?>">
    <?php foreach ($stylesheets as $href) { ?>
    <link rel="stylesheet" href="<?php p($href); ?>">
    <?php } ?>
</head>
<body>
    <!--
        THE SKIP LINK IS SERVER-RENDERED, AND THAT IS THE WHOLE POINT.

        It used to live in App.vue, which meant it did not exist until the
        bundle had downloaded, parsed and mounted. An affordance that appears
        after hydration is not an affordance: the visitor most likely to need
        it is the one tabbing into a page that has not finished loading, and
        for them the first focusable element was whatever the app happened to
        render first.

        Emitting it here also makes it TRUE OF THE RESPONSE rather than of the
        runtime — the document this app now owns (RENDER_AS_BLANK) carries its
        own SC 2.4.1 affordance, which is exactly what a document owner is
        responsible for. First element in <body>, so it is the first tab stop.

        `#pq-main` is rendered by the bundle. Before boot there is no main
        landmark because there is no content yet — with the content, the target
        arrives. Same classes and copy as the markup it replaces, so the
        existing styling and the accessibility spec keep addressing it.
    -->
    <p>
        <a id="skip-link"
           class="utrecht-skip-link utrecht-skip-link--visible-on-focus pq-site__skip"
           href="#pq-main">Direct naar de inhoud</a>
    </p>

    <!--
        DATA, NOT CODE. `type="application/json"` is not executed, so this
        needs no CSP nonce and cannot become an injection vector; the bundle
        parses it. `contentApi.js` already had a public-origin fallback for
        exactly this case — running with no Nextcloud initial-state channel.
    -->
    <script type="application/json" id="portaliq-site-config"><?php
        print_unescaped(json_encode($portalConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT));
    ?></script>

    <div id="portaliq-site"></div>

    <?php emit_script_tag($asset($appId, 'js/portaliq-site.js')); ?>
</body>
</html>
