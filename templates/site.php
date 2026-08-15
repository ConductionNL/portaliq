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

use OCP\Util;

$appId = OCA\Portaliq\AppInfo\Application::APP_ID;

\OC::$server->get(\OCP\IInitialStateService::class)
    ->provideInitialState($appId, 'siteConfig', $_['siteConfig'] ?? []);

Util::addScript($appId, $appId . '-site');
?>
<div id="portaliq-site"></div>
