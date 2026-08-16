<?php
// SPDX-License-Identifier: EUPL-1.2
//
// Shell for the built-in SITE renderer (ADR-084).
//
// Deliberately minimal. The renderer resolves everything it needs — title,
// theme, menus, pages, glossary — from the PUBLIC content API at runtime, the
// same way any other consumer does. Nothing about the site is baked in here,
// because the moment it is, the built-in renderer has a privileged path that
// a Docusaurus build cannot use, and the CMS stops being headless.
//
// The config goes through IInitialStateService — the CSP-safe channel the
// app's admin template already uses. An inline <script> would need a nonce
// (the app sets `inlineScriptAllowed = false`), and the nonce manager is not
// public API. At a PUBLIC origin, where there is no initial-state channel at
// all, `src/site/lib/contentApi.js` falls back to a `window` global; that
// fallback is the only thing that differs between the two hosts.

use OCA\Portaliq\Service\PortalThemeResolver;
use OCP\Util;

$appId = OCA\Portaliq\AppInfo\Application::APP_ID;

\OC::$server->get(\OCP\IInitialStateService::class)
    ->provideInitialState($appId, 'portalConfig', $_['portalConfig'] ?? []);

// The resolved portal's themiq tokens, emitted BEFORE the bundle so the first
// paint is already in the tenant's brand rather than repainting into it.
//
// The controller has already checked that this file exists; an empty value
// means the theme did not resolve, and the page renders UNSTYLED on purpose —
// a portal silently wearing another municipality's colours is the failure
// nobody screenshots (ADR-086 §6).
$themeStylesheet = (string)($_['themeStylesheet'] ?? '');
if ($themeStylesheet !== '') {
    Util::addStyle(PortalThemeResolver::THEME_APP, $themeStylesheet);
}

// The NL Design System tokens THIS app ships, loaded AFTER the theme app's so
// the `--utrecht-*` names the component CSS actually reads win. The theme
// app's hand-converted files define none of them, which is why a themed
// portal still rendered with Utrecht's built-in defaults.
$nldsStylesheet = (string)($_['nldsStylesheet'] ?? '');
if ($nldsStylesheet !== '') {
    Util::addStyle($appId, $nldsStylesheet);
}

Util::addScript($appId, $appId . '-site');
?>
<div id="portaliq-site"></div>
