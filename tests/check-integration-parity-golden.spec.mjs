#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// check-integration-parity-golden.spec.mjs: a golden record of
// scripts/check-integration-parity.js. Each scenario writes a small repository
// (lib/ PHP faces, src/ registrations, lib/Settings schema JSON) to a scratch
// directory, runs the script against it, and records exit code, stdout and
// stderr.
// PORTALIQ_GOLDEN_RECORD=1 rewrites the record.
//
// Usage:
//   node --test tests/check-integration-parity-golden.spec.mjs

import assert from 'node:assert/strict'
import { spawnSync } from 'node:child_process'
import { mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const SCRIPT = join(ROOT, 'scripts/check-integration-parity.js')
const GOLDEN = join(ROOT, 'tests/fixtures/check-integration-parity-golden.json')

/**
 * A PHP class returning one `new LeafDescriptor(...)` with the given arguments.
 *
 * @param {string} name The class name.
 * @param {string} args The named arguments.
 * @return {string} The source.
 */
function leaf(name, args) {
	return `<?php\nclass ${name} {\n\tconst LABEL = 'Const label';\n\tpublic function leaf() {\n\t\treturn new LeafDescriptor(${args});\n\t}\n}\n`
}

/**
 * A PHP integration provider with the given getters.
 *
 * @param {string} name The class name.
 * @param {object} getters Method name to returned PHP expression.
 * @return {string} The source.
 */
function provider(name, getters) {
	const methods = Object.entries(getters)
		.map(
			([method, expr]) =>
				`\tpublic function ${method}(): string {\n\t\treturn ${expr};\n\t}\n`,
		)
		.join('')
	return `<?php\nclass ${name} extends AbstractIntegrationProvider {\n${methods}}\n`
}

const SCHEMAS = JSON.stringify({
	components: {
		schemas: {
			plannedItem: {
				slug: 'planned-item',
				properties: { title: {}, date: {}, assignee: {} },
			},
			reference: { properties: { name: {} } },
		},
	},
})

const PASSING = {
	'lib/Leaf/MountLeaf.php': leaf(
		'MountLeaf',
		"id: 'leaf-mount', kinds: [LeafDescriptor::KIND_RENDER_SURFACE], renderMode: LeafDescriptor::RENDER_MODE_MOUNT, label: self::LABEL, icon: 'icon-a', group: 'grp', surfaces: ['tab', 'widget']",
	),
	'lib/Leaf/DataLeaf.php': leaf(
		'DataLeaf',
		"id: 'leaf-data', kinds: ['data-provider']",
	),
	'lib/Leaf/Provider.php': provider('Provider', {
		getId: "'prov-b'",
		getLabel: "$this->l10n->t('Provider label')",
		getIcon: "'icon-b'",
		getGroup: 'MountLeaf::LABEL',
	}),
	'lib/Settings/register.json': SCHEMAS,
	'src/leaves/mount.js': `import { mount, unmount } from './impl.js'\nregisterIntegration({ id: 'leaf-mount', renderMode: 'mount', mount, unmount, label: 'Const label', icon: 'icon-a', group: 'grp', surfaces: ['widget', 'tab'] })\n`,
	'src/leaves/provider.js': `const DESCRIPTOR = { id: 'prov-b', tab: Tab, widget: Widget, label: 'Provider label', offlineConfig: { plannedSchema: 'planned-item', referenceSchema: 'reference', titleField: 'title', dateField: 'date' } }\nOCA.OpenRegister.integrations.register(DESCRIPTOR)\n`,
	'src/leaves/owned.js': `import { fooIntegration } from '@conduction/nextcloud-vue'\nregisterIntegration({ ...fooIntegration, tab: T })\n`,
	'src/leaves/library.js':
		'export function registerIntegration(descriptor) {\n\treturn descriptor\n}\n',
}

const SCENARIOS = {
	'every rule passes': PASSING,
	'R1 incomplete render pairs': {
		...PASSING,
		'src/leaves/mount.js':
			"registerIntegration({ id: 'leaf-mount', renderMode: 'mount', mount, label: 'Const label' })\n",
		'src/leaves/component.js':
			"registerIntegration({ id: 'leaf-comp', tab: T })\nregisterIntegration({ id: 'leaf-comp2', renderMode: 'component', widget: W })\n",
		'lib/Leaf/Comp.php':
			leaf('Comp', "id: 'leaf-comp', kinds: ['render-surface']")
			+ leaf('Comp2', "id: 'leaf-comp2', kinds: ['render-surface']").replace(
				'<?php\n',
				'',
			),
	},
	'R2 correlation failures': {
		...PASSING,
		'lib/Leaf/NoId.php': leaf(
			'NoId',
			"id: $dynamic, kinds: [], label: 'Server label'",
		),
		'lib/Leaf/Phantom.php': leaf(
			'Phantom',
			"id: 'leaf-phantom', kinds: ['render-surface']",
		),
		'lib/Leaf/Lonely.php': provider('Lonely', { getId: "'prov-lonely'" }),
		'src/leaves/spread-label.js':
			"import { barIntegration } from '@conduction/nextcloud-vue'\nregisterIntegration({ ...barIntegration, label: 'Client label', tab: T, widget: W })\n",
		'src/leaves/orphan.js':
			"registerIntegration({ id: 'leaf-orphan', tab: T, widget: W })\n",
	},
	'R3 renderMode mismatch': {
		...PASSING,
		'lib/Leaf/Implicit.php': leaf(
			'Implicit',
			"id: 'leaf-implicit', kinds: ['render-surface'], renderMode: 'mount'",
		),
		'src/leaves/implicit.js':
			"registerIntegration({ id: 'leaf-implicit', tab: T, widget: W })\n",
		'src/leaves/mount.js':
			"registerIntegration({ id: 'leaf-mount', renderMode: 'component', tab: T, widget: W })\n",
	},
	'R4 metadata mismatch': {
		...PASSING,
		'src/leaves/mount.js':
			"registerIntegration({ id: 'leaf-mount', renderMode: 'mount', mount, unmount, label: 'Other', icon: 'icon-a', group: 'other', surfaces: ['tab'] })\n",
	},
	'R5 spread sources': {
		...PASSING,
		'src/leaves/spreads.js':
			"import { localIntegration } from './local.js'\nregisterIntegration({ ...missingIntegration, tab: T })\nregisterIntegration({ ...localIntegration })\nregisterIntegration({ tab: T, widget: W })\n",
	},
	'R6 offlineConfig failures': {
		...PASSING,
		'src/leaves/provider.js':
			"registerIntegration({ id: 'prov-b', tab: T, widget: W, label: 'Provider label', offlineConfig: { plannedSchema: 'planned-item', resultSchema: 'nope', titleField: 'title', assigneeField: 'missing', templateRefField: 'x' } })\n",
		'src/leaves/second.js':
			"registerIntegration({ id: 'leaf-mount', renderMode: 'mount', mount, unmount, offlineConfig: { plannedSchema: 'absent', dateField: 'date' } })\n",
	},
	'R6 without schema files': Object.fromEntries(
		Object.entries(PASSING).filter(
			([file]) => file.startsWith('lib/Settings/') === false,
		),
	),
	'nothing to check': {
		'lib/Empty.php': '<?php\nclass Empty {}\n',
		'src/empty.js': 'export default {}\n',
	},
}

/**
 * Run the script once against a repository.
 *
 * @param {string} root The repository root.
 * @return {object} Exit code and output.
 */
function runOn(root) {
	const result = spawnSync(process.execPath, [SCRIPT, root], { encoding: 'utf8' })
	return { status: result.status, stdout: result.stdout, stderr: result.stderr }
}

/**
 * Write a scenario's files to a scratch root and run the script there.
 *
 * @param {object} files Relative path to content.
 * @return {object} Exit code and output.
 */
function runScenario(files) {
	const root = mkdtempSync(join(tmpdir(), 'pq-parity-'))
	try {
		for (const [file, content] of Object.entries(files)) {
			mkdirSync(dirname(join(root, file)), { recursive: true })
			writeFileSync(join(root, file), content)
		}
		return runOn(root)
	} finally {
		rmSync(root, { recursive: true, force: true })
	}
}

test('check-integration-parity matches the golden record', () => {
	const recorded = {}
	for (const [name, files] of Object.entries(SCENARIOS)) {
		recorded[name] = runScenario(files)
	}
	if (process.env.PORTALIQ_GOLDEN_RECORD === '1') {
		writeFileSync(GOLDEN, JSON.stringify(recorded, null, '\t') + '\n')
	}
	assert.deepEqual(recorded, JSON.parse(readFileSync(GOLDEN, 'utf8')))
})
