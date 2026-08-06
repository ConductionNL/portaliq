#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// validate-manifest.js — schema-validates src/manifest.json against the
// @conduction/nextcloud-vue app-manifest schema using Ajv.
//
// The schema is chosen from the manifest's OWN `$schema` declaration:
//
//   • `$schema` ending in `app-manifest-v2.schema.json` → the **v2** schema
//     (`app-manifest-v2.schema.json`).
//   • anything else (including no `$schema`)            → the **v1** schema
//     (`app-manifest.schema.json`).
//
// This dispatch is the whole point of the file. `src/manifest.json` migrated
// to the v2 dialect (top-level `widgets[]` with `slot`/`widgetKey`, detail
// widgets carrying `icon` / `content` / `integrationId`, page types such as
// `roadmap` / `form` / `map` / `wiki` / `search`), while this validator was
// still hard-wired to the v1 schema it was scaffolded with. Measuring a v2
// document against the v1 contract is a category error: it reported 22 Ajv
// violations that are not defects — `$defs/widgetDef` is a *dashboard*-widget
// $def with `additionalProperties: false`, and the v1 `page` $def likewise
// forbids the v2 top-level `widgets[]`. The same v2 manifest validates clean
// against the v2 schema.
//
// Usage:
//   node tests/validate-manifest.js
//
// Exit codes:
//   0 — manifest validates against the matching schema with zero errors
//   1 — manifest fails validation, cannot be loaded, or (v2 only) the
//       matching schema / Ajv could not be resolved so nothing was checked
//
// v2 schema lookup order (first hit wins):
//   1. Env var APP_MANIFEST_V2_SCHEMA — explicit absolute path to a schema JSON
//   2. node_modules/@conduction/nextcloud-vue/src/schemas/app-manifest-v2.schema.json
//   3. ../nextcloud-vue/src/schemas/app-manifest-v2.schema.json (sibling worktree)
//
// v1 schema lookup order (first hit wins):
//   1. Env var APP_MANIFEST_SCHEMA — explicit absolute path to a schema JSON
//   2. node_modules/@conduction/nextcloud-vue/src/schemas/app-manifest.schema.json
//   3. ../nextcloud-vue/src/schemas/app-manifest.schema.json (sibling worktree)
//   4. /tmp/worktrees/nextcloud-vue-manifest-v1/src/schemas/app-manifest.schema.json (v1.2.0 consolidation worktree)
//   5. /tmp/worktrees/nextcloud-vue-page-type-extensions/src/schemas/app-manifest.schema.json (v1.1.0 fallback)
//
// The fourth / fifth v1 options exist because the v1.x schema was not
// released to npm when this file was scaffolded; the consolidated
// `manifest-v1` worktree carried the canonical v1.2.0 source.
//
// A v2 manifest whose schema cannot be resolved exits NON-ZERO rather than
// degrading to the v1 structural lint. That lint encodes the v1.1-era page
// enum, so running it over a v2 manifest produces bogus failures — and, more
// dangerously, a validator that silently swaps in a weaker contract reports
// success for a check that never actually ran. The v1 path keeps its existing
// structural-lint fallback unchanged.

'use strict'

const fs = require('fs')
const path = require('path')

const REPO_ROOT = path.resolve(__dirname, '..')

const MANIFEST_PATH = path.join(REPO_ROOT, 'src', 'manifest.json')

// A manifest is v2 when its own `$schema` names the v2 document. Mirrors the
// suffix test in tests/manifest-v2.spec.js so both validators agree on which
// dialect a given manifest is written in.
const V2_SCHEMA_URL_SUFFIX = 'app-manifest-v2.schema.json'

const SCHEMA_CANDIDATES = [
	process.env.APP_MANIFEST_SCHEMA,
	path.join(REPO_ROOT, 'node_modules', '@conduction', 'nextcloud-vue', 'src', 'schemas', 'app-manifest.schema.json'),
	path.join(REPO_ROOT, '..', 'nextcloud-vue', 'src', 'schemas', 'app-manifest.schema.json'),
	'/tmp/worktrees/nextcloud-vue-manifest-v1/src/schemas/app-manifest.schema.json',
	'/tmp/worktrees/nextcloud-vue-page-type-extensions/src/schemas/app-manifest.schema.json',
].filter(Boolean)

const V2_SCHEMA_CANDIDATES = [
	process.env.APP_MANIFEST_V2_SCHEMA,
	path.join(REPO_ROOT, 'node_modules', '@conduction', 'nextcloud-vue', 'src', 'schemas', 'app-manifest-v2.schema.json'),
	path.join(REPO_ROOT, '..', 'nextcloud-vue', 'src', 'schemas', 'app-manifest-v2.schema.json'),
].filter(Boolean)

/**
 * True when the manifest declares the v2 dialect via its own `$schema`.
 *
 * @param {object} manifest Parsed manifest.
 * @return {boolean} Whether the manifest is a v2 manifest.
 */
function isV2Manifest(manifest) {
	return typeof manifest.$schema === 'string'
		&& manifest.$schema.endsWith(V2_SCHEMA_URL_SUFFIX)
}

function findSchemaPath(candidates = SCHEMA_CANDIDATES) {
	for (const candidate of candidates) {
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
	let Ajv2020 = null
	let addFormats = null
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
			console.error('[validate-manifest] Install with: npm i -D ajv ajv-formats')
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
	if (!Array.isArray(manifest.menu)) errors.push('top-level: menu (array) is required')
	if (!Array.isArray(manifest.pages)) errors.push('top-level: pages (array) is required')
	const allowedTypes = new Set(['index', 'detail', 'dashboard', 'logs', 'settings', 'chat', 'files', 'custom'])
	const seenIds = new Set()
	for (let i = 0; i < (manifest.pages || []).length; i++) {
		const page = manifest.pages[i]
		if (!page || typeof page !== 'object') {
			errors.push(`pages[${i}]: must be an object`)
			continue
		}
		for (const required of ['id', 'route', 'type', 'title']) {
			if (!page[required] || typeof page[required] !== 'string') {
				errors.push(`pages[${i}]: missing required string field "${required}"`)
			}
		}
		if (page.type && !allowedTypes.has(page.type)) {
			errors.push(`pages[${i}].type: "${page.type}" not in v1.1 enum`)
		}
		if (page.id) {
			if (seenIds.has(page.id)) errors.push(`pages[${i}].id: duplicate "${page.id}"`)
			seenIds.add(page.id)
		}
		if (page.type === 'custom' && !page.component) {
			errors.push(`pages[${i}]: type=custom requires component field`)
		}
	}
	return errors
}

/**
 * Validate a v2 manifest against the v2 schema. Never falls back to the v1
 * structural lint — that lint encodes the v1.1 page enum and would reject
 * legitimate v2 page types. If the v2 schema or Ajv cannot be resolved this
 * exits non-zero, because a validator that could not reach its contract has
 * not validated anything and must not report success.
 *
 * @param {object} manifest Parsed v2 manifest.
 * @return {void} Always exits the process.
 */
function validateV2(manifest) {
	const schemaPath = findSchemaPath(V2_SCHEMA_CANDIDATES)
	if (!schemaPath) {
		console.error('[validate-manifest] manifest declares the v2 dialect but no v2 schema candidate resolved.')
		console.error('[validate-manifest] Looked in:')
		for (const candidate of V2_SCHEMA_CANDIDATES) console.error(`  - ${candidate}`)
		console.error('[validate-manifest] Run `npm ci` (the schema ships in @conduction/nextcloud-vue) '
			+ 'or point APP_MANIFEST_V2_SCHEMA at it.')
		process.exit(1)
	}
	console.log(`[validate-manifest] schema: ${schemaPath}`)
	const schema = loadJson(schemaPath)
	console.log(`[validate-manifest] schema.version: ${schema.version || '(unset)'}`)

	const { Ajv, addFormats } = loadAjv()
	if (!Ajv) {
		console.error('[validate-manifest] Ajv is unavailable, so the v2 manifest was NOT validated.')
		process.exit(1)
	}

	const ajv = new Ajv({ allErrors: true, strict: false })
	if (addFormats) addFormats(ajv)
	const validate = ajv.compile(schema)
	if (validate(manifest)) {
		console.log('[validate-manifest] Ajv v2 validation: PASS (0 errors)')
		process.exit(0)
	}
	console.error('[validate-manifest] Ajv v2 validation: FAIL')
	for (const err of validate.errors || []) {
		console.error(`  - ${err.instancePath || '(root)'} ${err.message} (keyword=${err.keyword})`)
	}
	process.exit(1)
}

function main() {
	if (!fs.existsSync(MANIFEST_PATH)) {
		console.error(`[validate-manifest] manifest not found: ${MANIFEST_PATH}`)
		process.exit(1)
	}

	const manifest = loadJson(MANIFEST_PATH)
	const v2 = isV2Manifest(manifest)
	console.log(`[validate-manifest] manifest: ${MANIFEST_PATH}`)
	console.log(`[validate-manifest] manifest.version: ${manifest.version}`)
	console.log(`[validate-manifest] manifest.$schema: ${manifest.$schema || '(absent)'}`)
	console.log(`[validate-manifest] dialect: ${v2 ? 'v2' : 'v1'}`)
	console.log(`[validate-manifest] pages: ${(manifest.pages || []).length}`)

	if (v2) {
		validateV2(manifest)
		return
	}

	const schemaPath = findSchemaPath()
	if (!schemaPath) {
		console.warn('[validate-manifest] no schema candidate resolved; falling back to structural lint.')
		const errors = structuralLint(manifest)
		if (errors.length === 0) {
			console.log('[validate-manifest] structural lint: PASS (0 issues)')
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
			console.log('[validate-manifest] structural lint (no Ajv): PASS (0 issues)')
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
		process.exit(0)
	}
	console.error('[validate-manifest] Ajv validation: FAIL')
	for (const err of validate.errors || []) {
		console.error(`  - ${err.instancePath || '(root)'} ${err.message} (keyword=${err.keyword})`)
	}
	process.exit(1)
}

main()
