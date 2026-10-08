#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// site-theme-bridge.spec.mjs: the site links the theme app's public bridge
// directly before the token set, after the vendored sheets, and only with a
// set (site-links-the-theme-bridge REQ-STB-001).
//
// The template cannot run outside Nextcloud, so this reads its source and
// checks the ORDER in which it fills the two lists it links: `$stylesheets`
// (vendored sheets, site-theme.css, fonts) and then `$tokenStylesheets`.
// Moving the bridge into `$stylesheets`, after the set, or out of the set's
// guard each fails a test below.
//
// Usage:
//   node --test tests/site-theme-bridge.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const template = readFileSync(join(ROOT, 'templates', 'site.php'), 'utf8')

const BRIDGE_PUSH = "$tokenStylesheets[] = $asset($themeApp, 'css/' . $themeBridgeStylesheet . '.css');"
const SET_PUSH = "$tokenStylesheets[] = $asset($themeApp, 'css/' . $themeStylesheet . '.css');"

/**
 * The source of the block guarded by the theme set, from its `if` to the
 * matching closing brace.
 *
 * @return {string} The guarded block.
 */
function setGuardBlock() {
	const start = template.indexOf("if ($themeStylesheet !== '' && $themeApp !== null) {")
	assert.notEqual(start, -1, 'the set guard is in the template')
	let depth = 0
	for (let i = template.indexOf('{', start); i < template.length; i++) {
		if (template[i] === '{') {
			depth++
		} else if (template[i] === '}') {
			depth--
			if (depth === 0) {
				return template.slice(start, i + 1)
			}
		}
	}
	throw new Error('unbalanced set guard')
}

test('the bridge is pushed into the token layer, directly before the set', () => {
	const block = setGuardBlock()
	const bridge = block.indexOf(BRIDGE_PUSH)
	const set = block.indexOf(SET_PUSH)
	assert.notEqual(bridge, -1, 'the bridge is pushed inside the set guard')
	assert.notEqual(set, -1, 'the set is pushed inside the set guard')
	assert.ok(bridge < set, 'the bridge comes before the set')
	assert.equal(
		template.split(BRIDGE_PUSH).length - 1,
		1,
		'the bridge is linked in one place only',
	)
})

test('the bridge is linked only when the controller named one', () => {
	const block = setGuardBlock()
	const guard = block.indexOf("if ($themeBridgeStylesheet !== '') {")
	assert.ok(guard !== -1 && guard < block.indexOf(BRIDGE_PUSH))
})

test('the token layer is linked after the vendored sheets and site-theme.css', () => {
	const vendored = template.indexOf("'nlds/nlds-app'")
	const siteTheme = template.indexOf("$asset($appId, 'css/site-theme.css')")
	const tokenLayer = template.indexOf('foreach ($tokenStylesheets as $href)')
	assert.ok(vendored !== -1 && siteTheme !== -1 && tokenLayer !== -1)
	assert.ok(vendored < tokenLayer, 'vendored sheets before the token layer')
	assert.ok(siteTheme < tokenLayer, 'site-theme.css before the token layer')
	assert.doesNotMatch(
		template,
		/\$stylesheets\[\] = \$asset\(\$themeApp, 'css\/' \. \$themeBridgeStylesheet/,
		'the bridge never joins the vendored list',
	)
})

// REQ-STB-002: the faces the theme app bundles.
const FONT_PUSH = "$stylesheets[] = $asset($themeApp, 'css/' . $themeFontStylesheet . '.css');"

test('the bundled faces are linked only when the controller named them', () => {
	const guard = template.indexOf("if ($themeFontStylesheet !== '' && $themeApp !== null) {")
	const push = template.indexOf(FONT_PUSH)
	assert.notEqual(guard, -1, 'the font link is guarded')
	assert.ok(push > guard, 'the push sits inside the guard')
	assert.equal(template.split(FONT_PUSH).length - 1, 1, 'linked in one place only')
})

test('the bundled faces come after this app\'s faces and before the uploaded ones', () => {
	const own = template.indexOf("$asset($appId, 'css/nlds/nlds-fonts.css')")
	const licensed = template.indexOf("$asset($appId, 'css/nlds/nlds-fonts-licensed.css')")
	const bundled = template.indexOf(FONT_PUSH)
	const uploaded = template.indexOf('$stylesheets[] = $url->linkToRoute($fontRoute);')
	const tokenLayer = template.indexOf('foreach ($tokenStylesheets as $href)')
	assert.ok(own !== -1 && licensed !== -1 && uploaded !== -1)
	assert.ok(own < bundled && licensed < bundled, 'after this app\'s own faces')
	assert.ok(bundled < uploaded, 'before the uploaded faces')
	assert.ok(bundled < tokenLayer, 'before the token set that names the family')
})
