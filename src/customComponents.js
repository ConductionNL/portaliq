// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// V1 custom-component registry — kept for backward-compatibility reference.
//
// *** V2 WAY: use src/registry.js instead. ***
//
// This file is the v1 "page-only" registry consumed by CnAppRoot's
// `customComponents` prop. It works for v1 manifests and during the
// v1 → v2 transition period. Once the app fully migrates to a v2 manifest
// and no longer needs the `customComponents` prop, this file can be removed
// and the import in main.js deleted.
//
// CnAppRoot will emit a console.warn once per mount when a v2 manifest is
// loaded alongside a non-empty `customComponents` prop. That is expected
// behaviour during the transition; it does not break anything.
//
// Every COMPONENT entry here has an equivalent `kind: "page"` entry in
// src/registry.js. `openPortalSite` is the exception and the reason this file
// cannot be retired: it is a handler FUNCTION, not a component. The v2
// registry's five kinds are widget | modal | page | form-field | cell-renderer,
// none of which is a handler, and the manifest action dispatcher resolves
// `handler` strings against THIS map only (never `cnRegistry`). Retiring this
// file needs a handler kind in the library first.
//
// Resolution order at runtime (v1 path):
//   1. Built-in page types          (CnIndexPage, CnDetailPage, …)
//   2. Built-in widget types        (version-info, register-mapping, …)
//   3. customComponents (this file) ← consumer-injected components
//
// See hydra ADR-036 for the v2 registry design.

import { showInfo } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import CustomExample from './views/CustomExample.vue'
import { createOpenPortalSite } from './lib/openPortalSite.js'

/**
 * The `Open portal` row action, wired to Nextcloud's URL generator, toast and
 * translator. The factory itself imports none of them so it stays loadable in
 * `tests/open-portal-site.spec.mjs` — see src/lib/openPortalSite.js.
 */
const openPortalSite = createOpenPortalSite({
	generateUrl,
	notify: showInfo,
	translate: (text) => t('portaliq', text),
})
// Features & Roadmap page — thin wrapper around the lib's
// CnFeaturesAndRoadmapView (in-product roadmap surface powered by
// OpenRegister's github-issue-proxy). Shipped wired-up so apps scaffolded
// from this template inherit the Settings-section "Features & roadmap"
// entry; change the repo fallback in views/FeaturesRoadmap.vue. See
// ConductionNL/hydra#251.

export default {
	// Example custom component. Keep or delete when scaffolding a new
	// app. The manifest does NOT reference this by default; it is
	// included so the registry's role is visible to first-time
	// cloners. Wire it up by adding a `type: "custom"` page entry to
	// `src/manifest.json` with `"component": "CustomExample"`.
	CustomExample,
	/**
	 * `Open portal` row action on the Portals index page. A manifest action
	 * with `type: "handler"` resolves its `handler` string against this map
	 * and calls it with `{ actionId, item: row }` — see src/lib/
	 * openPortalSite.js for why the destination cannot be a static
	 * `navigate` target.
	 */
	openPortalSite,
	// Features & Roadmap page (lib's CnFeaturesAndRoadmapView) — wired up
	// in src/manifest.json (the `FeaturesRoadmap` custom page + the
	// `FeaturesRoadmapMenu` settings entry).
}
