#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// check-schema-l10n.js — a RATCHET on untranslated schema strings.
//
// WHY THIS EXISTS
//
//   Every string inside a form comes from the OpenRegister schema, not from
//   the app manifest: `fieldsFromSchema()` runs a property `title` and
//   `description` through the injected `cnTranslate`, which CnAppRoot binds to
//   THIS app's id. So a schema title is a key in THIS catalogue — and when the
//   key is absent, `t()` hands the source string back and the field renders in
//   English inside an otherwise translated form. Nothing errors.
//
//   Measured across the fleet on 2026-08-23: 30,459 schema strings had no
//   catalogue key. That is far too much to translate in one go, and the
//   descriptions need rewriting for the person filling in the form before
//   translating them is even worth doing.
//
//   So this is a RATCHET, not a gate. It records how many strings are
//   currently uncovered and fails only when that number GROWS — the debt is
//   measured and cannot expand, while burning it down stays an ordinary PR.
//   Same shape as the JSDoc baseline in @conduction/nextcloud-vue.
//
// WHAT COUNTS AS A SCHEMA STRING
//
//   - a schema `title`         — the create/edit dialog heading, and the noun
//                                in an index page's Add button
//   - a property `title`       — the field's label and its column header
//   - a property `description` — the helper text under the field
//   - the VALUES of a property's `x-enum-labels` — dropdown options and the
//     text of a status badge
//
//   Enum VALUES themselves are deliberately NOT counted. They are stored
//   contract values — several are non-English by design (`ingediend`) — and
//   are never rendered once the property declares `x-enum-labels`.
//
//   `x-notes` is not counted either: it holds the engineering rationale a
//   description used to carry, is never rendered, and so is never translated.
//
// Usage:
//   node scripts/check-schema-l10n.js            (npm run check:schema-l10n)
//   node scripts/check-schema-l10n.js --update   rewrite the baseline
//   node scripts/check-schema-l10n.js --list     print what is uncovered
//
// Exit codes:
//   0 — uncovered count is at or below the baseline
//   1 — it grew, or the baseline file is missing

'use strict'

const fs = require('fs')
const path = require('path')

const REPO_ROOT = path.resolve(__dirname, '..')
const SCHEMA_DIR = path.join(REPO_ROOT, 'lib', 'Settings')
const CATALOGUE = path.join(REPO_ROOT, 'l10n', 'en.json')
const BASELINE = path.join(REPO_ROOT, 'l10n', '.schema-l10n-baseline.json')

/**
 * Every *.json under lib/Settings, at any depth — apps differ in whether they
 * use register.d/, templates/ or a single monolith.
 *
 * @param {string} dir - directory to walk
 * @return {string[]} absolute paths
 */
function schemaFiles(dir) {
	if (!fs.existsSync(dir)) return []
	return fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
		const full = path.join(dir, entry.name)
		if (entry.isDirectory()) return schemaFiles(full)
		return entry.name.endsWith('.json') ? [full] : []
	})
}

/**
 * The display strings found so far, and the file they are being read from.
 */
class FoundStrings {
	/**
	 * @param {Map<string, string>} sink - string -> first place it was seen
	 * @param {string} where - file label, for --list
	 */
	constructor(sink, where) {
		this.sink = sink
		this.where = where
	}

	/**
	 * Keep a non-blank string with the first place it was seen.
	 *
	 * @param {unknown} value - the candidate string
	 * @param {string} what - which part of the schema it came from
	 */
	remember(value, what) {
		if (typeof value !== 'string' || value.trim() === '') return
		if (!this.sink.has(value)) this.sink.set(value, `${this.where}:${what}`)
	}
}

/**
 * Whether a value is a non-null object (arrays included).
 *
 * @param {unknown} value - the value
 * @return {boolean} true for an object
 */
function isObject(value) {
	return value !== null && typeof value === 'object'
}

/**
 * Collect the display strings a schema puts on screen.
 *
 * @param {object|Array} node - current node
 * @param {FoundStrings} found - the strings so far
 */
function collect(node, found) {
	if (Array.isArray(node)) {
		collectEach(node, found)
		return
	}
	if (!isObject(node)) return

	if (isObject(node.properties) && !Array.isArray(node.properties)) {
		collectSchema(node, found)
	}

	collectEach(Object.values(node), found)
}

/**
 * Collect from each node in turn.
 *
 * @param {Array} nodes - the nodes
 * @param {FoundStrings} found - the strings so far
 */
function collectEach(nodes, found) {
	for (const item of nodes) collect(item, found)
}

/**
 * A schema's title and the strings of each of its properties.
 *
 * @param {object} node - a node with a `properties` object
 * @param {FoundStrings} found - the strings so far
 */
function collectSchema(node, found) {
	found.remember(node.title, 'schema title')
	for (const entry of Object.entries(node.properties)) {
		if (isObject(entry[1])) collectProperty(found, entry)
	}
}

/**
 * A property's title, description and enum labels (its own and its items').
 *
 * @param {FoundStrings} found - the strings so far
 * @param {Array} entry - the property key and the property
 */
function collectProperty(found, entry) {
	const [key, prop] = entry
	found.remember(prop.title, `${key}.title`)
	found.remember(prop.description, `${key}.description`)
	for (const source of [prop, prop.items]) {
		for (const label of enumLabels(source))
			found.remember(label, `${key}.x-enum-labels`)
	}
}

/**
 * The values of a node's `x-enum-labels`, none when it has no such object.
 *
 * @param {unknown} source - a property or its `items`
 * @return {Array} the labels
 */
function enumLabels(source) {
	if (!isObject(source)) return []
	const labels = source['x-enum-labels']
	return isObject(labels) ? Object.values(labels) : []
}

/**
 * Every display string in the schema documents, with where it was first seen.
 *
 * @return {Map<string, string>} string -> first place it was seen
 */
function schemaStrings() {
	const strings = new Map()
	for (const file of schemaFiles(SCHEMA_DIR)) {
		let doc
		try {
			doc = JSON.parse(fs.readFileSync(file, 'utf8'))
		} catch {
			continue // not a schema document; the manifest checks own their own files
		}
		collect(doc, new FoundStrings(strings, path.relative(REPO_ROOT, file)))
	}
	return strings
}

/**
 * The keys of the English catalogue.
 *
 * @return {Set<string>} the covered strings
 */
function catalogueKeys() {
	try {
		return new Set(
			Object.keys(
				JSON.parse(fs.readFileSync(CATALOGUE, 'utf8')).translations || {},
			),
		)
	} catch {
		// no catalogue yet — then everything is uncovered, which the baseline records
		return new Set()
	}
}

/**
 * Write the uncovered count as the new baseline.
 *
 * @param {string[]} uncovered - the uncovered strings
 */
function writeBaseline(uncovered) {
	fs.writeFileSync(
		BASELINE,
		JSON.stringify({ uncovered: uncovered.length }, null, 2) + '\n',
	)
	console.log(
		`baseline written: ${uncovered.length} uncovered schema string(s)`,
	)
}

/**
 * The recorded uncovered count; exits 1 when there is no baseline file.
 *
 * @return {number} the baseline
 */
function readBaseline() {
	if (!fs.existsSync(BASELINE)) {
		console.error(
			'No baseline. Run `npm run check:schema-l10n -- --update` and commit it.',
		)
		process.exit(1)
	}
	return JSON.parse(fs.readFileSync(BASELINE, 'utf8')).uncovered
}

/**
 * Explain the strings added with no catalogue key, and exit 1.
 *
 * @param {number} added - how many more than the baseline
 */
function failOnGrowth(added) {
	console.error('')
	console.error(
		`${added} schema string(s) added with no catalogue key — they will render`,
	)
	console.error('in English inside an otherwise translated form.')
	console.error('')
	console.error(
		'Add them to l10n/en.json (identity) and l10n/nl.json (translated), then',
	)
	console.error('run `npm run l10n:build`. See what is uncovered with:')
	console.error('  node scripts/check-schema-l10n.js --list')
	process.exit(1)
}

/**
 * Compare the uncovered count with the baseline: fail when it grew, and say
 * how to lower the baseline when it shrank.
 *
 * @param {Map<string, string>} strings - every schema string
 * @param {string[]} uncovered - the uncovered strings
 */
function compareWithBaseline(strings, uncovered) {
	const baseline = readBaseline()

	console.log(
		`${strings.size} schema string(s); ${uncovered.length} uncovered, baseline ${baseline}`,
	)

	if (uncovered.length > baseline) {
		failOnGrowth(uncovered.length - baseline)
	}

	if (uncovered.length < baseline) {
		console.log(
			`${baseline - uncovered.length} fewer than the baseline — lower it with:`,
		)
		console.log('  npm run check:schema-l10n -- --update')
	}
}

/**
 * Count the uncovered schema strings, then list them, rewrite the baseline,
 * or hold the count to the baseline, as the arguments ask.
 */
function main() {
	const update = process.argv.includes('--update')
	const list = process.argv.includes('--list')

	const strings = schemaStrings()
	const covered = catalogueKeys()
	const uncovered = [...strings.keys()].filter((s) => !covered.has(s)).sort()

	if (list) {
		for (const s of uncovered) console.log(`${strings.get(s)}\n  ${s}`)
	}

	if (update) {
		writeBaseline(uncovered)
		return
	}

	compareWithBaseline(strings, uncovered)
}

main()
