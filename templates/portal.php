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

// The public portal is a standalone React + NL Design System SPA, built
// separately from the app's internal Vue admin bundle (see webpack.portal.js).
// It boots into #portaliq-portal and drives its own routing + auth edge.
Util::addScript($appId, $appId . '-portal');
?>
<div id="portaliq-portal"></div>
