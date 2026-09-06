// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

// WHERE THIS BUNDLE'S LAZY CHUNKS LIVE — RESOLVED AT RUNTIME, NOT BUILT IN.
//
// `@nextcloud/webpack-vue-config` bakes `output.publicPath = '/apps/<app>/js/'`
// into the bundle. That is one of the two places an app can be served from: an
// app installed under `custom_apps` is served from `/custom_apps/<app>/js/`,
// which is where this app's own `<script>` tags point.
//
// The eager bundles are unaffected — PHP emits their URLs — so the app shell,
// the navigation and every EAGER page render perfectly. Only the lazily-loaded
// route chunks are requested by webpack itself, at the built-in path, and there
// the wrong URL does not even 404: it matches the app's greedy `/{path}` SPA
// catch-all, which answers 200 with the app's HTML. The browser then refuses to
// execute HTML as a script and the route silently never mounts.
//
// Measured before this line existed: `/apps/portaliq/portals/<uuid>` returned
// HTTP 200 with an EMPTY content area — no error banner, no empty state, no
// failed request in the network panel (the chunk request was a 200) — and one
// console line, `ChunkLoadError`. Every detail page in the app was blank.
//
// `generateFilePath` asks the server where the app actually is, so this works
// under `apps/` and `custom_apps/` alike. It must run BEFORE the first dynamic
// import, which is why it is the first statement in the entry point.
import { generateFilePath } from '@nextcloud/router'

// `__webpack_public_path__` is a free variable webpack replaces at build time;
// eslint cannot see it declared anywhere, which is why it is disabled here
// rather than added to the globals list — it exists only inside a bundle.
// eslint-disable-next-line no-undef
__webpack_public_path__ = generateFilePath('portaliq', '', 'js/')

import {
	CnPageRenderer,
	defaultPageTypes,
	registerIcons,
	registerTranslations,
} from '@conduction/nextcloud-vue'
import {
	loadTranslations,
	translatePlural as n,
	register,
	translate as t,
} from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { createApp } from 'vue'
import { createRouter, createWebHistory } from 'vue-router'
import App from './App.vue'
import enTranslations from '../l10n/en.json'
import customComponents from './customComponents.js'
import appIcons from './icons.js'
import bundledManifest from './manifest.json'
import pinia from './pinia.js'
// v2 five-kind registry — the replacement for customComponents.
// Both props coexist during the v1 → v2 transition.
// Once fully migrated to v2, remove the customComponents import and prop.
import registry from './registry.js'

// Library CSS — must be explicit import (webpack tree-shakes side-effect imports from aliased packages)
import '@conduction/nextcloud-vue/css/index.css'
// gridstack CSS. The manifest declares a `type: "dashboard"` page, and
// gridstack v12 sizes its items with `width: var(--gs-column-width)` — that
// variable is defined ONLY in this stylesheet. Without the import every
// dashboard widget renders 0 px wide with NO console error and correct
// heights (height comes from JS, width from CSS).
import 'gridstack/dist/gridstack.css'
// Global (unscoped) app styles
import './assets/app.css'

// Register library-side icon set + lib translations once at bootstrap.
registerIcons(appIcons)
try {
	registerTranslations()
} catch (e) {
	// Non-fatal — lib translations fall back to English source.
	// eslint-disable-next-line no-console
	console.warn(
		'[portaliq] registerTranslations failed; falling back to English',
		e,
	)
}

// Register English translations from the bundled en.json. loadTranslations()
// short-circuits for the 'en' locale (it assumes the key IS the English text),
// but this template uses slugged keys like 'app-availability.title', so we must
// register en.json explicitly to get readable strings instead of raw slugs.
register('portaliq', enTranslations.translations)

// Fire-and-forget translation load. Some Nextcloud installs (including
// standard dev containers) only allow the JS/CSS allowlist through
// Apache and rewrite everything else to index.php — there's no route
// for /custom_apps/<app>/l10n/<locale>.json so the request 404s.
// `loadTranslations` rejects on 404, so wrapping the Vue mount inside
// its callback would silently fail boot when translations can't load.
// Strings just fall back to their English source on miss; boot MUST
// not depend on this resolving.
/**
 *
 */
function tryLoadTranslations() {
	try {
		const result = loadTranslations('portaliq', () => {})
		if (result && typeof result.then === 'function') {
			result.then(
				() => {},
				() => {},
			)
		}
	} catch {
		// no-op
	}
}

// Shallow-clone CnPageRenderer because the lib's barrel exports are frozen /
// non-extensible (webpack ESM module records) and vue-router writes internal
// bookkeeping onto the component options it is handed. Cloning gives the
// router an extensible options object without altering the lib's internals.
const RoutePageRenderer = { ...CnPageRenderer }

/**
 * Build the vue-router config from the manifest. Each manifest page becomes
 * one route; the route's `name` IS `page.id` (per the lib's manifest contract).
 * Routes whose path declares a `:` parameter receive `props: true` so the
 * built-in detail / index components can read the route param without each
 * consumer wiring it manually.
 *
 * @param {object} manifest The bundled manifest (with `pages[]`).
 * @return {Array<object>} vue-router 4 routes config.
 */
function routesFromManifest(manifest) {
	const routes = manifest.pages.map((page) => ({
		name: page.id,
		path: page.route,
		component: RoutePageRenderer,
		props: page.route.includes(':'),
	}))
	// Catch-all: redirect unknown paths to the first page (the dashboard).
	// vue-router 4 REMOVED the bare `path: '*'` glob. It does not warn — the
	// route simply never matches, so an unknown path renders the app shell
	// with an empty <main> and no console error.
	routes.push({ path: '/:pathMatch(.*)*', redirect: '/' })
	return routes
}

/**
 * The router base for THIS page load.
 *
 * ⚠️ `generateUrl('/apps/portaliq')` alone is not enough. Nextcloud serves the
 * app under BOTH `/apps/portaliq/...` and `/index.php/apps/portaliq/...`, but
 * `generateUrl()` returns only the form the instance is configured for. A
 * visitor arriving on the other form — a bookmark, an emailed deep link, an
 * integration that hardcodes `/index.php` — has a pathname the router cannot
 * strip its base from. No route matches, the catch-all takes over, and they
 * land on the dashboard with no error at all: the deep link is silently
 * swallowed.
 *
 * Measured on a live instance for learniq, across all 282 of its routes:
 * `/apps/learniq/courses` resolved to Courses, `/index.php/apps/learniq/courses`
 * resolved to the dashboard. Every route behaved the same way, so this is not
 * one broken page but every deep link in that URL form.
 *
 * Deriving the base from the pathname makes both forms resolve, because the
 * base then always matches the URL the visitor actually arrived on.
 *
 * @return {string} The base path vue-router should strip from the URL.
 */
function routerBase() {
	const match = window.location.pathname.match(/^(.*\/apps\/portaliq)(?:\/|$)/)
	return match ? match[1] : generateUrl('/apps/portaliq')
}

const router = createRouter({
	history: createWebHistory(routerBase()),
	routes: routesFromManifest(bundledManifest),
})

tryLoadTranslations()

// Pass shallow copies of the registry maps to App.vue. The lib exports
// `defaultPageTypes` (and consumers' `customComponents` / `registry`) as
// FROZEN module objects in some bundle shapes; anything downstream that
// writes to them throws in strict mode. Cloning here yields extensible
// objects without changing the values the lib resolves at render time.
const pageTypesProp = { ...defaultPageTypes }
const customComponentsProp = { ...customComponents }
// Shallow-clone the v2 registry for the same reason as above.
// Once the app fully migrates to v2, the customComponentsProp and
// customComponents prop can be removed.
const registryProp = { ...registry }

const app = createApp(App, {
	manifest: bundledManifest,
	customComponents: customComponentsProp,
	pageTypes: pageTypesProp,
	registry: registryProp,
})

// Vue 3: global API lives on the app instance, not on the Vue constructor.
// `pinia` is a plugin here — PiniaVuePlugin was the Vue-2-only shim and is
// gone from the Vue 3 bootstrap entirely.
app.mixin({ methods: { t, n } })
app.use(pinia)
app.use(router)
app.mount('#content')
