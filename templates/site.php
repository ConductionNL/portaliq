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

// THE REFERENCE IMPLEMENTATION'S OWN STYLESHEETS, vendored verbatim.
//
// ORDER IS LOAD-BEARING, and it is the reverse of what looks natural: these
// come AFTER the token file so the components resolve against the serving
// portal's tokens, and BEFORE the site bundle's own scoped styles so anything
// Portaliq states explicitly still wins.
//
// `nlds-components` is the full Utrecht/NLDS component set (334KB) — a
// superset of the per-component packages the bundle imports, kept because the
// reference's layout reaches for components the site does not import yet.
// `nlds-app` (318KB) is the reference application's own layout CSS.
//
// ⚠️ `nlds-app` IS NOT GENERIC. It is dominated by softwarecatalogus classes
// (`ac-*` 1362 selectors, `con-*` 633) and styles that app's DOM, not a
// portal's. Loading it does nothing on its own — it pays off only as this
// renderer emits the matching structure, which is the next increment.
// Deliberate per the decision to start from what exists and abstract later;
// recorded here so the size is not mistaken for value already delivered.
foreach (['nlds/nlds-components', 'nlds/nlds-vendor-a', 'nlds/nlds-vendor-b', 'nlds/nlds-app'] as $sheet) {
    Util::addStyle($appId, $sheet);
}

Util::addScript($appId, $appId . '-site');
?>
<div id="portaliq-site"></div>
