#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// reason-dialogs.spec.mjs: the three dialogs that ask for a reason
// (RefuseAccessRequestDialog, RevealDecisionDialog, VoidAccountDialog). Each
// SFC's script is compiled with the component libraries stubbed, and its
// options are pinned: name, props, emits, initial state, and the confirm step
// that refuses a blank reason and closes with the trimmed one.
//
// Usage:
//   node --test tests/reason-dialogs.spec.mjs

import { compileScript, parse } from '@vue/compiler-sfc'
import assert from 'node:assert/strict'
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { dirname, join, resolve } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath, pathToFileURL } from 'node:url'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const OUT = join(ROOT, 'node_modules', '.cache', 'reason-dialogs', String(process.pid))

const STUBS = {
	'@conduction/nextcloud-vue': 'export const CnDialog = { name: "CnDialog" }',
	'@nextcloud/vue': 'export const NcButton = { name: "NcButton" }\nexport const NcTextArea = { name: "NcTextArea" }',
	'@nextcloud/l10n': 'export const translate = (app, text) => text',
}

/**
 * Compile an SFC's script into an importable module, with the component
 * libraries stubbed and relative imports resolved from the SFC.
 *
 * @param {string} rel The SFC, relative to the repository.
 * @return {Promise<object>} The component options.
 */
async function options(rel) {
	mkdirSync(OUT, { recursive: true })
	const file = join(ROOT, rel)
	const { descriptor } = parse(readFileSync(file, 'utf8'), { filename: file })
	let code = compileScript(descriptor, { id: 'x' }).content
	code = code.replace(/from\s+['"]([^'"]+)['"]/g, (match, spec) => {
		if (Object.hasOwn(STUBS, spec)) {
			const stub = join(OUT, `${spec.replace(/[^a-z]/gi, '_')}.mjs`)
			writeFileSync(stub, STUBS[spec])
			return `from '${pathToFileURL(stub).href}'`
		}
		if (spec.startsWith('.')) {
			return `from '${pathToFileURL(resolve(dirname(file), spec)).href}'`
		}
		return match
	})
	const out = join(OUT, `${rel.replace(/[^a-z]/gi, '_')}.mjs`)
	writeFileSync(out, code)
	return (await import(pathToFileURL(out).href)).default
}

/**
 * Run the confirm step on a given reason.
 *
 * @param {object} component The component options.
 * @param {string} reason The reason typed.
 * @return {object} The state afterwards and what was emitted.
 */
function confirmWith(component, reason) {
	const emitted = []
	const ctx = { ...component.data(), reason, $emit: (...args) => emitted.push(args) }
	component.methods.confirm.call(ctx)
	return { missing: ctx.missing, emitted }
}

const EXPECTED = {
	'src/dialogs/RefuseAccessRequestDialog.vue': {
		name: 'RefuseAccessRequestDialog',
		props: { look: { type: 'String', default: undefined } },
	},
	'src/dialogs/RevealDecisionDialog.vue': {
		name: 'RevealDecisionDialog',
		props: { look: { type: 'String', default: undefined }, allow: { type: 'Boolean', default: false } },
	},
	'src/dialogs/VoidAccountDialog.vue': {
		name: 'VoidAccountDialog',
		props: {
			look: { type: 'String', default: undefined },
			title: { type: 'String', default: '' },
			confirmLabel: { type: 'String', default: '' },
		},
	},
}

for (const [rel, expected] of Object.entries(EXPECTED)) {
	test(`${rel}: name, props, emits, components and initial state`, async () => {
		const component = await options(rel)
		assert.equal(component.name, expected.name)
		const props = Object.fromEntries(
			Object.entries(component.props).map(([key, prop]) => [key, { type: prop.type.name, default: prop.default }]),
		)
		assert.deepEqual(props, expected.props)
		assert.deepEqual(component.emits, ['close'])
		assert.deepEqual(Object.keys(component.components).sort(), ['CnDialog', 'NcButton', 'NcTextArea'])
		assert.deepEqual(component.data(), { reason: '', missing: false })
		assert.notStrictEqual(component.data(), component.data())
		assert.deepEqual(Object.keys(component.methods).sort(), ['confirm', 't'])
		assert.equal(component.methods.t('portaliq', 'Cancel'), 'Cancel')
	})

	test(`${rel}: a blank reason is refused, a reason closes trimmed`, async () => {
		const component = await options(rel)
		assert.deepEqual(confirmWith(component, ''), { missing: true, emitted: [] })
		assert.deepEqual(confirmWith(component, ' \n\t '), { missing: true, emitted: [] })
		assert.deepEqual(confirmWith(component, '  Omdat het moet.  '), {
			missing: false,
			emitted: [['close', 'Omdat het moet.']],
		})
	})
}
