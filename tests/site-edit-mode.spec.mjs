#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// site-edit-mode.spec.mjs: editing a page on the portal itself
// (portal-in-place-editing A2, REQ-PIE-006 to REQ-PIE-008).
//
// Usage:
//   node --test tests/site-edit-mode.spec.mjs

import assert from 'node:assert/strict'
import { existsSync, readFileSync, statSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import { cellOf, cellStyleOf, normaliseWidgets, storedWidget } from '../src/editor/index.js'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const read = (path) => readFileSync(join(ROOT, path), 'utf8')

const WIDGETS = [
	{ id: 'a', widgetKey: 'markdown', gridX: 0, gridY: 0, gridWidth: 6, gridHeight: 4 },
	{ id: 'b', widgetKey: 'markdown', gridX: 6, gridY: 0, gridWidth: 6, gridHeight: 4 },
	{ id: 'c', widgetKey: 'markdown', gridX: 10, gridY: 4, gridWidth: 6, gridHeight: 2 },
	{ id: 'd', widgetKey: 'markdown', gridX: -3, gridY: -1, gridWidth: 0, gridHeight: 0 },
]

test('renderer and editor agree on geometry', () => {
	// The editor stores what it shows; the renderer styles what is stored.
	// Both go through cellOf, so for every widget the stored cell and the
	// rendered style name the same columns and rows.
	for (const widget of WIDGETS) {
		const stored = storedWidget(normaliseWidgets([widget])[0])
		const style = cellStyleOf(widget)
		assert.equal(style.gridColumn, `${stored.gridX + 1} / span ${stored.gridWidth}`, widget.id)
		assert.equal(style.gridRow, `${stored.gridY + 1} / span ${stored.gridHeight}`, widget.id)
	}
	assert.deepEqual(cellOf(WIDGETS[0]), { gridX: 0, gridY: 0, gridWidth: 6, gridHeight: 4 })
	assert.deepEqual(cellOf(WIDGETS[1]), { gridX: 6, gridY: 0, gridWidth: 6, gridHeight: 4 })
	// Clamped inside 12 columns, not run past the edge.
	assert.deepEqual(cellOf(WIDGETS[2]), { gridX: 10, gridY: 4, gridWidth: 2, gridHeight: 2 })
	assert.deepEqual(cellOf(WIDGETS[3]), { gridX: 0, gridY: 0, gridWidth: 12, gridHeight: 1 })
})

test('the public renderer places cells with the shared function', () => {
	const grid = read('src/site/components/WidgetGrid.vue')
	assert.match(grid, /from '\.\.\/lib\/gridPlacement\.js'/)
	const placement = read('src/site/lib/gridPlacement.js')
	assert.match(placement, /from '\.\.\/\.\.\/editor\/geometry\.js'/)
	assert.match(placement, /cellOf\(widget\)/)
})

test('the renderer\'s own cell style equals the shared one on an absolute row', async () => {
	const { cellStyle } = await import('../src/site/lib/gridPlacement.js')
	for (const widget of WIDGETS) {
		assert.deepEqual(cellStyle(widget, 0), cellStyleOf(widget), widget.id)
	}
})

test('the site loads the editor as its own bundle, never in its entry', () => {
	const app = read('src/site/App.vue')
	assert.match(app, /loadSiteEditor\(\)/)
	assert.doesNotMatch(app, /editor\/SiteEditMode/)
	assert.doesNotMatch(app, /editor\/index/)
	const loader = read('src/site/lib/loadSiteEditor.js')
	assert.match(loader, /portaliq-site-editor\.js/)
	assert.doesNotMatch(loader, /^import /m, 'the loader in the entry imports nothing')
	const main = read('src/editor/siteEditorMain.js')
	assert.match(main, /window\.PortaliqSiteEditor = \{ mount \}/)
	const config = read('webpack.site.js')
	assert.match(config, /'portaliq-site-editor': path\.join\(\s*__dirname,\s*'src',\s*'editor',\s*'siteEditorMain\.js',?\s*\)/)
	assert.match(config, /module\.exports = \[site, editor(, embed)?\]/)
})

test('the edit control offers editing in place for a page, and the designer as a second way', () => {
	const button = read('src/site/components/SiteEditButton.vue')
	assert.match(button, /Deze pagina bewerken/)
	assert.match(button, /emit: 'edit'/)
	assert.match(button, /\$emit\(action\.emit/)
	assert.match(button, /In de beheeromgeving openen/)
})

test('the portal edit mode is the shared editor with a public palette', () => {
	const mode = read('src/editor/SiteEditMode.vue')
	assert.match(mode, /createPageEditor\(/)
	assert.match(mode, /<PageGridEditor/)
	assert.match(mode, /<PageGridEditor[\s\S]*?publicOnly/)
	assert.match(mode, /<PageHistoryDialog/)
	for (const id of ['site-edit-save', 'site-edit-publish', 'site-edit-discard', 'site-edit-undo', 'site-edit-redo', 'site-edit-leave', 'site-edit-history']) {
		assert.match(mode, new RegExp(`data-testid="${id}"`), id)
	}
})

test('the editor reaches openregister under the instance root, not a guessed webroot', () => {
	for (const file of ['src/editor/SiteEditMode.vue', 'src/editor/SiteMenuPanel.vue', 'src/editor/SitePagesPanel.vue']) {
		const source = read(file)
		assert.doesNotMatch(source, /@nextcloud\/router/, `${file}: /site carries no webroot for generateUrl`)
		assert.match(source, /instanceUrl\(\s*`\/apps\/openregister\//, file)
	}
	assert.match(read('src/editor/instanceUrl.js'), /instanceRootFrom\(resolveApiBase\(\)\)/)
})

test('the palette limited to public widgets offers only what the renderer mounts', async () => {
	const palette = read('src/editor/WidgetPalettePanel.vue')
	assert.match(palette, /publicOnly/)
	assert.match(palette, /entry\.publicSafe/)
})

test('the site entry stays under its budget and the editor is its own bundle', { skip: !existsSync(join(ROOT, 'js', 'portaliq-site-editor.js')) && 'no site build in js/' }, () => {
	const entry = statSync(join(ROOT, 'js', 'portaliq-site.js')).size
	assert.ok(entry < 410 * 1024, `portaliq-site.js is ${entry} bytes`)
	assert.doesNotMatch(readFileSync(join(ROOT, 'js', 'portaliq-site.js'), 'utf8'), /PageGridEditor|createPageEditor/)
})

// The editor bundle mounts @nextcloud/vue components, which read the build-time
// `appName` and `appVersion` globals. Without them every mount logs
// "[ERROR] @nextcloud/vue: The library was used without setting / replacing the
// appName" in the editor's console (found live on 30 Sep 2026). webpack.config.js
// re-adds them for the admin bundles for the same reason.
test('the editor bundle defines appName and appVersion for @nextcloud/vue', async () => {
	const { createRequire } = await import('node:module')
	const require = createRequire(import.meta.url)
	const configs = require(join(ROOT, 'webpack.site.js'))
	const editor = configs.find((c) => Object.keys(c.entry).includes('portaliq-site-editor'))
	assert.ok(editor, 'webpack.site.js exports an editor config')
	const defined = Object.assign({}, ...editor.plugins.filter((p) => p.constructor.name === 'DefinePlugin').map((p) => p.definitions))
	assert.equal(defined.appName, JSON.stringify('portaliq'), 'appName is defined as "portaliq"')
	assert.ok(typeof defined.appVersion === 'string' && defined.appVersion.length > 2, 'appVersion is defined')
})

// THE EDITOR'S OWN TAB SHOWS WHAT IT JUST PUBLISHED (found on :8080 while
// filming, 02 Oct 2026). A page is served `public, max-age=300` to a reader
// without a resident bearer, so re-reading it after a publish answered from
// the browser cache for five minutes. A fresh read goes to the server and
// refreshes that cache; an ordinary read keeps using it.
test('a fresh page read goes past the browser cache, an ordinary one does not', async () => {
	const memory = () => {
		const items = new Map()
		return {
			getItem: (k) => (items.has(k) ? items.get(k) : null),
			setItem: (k, v) => items.set(k, String(v)),
			removeItem: (k) => items.delete(k),
		}
	}
	const calls = []
	const saved = { window: globalThis.window, document: globalThis.document, fetch: globalThis.fetch }
	globalThis.window = {
		location: { origin: 'http://localhost:8080', hash: '', pathname: '/', search: '' },
		history: { replaceState() {} },
		sessionStorage: memory(),
		localStorage: memory(),
		PORTALIQ_SITE_CONFIG: { apiBase: '/apps/portaliq/api/content/site' },
	}
	globalThis.document = { getElementById: () => null, querySelector: () => null }
	globalThis.fetch = async (url, init) => {
		calls.push({ url, init })
		return { ok: true, status: 200, json: async () => ({ id: 'p1' }) }
	}
	try {
		const { fetchPage } = await import('../src/site/lib/contentApi.js')
		await fetchPage('/over', 'demo')
		await fetchPage('/over', 'demo', { fresh: true })
	} finally {
		Object.assign(globalThis, saved)
	}
	assert.equal(calls.length, 2)
	assert.match(calls[0].url, /\/apps\/portaliq\/api\/content\/page\?route=%2Fover&portal=demo$/)
	assert.equal(calls[0].init.cache, undefined, 'an ordinary read may use the cache')
	assert.equal(calls[1].init.cache, 'reload', 'a fresh read goes to the server')
})

test('the editor tells the site it published, and the site re-reads the page fresh', () => {
	const main = read('src/editor/siteEditorMain.js')
	assert.match(main, /function mount\(element, \{ pageId, portal, onLeave, onSaved \}\)/)
	assert.match(main, /h\(SiteEditMode, \{ pageId, portal, onLeave, onSaved \}\)/)
	const mode = read('src/editor/SiteEditMode.vue')
	assert.match(mode, /this\.\$emit\('saved'\)/)
	const app = read('src/site/App.vue')
	assert.match(app, /onSaved: \(\) => this\.refreshShownPage\(\)/)
	assert.match(app, /await this\.loadRoute\(this\.route, \{ fresh: true \}\)/)
	assert.match(app, /fetchPage\(route, this\.portalSlug, \{ fresh \}\)/)
})

// THE EDITOR'S NOTICES READ AT AA (resident-sees-words-not-codes). The editor
// page loads no Nextcloud CSS, so SiteEditMode.vue defines the tokens the
// shared components draw with. NcNoteCard and NcButton paint `--color-success`
// (and error, warning, info) as the BACKGROUND under the main text and under
// `--color-*-text`. Defined as a dark green, "Gepubliceerd." was dark text on
// dark green. Resolve the tokens as a browser would and measure.
// @spec openspec/changes/resident-sees-words-not-codes/specs/portal-in-place-editing/spec.md#requirement-the-editors-notices-must-read-at-aa-contrast

/**
 * The editor's `body { ... }` token block as a name to value map.
 *
 * @return {Map<string, string>} The tokens.
 */
function editorTokens() {
	const source = read('src/editor/SiteEditMode.vue')
	const block = source.slice(source.indexOf('body {'), source.indexOf('}', source.indexOf('body {')))
	const tokens = new Map()
	for (const [, name, value] of block.matchAll(/(--[\w-]+):\s*([^;]+);/g)) {
		tokens.set(name, value.replace(/\s+/g, ' ').trim())
	}
	return tokens
}

/**
 * Resolve a token to an [r, g, b] colour: `var(--x, fallback)`, a hex value,
 * or `color-mix(in srgb, A N%, B)`.
 *
 * @param {string} value The value.
 * @param {Map<string, string>} tokens The tokens.
 * @return {Array<number>} The colour.
 */
function resolveColour(value, tokens) {
	const text = value.trim()
	const variable = text.match(/^var\(\s*(--[\w-]+)\s*(?:,\s*(.+))?\)$/)
	if (variable) {
		return tokens.has(variable[1])
			? resolveColour(tokens.get(variable[1]), tokens)
			: resolveColour(variable[2], tokens)
	}
	const mix = text.match(/^color-mix\(\s*in srgb,\s*(.+?)\s+(\d+)%\s*,\s*(.+?)\s*\)$/)
	if (mix) {
		const a = resolveColour(mix[1], tokens)
		const b = resolveColour(mix[3], tokens)
		const share = Number(mix[2]) / 100
		return a.map((channel, index) => channel * share + b[index] * (1 - share))
	}
	const hex = text.match(/^#([0-9a-f]{3}|[0-9a-f]{6})$/i)
	assert.ok(hex, `unreadable colour: ${text}`)
	const full = hex[1].length === 3 ? [...hex[1]].map((digit) => digit + digit).join('') : hex[1]
	return [0, 2, 4].map((at) => Number.parseInt(full.slice(at, at + 2), 16))
}

/**
 * The WCAG contrast ratio of two colours.
 *
 * @param {Array<number>} one A colour.
 * @param {Array<number>} two A colour.
 * @return {number} The ratio.
 */
function contrast(one, two) {
	const luminance = (rgb) => {
		const [r, g, b] = rgb.map((channel) => {
			const c = channel / 255
			return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4
		})
		return 0.2126 * r + 0.7152 * g + 0.0722 * b
	}
	const [light, dark] = [luminance(one), luminance(two)].sort((a, b) => b - a)
	return (light + 0.05) / (dark + 0.05)
}

test('the editor notices and the delete button read at AA contrast, from tokens only', () => {
	const tokens = editorTokens()
	const text = resolveColour('var(--color-main-text)', tokens)
	for (const kind of ['success', 'error', 'warning', 'info']) {
		const background = resolveColour(`var(--color-${kind})`, tokens)
		assert.ok(contrast(text, background) >= 4.5, `${kind}: main text on --color-${kind}`)
		const own = resolveColour(`var(--color-${kind}-text)`, tokens)
		assert.ok(contrast(own, background) >= 4.5, `${kind}: --color-${kind}-text on --color-${kind}`)
	}
})
