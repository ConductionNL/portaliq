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

/** @var array $_ */

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
$locale = (string)($_['locale'] ?? 'nl');

?><!DOCTYPE html>
<html lang="<?php p($locale); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php
    // The serving portal's own name, resolved server-side by
    // PortalPageController::site(). `?? ` alone was not enough: the controller
    // always passes the key and answers '' for every unresolved request, so a
    // null-coalesce would have rendered an EMPTY title rather than the
    // fallback. Checked for emptiness instead, which covers both "no portal
    // resolved" and "a portal with a blank title".
    ?>
    <?php
    // THE HEAD OF THE PAGE ASKED FOR (site-page-seo-history-and-media), from
    // SiteHead: the same anonymous read the content API makes, so a draft or
    // a missing route lends nothing and gets `noindex`. The title prefers the
    // page's search title; with no head at all the portal's name stands, then
    // the neutral fallback.
    $head = (array)($head ?? []);
    $headTitle = (string)($head['title'] ?? '');
    if ($headTitle === '') {
        $headTitle = (($portalConfig['title'] ?? '') !== '' ? (string)$portalConfig['title'] : 'Portaal');
    }
    ?>
    <title><?php p($headTitle); ?></title>
    <?php if (($head['description'] ?? '') !== '') { ?>
    <meta name="description" content="<?php p($head['description']); ?>">
    <meta property="og:description" content="<?php p($head['description']); ?>">
    <?php } ?>
    <meta name="robots" content="<?php p(($head['robots'] ?? '') !== '' ? $head['robots'] : 'noindex'); ?>">
    <?php if ((string)($_['creator'] ?? '') !== '') { ?>
    <meta name="DCTERMS.creator" content="<?php p((string)$_['creator']); ?>">
    <?php } ?>
    <meta property="og:title" content="<?php p($headTitle); ?>">
    <meta property="og:type" content="website">
    <?php if (($head['canonical'] ?? '') !== '') { ?>
    <link rel="canonical" href="<?php p($head['canonical']); ?>">
    <meta property="og:url" content="<?php p($head['canonical']); ?>">
    <?php } ?>
    <?php if (($head['ogImage'] ?? '') !== '') { ?>
    <meta property="og:image" content="<?php p($head['ogImage']); ?>">
    <?php } ?>
    <?php
    // The stylesheets and the theme <style> lines, shared with the plain page.
    include __DIR__ . '/parts/site-stylesheets.php';

    // The tab icon (portal-identity-from-the-admin REQ-PIA-002): SiteIcon resolves
    // the portal's favicon, then its logo, then the theme's icon, then this app's
    // own mark, and SiteShell hands the answer in as `siteIcon`. The lines below
    // stay as the fallback for a render without it.
    // The theme app ships a per-brand logo under `img/logos/<theme>.svg`; the
    // reference application serves an SVG favicon the same way. `img/favicon.ico`
    // does NOT exist there — linking it would have traded a 404 on
    // /favicon.ico for a 404 on a path of our own invention, which is not a fix.
    $favicon = (string)($_['siteIcon'] ?? '');
    if ($favicon === '' && $themeStylesheet !== '' && $themeApp !== null) {
        $themeName = basename($themeStylesheet);
        try {
            $logo = $appManager->getAppPath($themeApp)
                . '/img/logos/' . $themeName . '.svg';
            if (is_file($logo) === true) {
                $favicon = $asset($themeApp, 'img/logos/' . $themeName . '.svg');
            }
        } catch (\Throwable) {
            // No theme app: fall through to this app's own mark.
        }
    }

    if ($favicon === '') {
        $favicon = $asset($appId, 'img/app.svg');
    }
    ?>
    <?php
    // FAVICON. Without one the browser requests /favicon.ico against the
    // ORIGIN, which on a Nextcloud host is not this app's to answer — measured,
    // a 404 on every page load and a blank tab icon. The portal's own logo when
    // it has one, otherwise the theme app's, so a tab is identifiable either
    // way.
    ?>
    <link rel="icon" href="<?php p($favicon); ?>">
    <?php
    // THE WEB APP MANIFEST (site-reaches-portal-parity REQ-SRP-044), for the
    // portal named in the address, so an installed app opens on this site with
    // the same portal. Without a `?portal=` the manifest resolves the portal by
    // host, the same way this page does.
    $manifestParams = [];
    if ((string)($portalConfig['portal'] ?? '') !== '') {
        $manifestParams['portal'] = (string)$portalConfig['portal'];
    }
    ?>
    <link rel="manifest" href="<?php p($url->linkToRoute('portaliq.portalManifest.manifest', $manifestParams)); ?>">
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

    <?php
    // WITHOUT JAVASCRIPT THE PAGE SAYS SO (site-honest-without-javascript
    // REQ-SHJ-001). A browser that runs scripts does not parse noscript
    // content into the page, so for it the bundle's own #pq-main stays the
    // only one; without scripts this main is the skip link's target, and its
    // link opens the plain page of the same route, search included.
    $noscript = (array)($_['noscript'] ?? []);
    $plainUrl = (string)($_['plainUrl'] ?? '');
    ?>
    <?php if ($plainUrl !== '') { ?>
    <noscript>
        <main id="pq-main" class="pq-site__noscript" tabindex="-1">
            <p class="utrecht-paragraph"><?php p((string)($noscript['text'] ?? '')); ?></p>
            <p class="utrecht-paragraph"><a class="utrecht-link" href="<?php p($plainUrl); ?>"><?php p((string)($noscript['linkText'] ?? '')); ?></a></p>
        </main>
    </noscript>
    <?php } ?>

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
    <?php
    // THE TRAFFIC CLIENT, AFTER THE SITE BUNDLE, FROM THE ROUTE (portal-
    // traffic-analytics). The same URL a Docusaurus build loads, so the two
    // renderers run the same bytes; a file path under js/ would answer 401
    // to the anonymous visitor this page exists for. The nonce helper takes
    // no data attributes, so the client derives its origin and app path from
    // this tag's own src and the named portal (when one was named) from the
    // config block above. It then asks /api/content/site what the portal
    // measures and does nothing at all for a portal that measures nothing:
    // whether to measure is the portal's decision, served by the API, not
    // something this template resolves. The cache-buster is the built
    // file's mtime for the same reason the bundle's is.
    $trafficClient = $appRoot . '/js/portaliq-traffic.js';
    if (is_file($trafficClient) === true) {
        emit_script_tag($url->linkToRoute('portaliq.traffic.client') . '?v=' . urlencode((string)filemtime($trafficClient)));
    }
    ?>
</body>
</html>
