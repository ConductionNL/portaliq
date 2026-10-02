// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The parts of slice f (PWA and embed) that the site shell mounts. Each is a
// lazy chunk, so importing this file adds almost nothing to the site bundle.
//
// The other half of installability is not a component: the shell registers
// the service worker once at boot with `registerSiteServiceWorker()` from
// src/site/lib/pwa.js.
//
// @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md

import { defineAsyncComponent } from 'vue'

export { default as strings } from './strings.js'

/**
 * The install offer. Mount it once in the shell, above the page content,
 * signed in or not. It renders nothing until the browser fires
 * `beforeinstallprompt`, so a browser that makes no offer shows nothing.
 *
 * Props:
 * - `t` (function, required): the translator `t(key, vars)`; its keys are in
 *   ./strings.js and in the shared i18n bundles.
 * - `win` (object): the window to listen on; the page's own by default.
 *
 * Emits `installed` when the app was installed, `dismiss` on "Not now"
 * (the banner then stays hidden for this page view).
 *
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-the-install-offer-must-be-dismissible-req-srp-046
 */
export const InstallBanner = defineAsyncComponent(
	() => import('./InstallBanner.vue'),
)
