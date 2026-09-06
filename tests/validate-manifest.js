#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// validate-manifest.js — schema-validates src/manifest.json against the
// @conduction/nextcloud-vue app-manifest schema using Ajv.
//
// Usage:
//   node tests/validate-manifest.js
//
// Exit codes:
//   0 — manifest validates against the schema with zero errors
//   1 — manifest fails validation (or schema/manifest cannot be loaded)
//
// Schema VARIANT is chosen from the manifest's own `$schema` — a manifest
// pointing at `app-manifest-v2.schema.json` is validated against the v2
// schema, anything else against v1. Hardcoding v1 here (as this script did
// until 2026-08) validated portaliq's v2 manifest against the v1 schema,
// whose `$defs.widgetDef` sets `additionalProperties: false` and predates the
// `content` / `icon` / `integrationId` widget keys that CnDetailPage actually
// renders — reporting 21 errors against a manifest that renders correctly and
// passes its own declared schema with zero errors.
//
// Schema lookup order (first hit wins), for the selected variant:
//   1. Env var APP_MANIFEST_SCHEMA — explicit absolute path to a schema JSON
//   2. node_modules/@conduction/nextcloud-vue/src/schemas/<variant>
//   3. ../nextcloud-vue/src/schemas/<variant> (sibling worktree)

'use strict'

const fs = require('fs')
const path = require('path')

const REPO_ROOT = path.resolve(__dirname, '..')

const MANIFEST_PATH = path.join(REPO_ROOT, 'src', 'manifest.json')

/**
 * Determine whether the manifest is v2 (points to the v2 $schema URL).
 *
 * @param {object} manifest Parsed manifest object.
 * @return {boolean} True when the manifest targets the v2 schema.
 */
function isV2Manifest(manifest) {
	return (
		typeof manifest.$schema === 'string'
		&& manifest.$schema.includes('app-manifest-v2')
	)
}

/**
 * Build the ordered list of schema file candidates for a given manifest.
 * V2 manifests prefer the v2 schema file; v1 manifests prefer the v1 file.
 *
 * @param {object} manifest Parsed manifest object.
 * @return {string[]} Candidate paths (env override first, then node_modules, then sibling worktree).
 */
function schemaCandidates(manifest) {
	const schemaFile = isV2Manifest(manifest)
		? 'app-manifest-v2.schema.json'
		: 'app-manifest.schema.json'
	return [
		process.env.APP_MANIFEST_SCHEMA,
		path.join(
			REPO_ROOT,
			'node_modules',
			'@conduction',
			'nextcloud-vue',
			'src',
			'schemas',
			schemaFile,
		),
		path.join(REPO_ROOT, '..', 'nextcloud-vue', 'src', 'schemas', schemaFile),
	].filter(Boolean)
}

function findSchemaPath(manifest) {
	for (const candidate of schemaCandidates(manifest)) {
		try {
			if (fs.existsSync(candidate) && fs.statSync(candidate).isFile()) {
				return candidate
			}
		} catch (_) {
			// continue to next candidate
		}
	}
	return null
}

function loadJson(file) {
	const raw = fs.readFileSync(file, 'utf8')
	return JSON.parse(raw)
}

function loadAjv() {
	// The canonical schema uses JSON Schema draft 2020-12 (`$schema`:
	// "https://json-schema.org/draft/2020-12/schema"). Standard Ajv (v7+)
	// does not auto-load the 2020 meta-schema; we need the `ajv/dist/2020`
	// entry point.
	// Declared without initialisers: every path below assigns before use, and
	// the `= null` seeds were dead stores (eslint no-useless-assignment). Both
	// callers test falsiness, so an unassigned binding behaves identically.
	let Ajv2020
	let addFormats
	try {
		// Ajv 8+ ships the 2020 draft entry point.
		Ajv2020 = require('ajv/dist/2020').default || require('ajv/dist/2020')
	} catch (_) {
		try {
			// Fall back to standard Ajv (will fail to compile the 2020-draft
			// schema; we surface that error clearly).
			Ajv2020 = require('ajv').default || require('ajv')
		} catch (__) {
			console.error('[validate-manifest] Ajv not installed in node_modules.')
			console.error(
				'[validate-manifest] Install with: npm i -D ajv ajv-formats',
			)
			console.error(
				'[validate-manifest] Falling back to a structural lint pass.',
			)
			return { Ajv: null, addFormats: null }
		}
	}
	try {
		addFormats = require('ajv-formats').default || require('ajv-formats')
	} catch (_) {
		// ajv-formats is optional; the schema uses "uri" format on $schema
		// which without ajv-formats is silently accepted.
		addFormats = null
	}
	return { Ajv: Ajv2020, addFormats }
}

function structuralLint(manifest) {
	// Minimal structural fallback when Ajv isn't available.
	const errors = []
	if (!manifest.version || typeof manifest.version !== 'string') {
		errors.push('top-level: version (string) is required')
	}
	if (!Array.isArray(manifest.menu))
		errors.push('top-level: menu (array) is required')
	if (!Array.isArray(manifest.pages))
		errors.push('top-level: pages (array) is required')
	// Mirrors $defs/page/properties/type in app-manifest-v2.schema.json — every
	// entry is a type CnAppRoot actually renders. Do NOT widen this to make a
	// manifest pass; widen it only once the renderer has gained the type.
	const allowedTypes = new Set([
		'chat',
		'custom',
		'dashboard',
		'detail',
		'files',
		'form',
		'index',
		'logs',
		'map',
		'roadmap',
		'search',
		'settings',
		'wiki',
	])
	const seenIds = new Set()
	for (let i = 0; i < (manifest.pages || []).length; i++) {
		const page = manifest.pages[i]
		if (!page || typeof page !== 'object') {
			errors.push(`pages[${i}]: must be an object`)
			continue
		}
		for (const required of ['id', 'route', 'type', 'title']) {
			if (!page[required] || typeof page[required] !== 'string') {
				errors.push(
					`pages[${i}]: missing required string field "${required}"`,
				)
			}
		}
		if (page.type && !allowedTypes.has(page.type)) {
			errors.push(`pages[${i}].type: "${page.type}" not in v1.1 enum`)
		}
		if (page.id) {
			if (seenIds.has(page.id))
				errors.push(`pages[${i}].id: duplicate "${page.id}"`)
			seenIds.add(page.id)
		}
		if (page.type === 'custom' && !page.component) {
			errors.push(`pages[${i}]: type=custom requires component field`)
		}
	}
	return errors
}

// Widget types CnDashboardPage can actually MOUNT, in its own branch order
// (see node_modules/@conduction/nextcloud-vue/src/components/CnDashboardPage/
// CnDashboardPage.vue — isTile / isChart / isStatsBlock). Anything else falls
// through to the "unavailable" placeholder: the page renders, the widget does
// not, and nothing fails.
// Built-in types first. A consumer widget registered in src/registry.js with
// kind: "widget" is ALSO mountable: CnDashboardPage resolves `type` against
// the app registry before its own catalog (REQ-MVR-005, custom over built-in).
// The registry imports .vue files, so it cannot be required from this plain
// node script; its widget keys are read off the source instead, by the exact
// two-line shape every entry in this repo uses (`Key: {` then `kind: 'widget'`).
// A key that is not written that way is simply not seen, and the widget is
// reported as unmountable, which is the failure direction that makes noise.
const RENDERABLE_WIDGET_TYPES = new Set([
	'tile',
	'chart',
	'stats-block',
	...registryWidgetKeys(),
])

/**
 * The kind: "widget" keys declared in src/registry.js.
 *
 * @return {string[]} The registry keys.
 */
function registryWidgetKeys() {
	const registry = path.join(__dirname, '..', 'src', 'registry.js')
	if (!fs.existsSync(registry)) {
		return []
	}
	const source = fs.readFileSync(registry, 'utf8')
	const keys = []
	const entry = /^\t([A-Za-z][A-Za-z0-9]*|'[a-z0-9-]+'): \{\n\t\tkind: 'widget'/gm
	let match
	while ((match = entry.exec(source)) !== null) {
		keys.push(match[1].replace(/^'|'$/g, ''))
	}
	return keys
}

// The only link types CnTileWidget resolves (see its `tileUrl` computed).
// There is no default branch: anything else is used verbatim as an href.
const TILE_LINK_TYPES = new Set(['app', 'url'])

/**
 * Check what the schema cannot: that a dashboard page's widgets will actually
 * appear on screen.
 *
 * TWO WAYS A VALID MANIFEST RENDERS A BLANK PAGE, both found in this repo:
 *
 *   no layout entry    CnDashboardPage iterates `config.layout`, not
 *                      `config.widgets`. A page declaring three widgets and no
 *                      layout renders its empty state — "No widgets
 *                      configured" — which reads as "nothing was configured
 *                      yet" rather than "your configuration is unreachable".
 *   unrenderable type  `text` and `object-table` are not in the renderer's
 *                      vocabulary. They validate, they round-trip, and they
 *                      mount nothing.
 *
 * Neither is a schema violation: the schema describes the manifest's SHAPE,
 * and both of these are true statements about a component's behaviour.
 *
 * @param {object} manifest Parsed manifest object.
 * @return {string[]} Human-readable problems; empty when the manifest is sound.
 */
function rendererContractLint(manifest) {
	const errors = []
	const dashboards = (manifest.pages || []).filter(
		(p) =>
			p
			&& p.type === 'dashboard'
			&& p.config
			&& Array.isArray(p.config.widgets),
	)

	// A check that inspects nothing must not report success. If the manifest
	// stops using dashboard pages, or their shape moves, this lint would
	// otherwise pass by vacuum — the failure mode it exists to catch.
	if (dashboards.length === 0) {
		return [
			'renderer contract: found NO dashboard pages with a config.widgets array. '
				+ 'Either the manifest changed shape or this lint is looking in the wrong place; '
				+ 'a check that inspected nothing is not a passing check.',
		]
	}

	for (const page of dashboards) {
		const widgets = page.config.widgets
		const layout = Array.isArray(page.config.layout) ? page.config.layout : []
		const placed = new Set(layout.map((l) => l && l.widgetId))

		for (const widget of widgets) {
			if (!widget || typeof widget !== 'object') continue

			if (!placed.has(widget.id)) {
				errors.push(
					`pages[${page.id}].config: widget "${widget.id}" has no layout entry — `
						+ 'it will not render (CnDashboardPage iterates layout, not widgets)',
				)
			}

			// NC Dashboard API widgets are mounted by their own branch and
			// carry no renderable `type`.
			if (widget.itemApiVersions) continue

			if (!RENDERABLE_WIDGET_TYPES.has(widget.type)) {
				errors.push(
					`pages[${page.id}].config: widget "${widget.id}" has type "${widget.type}", `
						+ `which CnDashboardPage cannot mount (renderable: ${[...RENDERABLE_WIDGET_TYPES].join(', ')})`,
				)
			}

			// A tile's linkType is `'app' | 'url'` and nothing else. CnTileWidget
			// has no default branch: an unrecognised linkType falls through to
			// the raw linkValue as an href, so `{linkType: 'route', linkValue:
			// '/portals'}` renders a perfectly good-looking tile that navigates
			// to a 404 — the tile is styled, clickable and wrong.
			if (widget.type === 'tile') {
				if (!TILE_LINK_TYPES.has(widget.linkType)) {
					errors.push(
						`pages[${page.id}].config: tile "${widget.id}" has linkType "${widget.linkType}" — `
							+ `CnTileWidget resolves only ${[...TILE_LINK_TYPES].join(' / ')} and would use linkValue as a raw href`,
					)
				}

				if (!widget.linkValue) {
					errors.push(
						`pages[${page.id}].config: tile "${widget.id}" has no linkValue — it would link to "#"`,
					)
				}
			}
		}

		for (const entry of layout) {
			if (
				entry
				&& entry.widgetId
				&& !widgets.some((w) => w && w.id === entry.widgetId)
			) {
				errors.push(
					`pages[${page.id}].config.layout: entry references unknown widget "${entry.widgetId}"`,
				)
			}
		}
	}

	// DETAIL PAGES HAVE THE SAME LAYOUT TRAP, and this lint used to look right
	// past them. `CnDetailPage` feeds the same grid engine, so a widget with no
	// layout entry is just as unreachable there — it simply does not appear,
	// and the page looks like an object that has nothing to show.
	//
	// Their TYPES are not checked. A detail page mounts `data`, `related`,
	// `integration` and `object-geo` directly and resolves everything else
	// through the shared widget-type registry, which apps extend at runtime —
	// so an allow-list here would be a guess about what is registered, and a
	// wrong guess would fail a working page. The pairing is checkable without
	// guessing; the type is not.
	const details = (manifest.pages || []).filter(
		(p) =>
			p && p.type === 'detail' && p.config && Array.isArray(p.config.widgets),
	)

	for (const page of details) {
		const widgets = page.config.widgets
		const layout = Array.isArray(page.config.layout) ? page.config.layout : []
		const placed = new Set(layout.map((l) => l && l.widgetId))

		for (const widget of widgets) {
			if (!widget || typeof widget !== 'object') continue
			if (!placed.has(widget.id)) {
				errors.push(
					`pages[${page.id}].config: widget "${widget.id}" has no layout entry — `
						+ 'a detail-page widget without one never renders',
				)
			}
		}

		for (const entry of layout) {
			if (
				entry
				&& entry.widgetId
				&& !widgets.some((w) => w && w.id === entry.widgetId)
			) {
				errors.push(
					`pages[${page.id}].config.layout: entry references unknown widget "${entry.widgetId}"`,
				)
			}
		}
	}

	console.log(
		`[validate-manifest] renderer contract: inspected ${dashboards.length} dashboard page(s) `
			+ `and ${details.length} detail page(s), `
			+ `${
				dashboards.reduce((n, p) => n + p.config.widgets.length, 0)
				+ details.reduce((n, p) => n + p.config.widgets.length, 0)
			} widget(s)`,
	)

	return errors
}

/**
 * Run the renderer-contract lint and exit non-zero when it finds anything.
 *
 * @param {object} manifest Parsed manifest object.
 * @return {void}
 */
function enforceRendererContract(manifest) {
	const errors = rendererContractLint(manifest)
	if (errors.length === 0) {
		console.log('[validate-manifest] renderer contract: PASS (0 issues)')
		return
	}

	console.error('[validate-manifest] renderer contract: FAIL')
	for (const err of errors) console.error(`  - ${err}`)
	process.exit(1)
}

function main() {
	if (!fs.existsSync(MANIFEST_PATH)) {
		console.error(`[validate-manifest] manifest not found: ${MANIFEST_PATH}`)
		process.exit(1)
	}

	const manifest = loadJson(MANIFEST_PATH)
	console.log(`[validate-manifest] manifest: ${MANIFEST_PATH}`)
	console.log(`[validate-manifest] manifest.version: ${manifest.version}`)
	console.log(`[validate-manifest] pages: ${(manifest.pages || []).length}`)

	console.log(
		`[validate-manifest] manifest.$schema: ${manifest.$schema || '(unset)'}`,
	)
	console.log(
		`[validate-manifest] schema variant: ${isV2Manifest(manifest) ? 'v2' : 'v1'}`,
	)

	const schemaPath = findSchemaPath(manifest)
	if (!schemaPath) {
		console.warn(
			'[validate-manifest] no schema candidate resolved; falling back to structural lint.',
		)
		const errors = structuralLint(manifest)
		if (errors.length === 0) {
			console.log('[validate-manifest] structural lint: PASS (0 issues)')
			enforceRendererContract(manifest)
			process.exit(0)
		}
		console.error('[validate-manifest] structural lint: FAIL')
		for (const err of errors) console.error(`  - ${err}`)
		process.exit(1)
	}
	console.log(`[validate-manifest] schema: ${schemaPath}`)
	const schema = loadJson(schemaPath)
	console.log(`[validate-manifest] schema.version: ${schema.version || '(unset)'}`)

	const { Ajv, addFormats } = loadAjv()
	if (!Ajv) {
		const errors = structuralLint(manifest)
		if (errors.length === 0) {
			console.log(
				'[validate-manifest] structural lint (no Ajv): PASS (0 issues)',
			)
			enforceRendererContract(manifest)
			process.exit(0)
		}
		console.error('[validate-manifest] structural lint (no Ajv): FAIL')
		for (const err of errors) console.error(`  - ${err}`)
		process.exit(1)
	}

	const ajv = new Ajv({ allErrors: true, strict: false })
	if (addFormats) addFormats(ajv)
	const validate = ajv.compile(schema)
	const ok = validate(manifest)
	if (ok) {
		console.log('[validate-manifest] Ajv validation: PASS (0 errors)')
		enforceRendererContract(manifest)
		process.exit(0)
	}
	console.error('[validate-manifest] Ajv validation: FAIL')
	for (const err of validate.errors || []) {
		console.error(
			`  - ${err.instancePath || '(root)'} ${err.message} (keyword=${err.keyword})`,
		)
	}
	process.exit(1)
}

main()
