#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// l10n-scripts-golden.spec.mjs: a golden record of scripts/check-schema-l10n.js
// and scripts/build-l10n-js.js. Both scripts resolve the repository from their
// own location, so each scenario copies the script into a scratch repository
// (lib/Settings schema JSON, l10n/ catalogues, appinfo/info.xml), runs it
// there, and records exit code, stdout, stderr and every file under l10n/
// afterwards.
// PORTALIQ_GOLDEN_RECORD=1 rewrites the record.
//
// Usage:
//   node --test tests/l10n-scripts-golden.spec.mjs

import assert from 'node:assert/strict'
import { spawnSync } from 'node:child_process'
import {
	copyFileSync,
	existsSync,
	mkdirSync,
	mkdtempSync,
	readdirSync,
	readFileSync,
	rmSync,
	writeFileSync,
} from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const GOLDEN = join(ROOT, 'tests/fixtures/l10n-scripts-golden.json')

const SCHEMA = JSON.stringify({
	components: {
		schemas: {
			case: {
				title: 'Case',
				properties: {
					name: { title: 'Name', description: 'The name' },
					status: {
						title: 'Status',
						'x-enum-labels': { open: 'Open', closed: 'Closed', blank: ' ' },
					},
					tags: {
						type: 'array',
						items: { 'x-enum-labels': { a: 'Tag A', b: 42 } },
					},
					nothing: null,
					odd: { title: '', items: null, 'x-enum-labels': null },
					nested: {
						title: 'Nested',
						properties: { inner: { title: 'Inner', description: 'Name' } },
					},
				},
			},
			list: [{ title: 'In a list', properties: { x: { title: 'X' } } }],
			noProps: { title: 'Never counted', properties: ['not', 'an', 'object'] },
		},
	},
})

const CATALOGUE = JSON.stringify({
	translations: { Case: 'Case', Name: 'Name', Open: 'Open' },
})

const SCHEMA_REPO = {
	'lib/Settings/register.json': SCHEMA,
	'lib/Settings/deeper/other.json': JSON.stringify({ title: 'T', properties: { y: { title: 'Name' } } }),
	'lib/Settings/broken.json': '{ not json',
	'lib/Settings/readme.txt': 'ignored',
	'l10n/en.json': CATALOGUE,
}

const SCHEMA_SCENARIOS = {
	'no baseline': { files: SCHEMA_REPO, args: [] },
	'at the baseline': {
		files: { ...SCHEMA_REPO, 'l10n/.schema-l10n-baseline.json': '{"uncovered": 12}\n' },
		args: [],
	},
	'below the baseline': {
		files: { ...SCHEMA_REPO, 'l10n/.schema-l10n-baseline.json': '{"uncovered": 20}\n' },
		args: [],
	},
	'above the baseline': {
		files: { ...SCHEMA_REPO, 'l10n/.schema-l10n-baseline.json': '{"uncovered": 3}\n' },
		args: [],
	},
	'list and update': { files: SCHEMA_REPO, args: ['--list', '--update'] },
	'list against a baseline': {
		files: { ...SCHEMA_REPO, 'l10n/.schema-l10n-baseline.json': '{"uncovered": 12}\n' },
		args: ['--list'],
	},
	'no catalogue and no schema dir': {
		files: { 'l10n/.schema-l10n-baseline.json': '{"uncovered": 0}\n' },
		args: [],
	},
	'catalogue without translations': {
		files: { ...SCHEMA_REPO, 'l10n/en.json': '{}', 'l10n/.schema-l10n-baseline.json': '{"uncovered": 1}\n' },
		args: [],
	},
}

const INFO = '<?xml version="1.0"?>\n<info>\n\t<id> demoapp </id>\n</info>\n'

const BUILD_REPO = {
	'appinfo/info.xml': INFO,
	'l10n/en.json': JSON.stringify({ translations: { A: 'A', 'Say "hi"': 'Say "hi"' } }),
	'l10n/nl.json': JSON.stringify({
		translations: { A: 'Aa' },
		pluralForm: 'nplurals=2; plural=(n > 1);',
	}),
	'l10n/.schema-l10n-baseline.json': '{"uncovered": 1}\n',
	'l10n/readme.md': 'ignored',
}

const CURRENT_EN =
	'OC.L10N.register(\n    "demoapp",\n    {\n        "A": "A",\n        "Say \\"hi\\"": "Say \\"hi\\""\n    },\n    "nplurals=2; plural=(n != 1);"\n)\n'

const BUILD_SCENARIOS = {
	'write every locale': { files: BUILD_REPO, args: [] },
	'one current, one missing': {
		files: { ...BUILD_REPO, 'l10n/en.js': CURRENT_EN },
		args: [],
	},
	'check finds stale': {
		files: { ...BUILD_REPO, 'l10n/en.js': CURRENT_EN, 'l10n/nl.js': 'old' },
		args: ['--check'],
	},
	'check passes': {
		files: { ...BUILD_REPO, 'l10n/nl.json': BUILD_REPO['l10n/en.json'], 'l10n/en.js': CURRENT_EN, 'l10n/nl.js': CURRENT_EN },
		args: ['--check'],
	},
	'empty translations': {
		files: { ...BUILD_REPO, 'l10n/nl.json': '{"translations": {}}' },
		args: [],
	},
	'catalogue does not parse': {
		files: { ...BUILD_REPO, 'l10n/de.json': '{ nope' },
		args: [],
	},
	'catalogue without translations': {
		files: { ...BUILD_REPO, 'l10n/zz.json': '{"pluralForm": "x"}' },
		args: [],
	},
	'no catalogues': {
		files: { 'appinfo/info.xml': INFO, 'l10n/.schema-l10n-baseline.json': '{}' },
		args: [],
	},
	'no app id': {
		files: { ...BUILD_REPO, 'appinfo/info.xml': '<info></info>' },
		args: [],
	},
}

/**
 * Every file under l10n/ after a run, by name.
 *
 * @param {string} root The scratch repository.
 * @return {object} File name to content.
 */
function l10nFiles(root) {
	const dir = join(root, 'l10n')
	if (existsSync(dir) === false) {
		return {}
	}
	const out = {}
	for (const name of readdirSync(dir).sort()) {
		out[name] = readFileSync(join(dir, name), 'utf8')
	}
	return out
}

/**
 * Copy one script into a scratch repository with the scenario's files and run it.
 *
 * @param {string} script The script path relative to the repository.
 * @param {object} scenario The files and arguments.
 * @return {object} Exit code, output and the l10n/ files afterwards.
 */
function runScenario(script, scenario) {
	const root = mkdtempSync(join(tmpdir(), 'pq-l10n-scripts-'))
	try {
		for (const [file, content] of Object.entries(scenario.files)) {
			mkdirSync(dirname(join(root, file)), { recursive: true })
			writeFileSync(join(root, file), content)
		}
		mkdirSync(join(root, 'scripts'), { recursive: true })
		copyFileSync(join(ROOT, script), join(root, script))
		const result = spawnSync(process.execPath, [join(root, script), ...scenario.args], {
			encoding: 'utf8',
			cwd: root,
		})
		return {
			status: result.status,
			stdout: result.stdout,
			// The parse error's wording is the JavaScript engine's, not the script's.
			stderr: result.stderr.replace(/(does not parse: ).*/, '$1<parse error>'),
			l10n: l10nFiles(root),
		}
	} finally {
		rmSync(root, { recursive: true, force: true })
	}
}

/**
 * Run every scenario of one script.
 *
 * @param {string} script The script path relative to the repository.
 * @param {object} scenarios Name to scenario.
 * @return {object} Name to recorded result.
 */
function record(script, scenarios) {
	const out = {}
	for (const [name, scenario] of Object.entries(scenarios)) {
		out[name] = runScenario(script, scenario)
	}
	return out
}

test('the l10n scripts match the golden record', () => {
	const recorded = {
		'check-schema-l10n': record('scripts/check-schema-l10n.js', SCHEMA_SCENARIOS),
		'build-l10n-js': record('scripts/build-l10n-js.js', BUILD_SCENARIOS),
	}
	if (process.env.PORTALIQ_GOLDEN_RECORD === '1') {
		writeFileSync(GOLDEN, JSON.stringify(recorded, null, '\t') + '\n')
	}
	assert.deepEqual(recorded, JSON.parse(readFileSync(GOLDEN, 'utf8')))
})
