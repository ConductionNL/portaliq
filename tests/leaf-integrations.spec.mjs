// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// leaf-integrations.spec.mjs — the check that asks whether a declared leaf can
// actually reach a page.
//
// WHY THIS FILE EXISTS
// --------------------
// An integration leaf is two halves: a declaration (`{"type": "integration",
// "integrationId": "talk"}` in src/manifest.json, plus the schema's
// configuration.linkedTypes) and a rendering half (the registry the widget is
// resolved against at render time). Every check we have compares the two halves
// TO EACH OTHER. None of them asks whether either half is on a page.
//
// That gap has already cost the fleet a feature: humaniq registered both halves
// of its hours leaf, the parity gate compared them and passed, and the surface
// was dark on every consuming page for as long as it shipped. Portaliq's
// version of the same gap was simpler and just as invisible: it called none of
// the three registry bootstrap functions, so any integration widget it declared
// would have resolved to null and rendered nothing at all — not a card, not an
// absent state, not a console error, because an unregistered id and an
// uninstalled Nextcloud app look identical from outside.
//
// So this file asserts the things that are true only when a leaf can be seen:
// the widget is declared, it is placed in the layout, its icon is registered,
// its schema agrees with it, and the bundle that renders it installs the
// registry first.
//
// Usage: node --test tests/leaf-integrations.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'

const REPO_ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')

const manifest = JSON.parse(
	readFileSync(join(REPO_ROOT, 'src', 'manifest.json'), 'utf8'),
)
const register = JSON.parse(
	readFileSync(join(REPO_ROOT, 'lib', 'Settings', 'portaliq_register.json'), 'utf8'),
)
const mainJs = readFileSync(join(REPO_ROOT, 'src', 'main.js'), 'utf8')

/**
 * `src/main.js` with its comment lines removed.
 *
 * Load-bearing, found by mutation: main.js explains these three calls in a
 * comment block that names all three of them, so a search of the raw file still
 * matches after the calls themselves are commented out or deleted. The check
 * then passes on a bundle that installs nothing. Stripping line comments is the
 * cheapest way to make the search see code only, and this file is the one place
 * that difference decides whether a leaf renders.
 *
 * @type {string}
 */
const mainCode = mainJs
	.split('\n')
	.filter((line) => {
		const trimmed = line.trim()
		return trimmed.startsWith('//') === false && trimmed.startsWith('*') === false
	})
	.join('\n')
const iconsJs = readFileSync(join(REPO_ROOT, 'src', 'icons.js'), 'utf8')

const schemas = register.components.schemas

/**
 * Every integration widget in the manifest, with the page it sits on.
 *
 * @return {Array<object>} One entry per integration widget.
 */
function integrationWidgets() {
	const found = []
	for (const page of manifest.pages || []) {
		const config = page.config || {}
		for (const widget of config.widgets || []) {
			if (widget.type === 'integration') {
				found.push({ page, config, widget })
			}
		}
	}
	return found
}

test('every integration widget names an integration', () => {
	const widgets = integrationWidgets()
	assert.ok(
		widgets.length > 0,
		'No integration widget is declared at all. If the leaves were '
		+ 'deliberately dropped, delete this file with them; a silently empty '
		+ 'check is worse than no check.',
	)

	for (const { page, widget } of widgets) {
		assert.ok(
			typeof widget.integrationId === 'string' && widget.integrationId !== '',
			`${page.id}/${widget.id}: an integration widget without an `
			+ 'integrationId resolves to nothing and renders as a blank card.',
		)
	}
})

test('every integration widget is placed in its page layout', () => {
	for (const { page, config, widget } of integrationWidgets()) {
		const placed = (config.layout || []).some((item) => item.widgetId === widget.id)
		assert.ok(
			placed,
			`${page.id}/${widget.id}: declared but absent from config.layout. A `
			+ 'widget the grid never places is dark in exactly the way this file '
			+ 'exists to catch, and nothing else reports it.',
		)
	}
})

test('the bundle that renders a leaf installs the registry first', () => {
	// The order matters as much as the presence: registering into a registry
	// that has not been installed on the global leaves the entries in this
	// bundle's module singleton, where a leaf registered by any other bundle
	// will not be found.
	const install = mainCode.indexOf('installIntegrationRegistry()')
	const builtin = mainCode.indexOf('registerBuiltinIntegrations()')
	const leaves = mainCode.indexOf('registerLeafIntegrations()')

	assert.ok(
		install !== -1,
		'src/main.js does not call installIntegrationRegistry(). Every '
		+ 'integration widget in the manifest then resolves to null and renders '
		+ 'nothing, with no error anywhere.',
	)
	assert.ok(builtin !== -1, 'src/main.js does not call registerBuiltinIntegrations().')
	assert.ok(leaves !== -1, 'src/main.js does not call registerLeafIntegrations().')
	assert.ok(
		install < builtin && builtin < leaves,
		'installIntegrationRegistry() must run before the two register calls, '
		+ 'and the builtin (bespoke) pairs before the generic leaf factory, so '
		+ 'the first-wins collision policy keeps the richer component.',
	)
})

test('every integration icon the manifest names is registered', () => {
	for (const { page, widget } of integrationWidgets()) {
		if (!widget.icon) {
			continue
		}
		assert.ok(
			new RegExp(`\\b${widget.icon}\\b`).test(iconsJs),
			`${page.id}/${widget.id}: icon "${widget.icon}" is not in `
			+ 'src/icons.js. An unregistered icon draws no glyph at all rather '
			+ 'than a fallback, so the card renders half-built.',
		)
	}
})

test('the schema behind an integration widget declares the same leaf', () => {
	for (const { page, config, widget } of integrationWidgets()) {
		const schemaSlug = config.schema
		assert.ok(schemaSlug, `${page.id}: an integration widget on a page with no schema.`)

		const schema = schemas[schemaSlug]
		assert.ok(schema, `${page.id}: schema "${schemaSlug}" is not in the register.`)

		const linkedTypes = (schema.configuration || {}).linkedTypes || []
		assert.ok(
			linkedTypes.includes(widget.integrationId),
			`${schemaSlug}: the manifest adopts the "${widget.integrationId}" leaf `
			+ 'but the schema does not declare it in configuration.linkedTypes, so '
			+ 'the sidebar tab surface offers a different set from the page body. '
			+ 'Note the nesting: a top-level linkedTypes is dropped on save.',
		)
	}
})

test('a schema that declares a leaf had its version bumped to carry it', () => {
	// The importer compares properties, required, authorization and the
	// x-openregister annotations. configuration is in none of those, so a
	// configuration-only edit at an unchanged version is skipped in silence and
	// the declaration never reaches the running instance.
	for (const slug of ['portalSubmission', 'portalMessage', 'portalAccount']) {
		const schema = schemas[slug]
		assert.ok(schema, `${slug} is missing from the register.`)
		assert.ok(
			(schema.configuration || {}).linkedTypes,
			`${slug} should declare configuration.linkedTypes.`,
		)
		assert.notEqual(
			schema.version,
			'0.0.0',
			`${slug} must carry a real version; the import gate reads it.`,
		)
	}
})

test('no portal-edge page renders an integration widget', () => {
	// The structural half of the ADR-046 boundary. Portal pages are portalPage
	// objects served by the portal edge, never manifest pages; this asserts the
	// manifest keeps its leaves on schemas that only staff pages address.
	const edgeSchemas = new Set(['portalPage', 'page', 'menu'])
	for (const { page, config, widget } of integrationWidgets()) {
		assert.ok(
			edgeSchemas.has(config.schema) === false,
			`${page.id}/${widget.id}: an integration leaf on "${config.schema}", `
			+ 'which is visitor-facing content. A leaf is a Nextcloud component '
			+ 'for a Nextcloud user (ADR-046).',
		)
	}
})
