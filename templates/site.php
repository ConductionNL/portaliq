<?php
// SPDX-License-Identifier: EUPL-1.2
//
// THE PUBLIC SITE'S DOCUMENT.
//
// Rendered with `RENDER_AS_BLANK`: this template owns the whole document,
// because even Nextcloud's barest layout ships `server.css` (587 rules) and the
// instance theme chain, which kept the content column inset and rendered bare
// `h1` in the platform's typeface — rules that outrank anything this app can
// scope. See `PortalPageController::site()` for the measurements.
//
// EVERY ASSET DECISION LIVES IN THE PARTIAL BELOW, and the long notes explaining
// each one live there with it: stylesheet order, the mtime cache-buster, the
// root-relative webfont urls, the token cascade and the favicon fallback. The
// page editor includes the same partial, so its canvas renders the real blocks
// under the real stylesheets rather than an approximation of them.

declare(strict_types=1);

/** @var array $_ */
require __DIR__ . '/partials/site-assets.php';

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
    <?php if ($portalTokenCss !== '') { ?>
    <style><?php print_unescaped($portalTokenCss); ?></style>
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
