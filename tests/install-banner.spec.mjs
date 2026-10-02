#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// install-banner.spec.mjs — the site's install offer
// (site-reaches-portal-parity REQ-SRP-046), ported from the React portal's
// `portaliq-install-banner`.
//
// Usage:
//   node --test tests/install-banner.spec.mjs
//
// Renders src/site/components/f/InstallBanner.vue in plain node and drives
// its methods with a stand-in `this`, the same way the slice-e specs do.

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import strings from '../src/site/components/f/strings.js'
import { loadSfc, renderSfc } from './support/render-sfc.mjs'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const FILE = 'src/site/components/f/InstallBanner.vue'
const t = (key) => strings.nl[key] ?? key

/**
 * A stand-in `this` for the component's methods.
 *
 * @return {object} The instance.
 */
function instance() {
	const emitted = []
	return {
		offer: null,
		dismissed: false,
		emitted,
		$emit: (name) => emitted.push(name),
	}
}

test('a browser that makes no offer shows nothing', async () => {
	const html = await renderSfc(FILE, { t, win: null })

	assert.doesNotMatch(html, /install-banner/)
	assert.doesNotMatch(html, /Installeren/)
})

test('the offer is kept instead of the browser showing its own bar', async () => {
	const component = await loadSfc(FILE)
	const self = instance()
	let prevented = false
	const event = {
		preventDefault: () => {
			prevented = true
		},
	}

	component.methods.onOffer.call(self, event)

	assert.equal(prevented, true)
	assert.equal(self.offer, event)
	assert.equal(component.computed.visible.call(self), true)
})

// Scenario "Not now": the control disappears for this page view.
test('"Not now" hides the banner for this page view', async () => {
	const component = await loadSfc(FILE)
	const self = instance()
	component.methods.onOffer.call(self, { preventDefault: () => {} })

	component.methods.dismiss.call(self)

	assert.equal(component.computed.visible.call(self), false)
	assert.deepEqual(self.emitted, ['dismiss'])

	// A second offer later in the same page view does not bring it back.
	component.methods.onOffer.call(self, { preventDefault: () => {} })
	assert.equal(component.computed.visible.call(self), false)
})

test('"Install" hands the offer back to the browser once', async () => {
	const component = await loadSfc(FILE)
	const self = instance()
	let prompted = 0
	component.methods.onOffer.call(self, {
		preventDefault: () => {},
		prompt: async () => {
			prompted++
		},
	})

	await component.methods.install.call(self)
	await component.methods.install.call(self)

	assert.equal(prompted, 1)
	assert.equal(component.computed.visible.call(self), false)
})

test('a browser that refuses its dialog does not break the page', async () => {
	const component = await loadSfc(FILE)
	const self = instance()
	component.methods.onOffer.call(self, {
		preventDefault: () => {},
		prompt: async () => {
			throw new Error('NotAllowedError')
		},
	})

	await component.methods.install.call(self)

	assert.equal(component.computed.visible.call(self), false)
})

test('an app installed from the browser menu hides the banner', async () => {
	const component = await loadSfc(FILE)
	const self = instance()
	component.methods.onOffer.call(self, { preventDefault: () => {} })

	component.methods.onInstalled.call(self)

	assert.equal(component.computed.visible.call(self), false)
	assert.deepEqual(self.emitted, ['installed'])
})

test('the banner listens on the window it is given, and stops when it goes', async () => {
	const component = await loadSfc(FILE)
	const added = []
	const removed = []
	const self = {
		...instance(),
		win: {
			addEventListener: (type) => added.push(type),
			removeEventListener: (type) => removed.push(type),
		},
		onOffer: () => {},
		onInstalled: () => {},
	}

	component.mounted.call(self)
	component.unmounted.call(self)

	assert.deepEqual(added.sort(), ['appinstalled', 'beforeinstallprompt'])
	assert.deepEqual(removed.sort(), ['appinstalled', 'beforeinstallprompt'])
})

test('every string the banner uses is in Dutch and English, as the portal said it', () => {
	const source = readFileSync(join(ROOT, FILE), 'utf8')
	const keys = [...source.matchAll(/\bt\('([^']+)'\)/g)].map((m) => m[1])
	assert.ok(keys.length >= 4, 'the banner uses its strings through t()')

	const i18nDir = join(ROOT, 'src', 'shared', 'i18n')

	for (const locale of ['nl', 'en']) {
		const shared = JSON.parse(
			readFileSync(join(i18nDir, `${locale}.json`), 'utf8'),
		)
		for (const key of keys) {
			assert.ok(strings[locale][key], `${locale} has "${key}"`)
			assert.equal(
				strings[locale][key],
				shared[key],
				`${locale} "${key}" matches the shared bundle`,
			)
			assert.doesNotMatch(strings[locale][key], /—/, 'no em-dashes')
		}
	}
})

// The wiring, asserted from the caller: a banner and a worker nobody mounts
// pass every test above and still never reach a resident.
test('the shell mounts the banner and registers the worker after mounting', () => {
	const app = readFileSync(join(ROOT, 'src', 'site', 'App.vue'), 'utf8')
	assert.match(app, /<InstallBanner class="container" :t="t" \/>/)
	assert.match(
		app,
		/import \{ InstallBanner \} from '\.\/components\/f\/index\.js'/,
	)

	const main = readFileSync(join(ROOT, 'src', 'site', 'main.js'), 'utf8')
	const mount = main.indexOf('.mount(element)')
	const register = main.indexOf(
		'registerSiteServiceWorker(authBaseFrom(resolveApiBase()))',
	)
	assert.ok(
		mount > 0 && register > mount,
		'the worker is registered after the mount',
	)
})
