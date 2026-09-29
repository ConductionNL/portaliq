#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// portal-live-regions.spec.mjs: the signed-in portal tells a screen reader
// what a sighted resident sees change (portal-spa-nl-design-system-styling,
// WCAG 2.2 AA success criterion 4.1.3 Status messages). Every loading
// indicator is the shared Loading component, a `role="status"` region with a
// spoken label instead of a bare "…"; every error paragraph is an alert and
// every saved-notice a status; no native prompt, alert or confirm dialog is
// used anywhere in the portal.
//
// Usage:
//   node --test tests/portal-live-regions.spec.mjs

import babel from '@babel/core'
import assert from 'node:assert/strict'
import { mkdirSync, readdirSync, readFileSync, writeFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath, pathToFileURL } from 'node:url'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const PORTAL = join(ROOT, 'src', 'portal')
const OUT_DIR = join(ROOT, 'node_modules', '.cache', 'portaliq-tests')

/**
 * Every .jsx and .js file under src/portal, with its source.
 *
 * @param {string} dir The directory.
 * @return {Array<{path: string, source: string}>}
 */
function sources(dir = PORTAL) {
	return readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
		const path = join(dir, entry.name)
		if (entry.isDirectory()) {
			return sources(path)
		}
		return /\.jsx?$/.test(entry.name)
			? [{ path: path.slice(ROOT.length + 1), source: readFileSync(path, 'utf8') }]
			: []
	})
}

test('no loading indicator is a bare ellipsis', () => {
	const bare = sources()
		.filter(({ path }) => !path.endsWith('Loading.jsx'))
		.flatMap(({ path, source }) =>
			source
				.split('\n')
				.map((line, i) => ({ line, at: `${path}:${i + 1}` }))
				.filter(({ line }) => /<(p|span)[^>]*>\s*…\s*<\/(p|span)>/.test(line)),
		)
		.map(({ at }) => at)
	assert.deepEqual(bare, [], 'use <Loading t={…} /> instead')
})

test('every error is an alert and every notice a status', () => {
	const silent = sources().flatMap(({ path, source }) =>
		source
			.split('\n')
			.map((line, i) => ({ line, at: `${path}:${i + 1}` }))
			.filter(({ line }) => {
				const match = line.match(/\{\s*(?:state\.)?(\w*[eE]rror|\w*[nN]otice)\s*&&\s*<p\b([^>]*)>/)
				if (!match) {
					return false
				}
				const wanted = /[eE]rror$/.test(match[1]) ? 'alert' : 'status'
				return !match[2].includes(`role="${wanted}"`)
			}),
	).map(({ at }) => at)
	assert.deepEqual(silent, [])
})

test('the portal uses no native prompt, alert or confirm dialog', () => {
	const native = sources()
		.filter(({ source }) =>
			source
				.split('\n')
				.filter((line) => !/^\s*(\/\/|\*|\/\*)/.test(line))
				.some((line) => /\bwindow\.(prompt|alert|confirm)\s*\(/.test(line)),
		)
		.map(({ path }) => path)
	assert.deepEqual(native, [])
})

test('Loading is a polite status region with a spoken label', async () => {
	const source = join(PORTAL, 'components', 'Loading.jsx')
	const compiled = babel.transformSync(readFileSync(source, 'utf8'), {
		filename: source,
		babelrc: false,
		configFile: false,
		presets: [['@babel/preset-react', { runtime: 'automatic' }]],
	})
	mkdirSync(OUT_DIR, { recursive: true })
	const out = join(OUT_DIR, 'components_Loading.mjs')
	writeFileSync(out, compiled.code)
	const { default: Loading } = await import(pathToFileURL(out).href)
	const { createElement } = await import('react')
	const { renderToStaticMarkup } = await import('react-dom/server')

	const t = (key) => ({ 'Loading…': 'Laden…' })[key] ?? key
	const html = renderToStaticMarkup(createElement(Loading, { t }))
	assert.match(html, /role="status"/)
	assert.match(html, /aria-live="polite"/)
	assert.match(html, /<span aria-hidden="true">…<\/span>/)
	assert.match(html, /<span class="portaliq-sr-only">Laden…<\/span>/)

	const untranslated = renderToStaticMarkup(createElement(Loading, {}))
	assert.match(untranslated, /Loading…/, 'without a translator the key is spoken')

	for (const locale of ['en', 'nl']) {
		const bundle = JSON.parse(readFileSync(join(PORTAL, 'i18n', `${locale}.json`), 'utf8'))
		assert.equal(bundle['Loading…'], locale === 'nl' ? 'Laden…' : 'Loading…', locale)
	}
})
