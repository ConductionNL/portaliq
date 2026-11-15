#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// portal-live-regions.spec.mjs: the site tells a screen reader what a sighted
// resident sees change (portal-spa-nl-design-system-styling, WCAG 2.2 AA
// success criterion 4.1.3 Status messages; site-reaches-portal-parity
// REQ-SRP-012). Every loading indicator is a `role="status"` region with a
// spoken "Loading…" in the site's language instead of a bare "…"; every error
// shown by a `v-if` on an error value is an alert; no native prompt, alert or
// confirm dialog is used anywhere in the site.
//
// Usage:
//   node --test tests/portal-live-regions.spec.mjs

import assert from 'node:assert/strict'
import { readdirSync, readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const DIRS = [join(ROOT, 'src', 'site'), join(ROOT, 'src', 'embed')]

/**
 * Every .vue and .js file under the site and embed sources, with its source.
 *
 * @param {string} dir The directory.
 * @return {Array<{path: string, source: string}>}
 */
function sources(dir) {
	return readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
		const path = join(dir, entry.name)
		if (entry.isDirectory()) {
			return sources(path)
		}
		return /\.(vue|js)$/.test(entry.name)
			? [{ path: path.slice(ROOT.length + 1), source: readFileSync(path, 'utf8') }]
			: []
	})
}

const ALL = DIRS.flatMap((dir) => sources(dir))
const VUE = ALL.filter(({ path }) => path.endsWith('.vue'))

/**
 * The template of a single-file component, or ''.
 *
 * @param {string} source The SFC source.
 * @return {string}
 */
function templateOf(source) {
	const start = source.indexOf('<template>')
	const end = source.lastIndexOf('</template>')
	return start === -1 || end === -1 ? '' : source.slice(start, end)
}

/**
 * The opening tags of a template, with the line each starts on. Quotes are
 * respected, so a `>` inside an attribute value does not end a tag.
 *
 * @param {string} template The template source.
 * @return {Array<{tag: string, line: number}>}
 */
function openingTags(template) {
	const tags = []
	for (let i = 0; i < template.length; i++) {
		if (template[i] !== '<' || !/[a-zA-Z]/.test(template[i + 1] || '')) {
			continue
		}
		let quote = ''
		let j = i + 1
		for (; j < template.length; j++) {
			const c = template[j]
			if (quote) {
				quote = c === quote ? '' : quote
			} else if (c === '"' || c === "'") {
				quote = c
			} else if (c === '>') {
				break
			}
		}
		tags.push({ tag: template.slice(i, j + 1), line: template.slice(0, i).split('\n').length })
		i = j
	}
	return tags
}

test('no loading indicator is a bare ellipsis', () => {
	const bare = VUE.flatMap(({ path, source }) => {
		const lines = templateOf(source).split('\n')
		return lines
			.map((line, i) => ({ line, i }))
			.filter(({ line }) => /<(p|span|div)[^>]*>\s*…\s*<\/(p|span|div)>/.test(line))
			.filter(({ line, i }) => {
				// A visual "…" is fine when it is hidden from a screen reader and
				// sits in a status region that speaks the word instead.
				const around = lines.slice(Math.max(0, i - 2), i + 3).join('\n')
				return !(/aria-hidden="true"/.test(line) && /role="status"/.test(around) && /Loading…/.test(around))
			})
			.map(({ i }) => `${path}:${i + 1}`)
	})
	assert.deepEqual(bare, [])
})

test('every "Loading…" sits in a polite status region', () => {
	const silent = VUE.flatMap(({ path, source }) => {
		const lines = templateOf(source).split('\n')
		return lines
			.map((line, i) => ({ line, i }))
			.filter(({ line }) => /\b(t|tr)\('Loading…'\)/.test(line))
			.filter(({ i }) => !/role="status"/.test(lines.slice(Math.max(0, i - 6), i + 1).join('\n')))
			.map(({ i }) => `${path}:${i + 1}`)
	})
	assert.deepEqual(silent, [])
})

test('every error shown on an error value is an alert', () => {
	const silent = VUE.flatMap(({ path, source }) =>
		openingTags(templateOf(source))
			.filter(({ tag }) => /\sv-(else-)?if="[\w.]*[eE]rror"/.test(tag))
			.filter(({ tag }) => !/\srole="alert"/.test(tag))
			.map(({ line }) => `${path}:${line}`),
	)
	assert.deepEqual(silent, [])
})

test('the site uses no native prompt, alert or confirm dialog', () => {
	const native = ALL.filter(({ source }) =>
		source
			.split('\n')
			.filter((line) => !/^\s*(\/\/|\*|\/\*)/.test(line))
			.some((line) => /\bwindow\.(prompt|alert|confirm)\s*\(/.test(line)),
	).map(({ path }) => path)
	assert.deepEqual(native, [])
})

test('the shell announces its own loading state politely', () => {
	const app = readFileSync(join(ROOT, 'src', 'site', 'App.vue'), 'utf8')
	assert.match(app, /v-else-if="loading"\s+class="container"\s+role="status"\s+data-testid="site-loading">\s*\{\{ t\('Loading…'\) \}\}/)
})

test('"Loading…" is translated in both languages', () => {
	for (const locale of ['en', 'nl']) {
		const bundle = JSON.parse(readFileSync(join(ROOT, 'src', 'shared', 'i18n', `${locale}.json`), 'utf8'))
		assert.equal(bundle['Loading…'], locale === 'nl' ? 'Laden…' : 'Loading…', locale)
	}
})
