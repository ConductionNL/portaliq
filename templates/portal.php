<?php
// SPDX-License-Identifier: EUPL-1.2

use OCP\Util;

$appId = OCA\Portaliq\AppInfo\Application::APP_ID;

// White-label runtime config (portal-white-label-runtime-config), resolved
// server-side by PortalPageController::index() from the `?org={slug}` query
// parameter. Injected via IInitialStateService — the same CSP-safe,
// nonce-free mechanism `templates/settings/admin.php` already uses for
// `version` — rather than a raw inline <script> tag (which the app's default
// ContentSecurityPolicy() disallows: `inlineScriptAllowed = false`).
// `src/portal/main.jsx` reads it back via `loadState('portaliq',
// 'runtimeConfig', <dev-only default>)`.
\OC::$server->get(\OCP\IInitialStateService::class)
    ->provideInitialState($appId, 'runtimeConfig', $_['runtimeConfig'] ?? []);

// THE SERVING PORTAL'S TOKEN SET (WOO-566).
//
// Without this the white-label fix would have been invisible. The runtime
// config below carries the resolved theme, and `App.jsx` turns it into a
// `theme-<name>` class on the shell's root element -- but nothing on this page
// ever DECLARED those brands. `src/portal/theme.css` shipped one hard-coded
// accent colour and a single `.theme-utrecht` placeholder, so a portal
// configured as `opencatalogi` rendered a correct class name on a page wearing
// none of OpenCatalogi's colours. Reading the DOM would have confirmed the fix;
// looking at the page would have refuted it.
//
// The brands live in the theme app (44+ sets), and `theme.css` now reads their
// `--nldesign-color-*` layer -- the one the token files themselves document as
// "the layer Nextcloud chrome and the Portaliq site renderer read". Linking the
// resolved set is what connects the two.
//
// Resolved SERVER-SIDE and linked here rather than fetched by the bundle, for
// the same reason `/site` does it: colours resolved after boot mean the first
// paint is unbranded and the page visibly repaints a moment later.
//
// Empty when nothing resolved -- no portal, no theme, theme app not installed,
// or a theme reference with no token file. The page then renders in the
// fallback values `theme.css` declares, which is the neutral shell it has
// always rendered. NEVER another tenant's brand.
$themeStylesheet = (string)($_['themeStylesheet'] ?? '');
if ($themeStylesheet !== '') {
    $themeApp = \OCP\Server::get(\OCA\Portaliq\Service\PortalThemeResolver::class)->themeAppId();
    if ($themeApp !== null) {
        Util::addStyle($themeApp, $themeStylesheet);
    }
}

// PWA installability (parent-pwa-installability): links the manifest
// PortalManifestController::manifest() serves for this SAME resolved
// portal, built by the controller from the identical `?org=`/`?portal=`
// query the runtime config above was already resolved from — so the
// installed app and the page installing it can never name two tenants.
$manifestUrl = (string)($_['manifestUrl'] ?? '');
if ($manifestUrl !== '') {
    Util::addHeader('link', ['rel' => 'manifest', 'href' => $manifestUrl]);
}

// The public portal is a standalone React + NL Design System SPA, built
// separately from the app's internal Vue admin bundle (see webpack.portal.js).
// It boots into #portaliq-portal and drives its own routing + auth edge.
Util::addScript($appId, $appId . '-portal');
?>
<div id="portaliq-portal"></div>
