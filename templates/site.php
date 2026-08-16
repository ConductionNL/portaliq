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

// Stylesheet order is load-bearing: tokens first so the component CSS resolves
// against the serving portal's values, then the components, then the reference
// application's own layout CSS.
$stylesheets = [];
if ($themeStylesheet !== '') {
    $stylesheets[] = $asset(PortalThemeResolver::THEME_APP, 'css/' . $themeStylesheet . '.css');
}

if ($nldsStylesheet !== '') {
    $stylesheets[] = $asset($appId, 'css/' . $nldsStylesheet . '.css');
}

foreach (['nlds/nlds-components', 'nlds/nlds-vendor-a', 'nlds/nlds-vendor-b', 'nlds/nlds-app'] as $sheet) {
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

?><!DOCTYPE html>
<html lang="<?php p($locale); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php p($portalConfig['title'] ?? 'Portaal'); ?></title>
    <?php foreach ($stylesheets as $href) { ?>
    <link rel="stylesheet" href="<?php p($href); ?>">
    <?php } ?>
</head>
<body>
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
