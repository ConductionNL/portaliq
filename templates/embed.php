<?php
// SPDX-License-Identifier: EUPL-1.2
//
// THE EMBED FRAME (embedded-intake-form, site-reaches-portal-parity REQ-SRP-047).
//
// THIS TEMPLATE EMITS THE WHOLE DOCUMENT, like site.php, and it has to.
//
// PortalEmbedController renders it with `RENDER_AS_BLANK`, which is no layout
// at all. This file used to call `Util::addScript()` and hand the payload over
// through the initial-state channel, and both of those are printed by a
// Nextcloud LAYOUT. With none, the frame served exactly one line: an empty
// `<div id="portaliq-embed">`, with no script and no data. Measured on the
// integration instance on 2026-10-01. The route, its CSP and its refusals
// all worked; nothing ever ran in the frame.
//
// So the frame links its own bundle (`portaliq-embed`, the small Vue entry in
// src/embed/, not the site and not the React portal) through core's
// `emit_script_tag()`, which stamps the CSP nonce, and passes the boot data in
// a JSON block that is DATA, not code. It carries the form to render and
// NOTHING about a visitor: the frame never reads a session.

declare(strict_types=1);

/** @var array $_ */
$appId = OCA\Portaliq\AppInfo\Application::APP_ID;
$url = \OCP\Server::get(\OCP\IURLGenerator::class);
$appManager = \OCP\Server::get(\OCP\App\IAppManager::class);

// The page language from the visitor's browser, Dutch unless it asks for
// English: the frame's own sentences exist in those two.
$locale = substr(\OCP\Server::get(\OCP\L10N\IFactory::class)->findLanguage($appId), 0, 2);
if ($locale !== 'en') {
    $locale = 'nl';
}

// Cache-buster from the built file's own mtime, for the reason site.php gives.
$bundle = 'js/' . $appId . '-embed.js';
$bundleFile = $appManager->getAppPath($appId) . '/' . $bundle;
$bundleUrl = $url->linkTo($appId, $bundle) . '?v=' . urlencode(is_file($bundleFile) ? (string)filemtime($bundleFile) : '0');

$config = [
    'payload' => (array)($_['embed'] ?? []),
    'submitUrl' => $url->linkToRoute('portaliq.portalEmbed.submit'),
    'locale' => $locale,
];
?><!DOCTYPE html>
<html lang="<?php p($locale); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title><?php p($locale === 'en' ? 'Form' : 'Formulier'); ?></title>
    <style>
        /* The skip link (WCAG 2.4.1): out of sight until it has keyboard focus. */
        .pq-embed-skip{position:absolute;inset-inline-start:-10000px;inset-block-start:auto;inline-size:1px;block-size:1px;overflow:hidden}
        .pq-embed-skip:focus{position:static;inline-size:auto;block-size:auto;overflow:visible;display:inline-block;padding:.5rem 1rem;background:Canvas;color:LinkText;outline:2px solid currentColor;outline-offset:2px}
        #pq-embed-main:focus{outline:none}
    </style>
</head>
<body>
    <!--
        THE SKIP LINK (WCAG 2.4.1). The frame owns its document, so it owns its
        bypass too. The target is a <main> in THIS template, not one the bundle
        renders, so the link works before the frame boots and if it never does.
    -->
    <a id="skip-link" class="pq-embed-skip" href="#pq-embed-main"><?php p($locale === 'en' ? 'Skip to content' : 'Direct naar de inhoud'); ?></a>

    <script type="application/json" id="portaliq-embed-config"><?php
        print_unescaped(json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT));
    ?></script>

    <main id="pq-embed-main" tabindex="-1">
        <div id="portaliq-embed" data-min-height="<?php p($_['minHeight'] ?? 480); ?>"></div>
    </main>

    <?php emit_script_tag($bundleUrl); ?>
</body>
</html>
