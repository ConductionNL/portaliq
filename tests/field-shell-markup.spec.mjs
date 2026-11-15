#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// field-shell-markup.spec.mjs: the elements a form field's shell (a label or
// a fieldset with its legend, the description, the error with its icon) and
// the error summary mount, as the browser builds them, pinned in a golden
// file before the error icon was drawn by one component. Comment placeholders
// are left out: they are no part of the page.
//
// Usage:
//   node --test tests/field-shell-markup.spec.mjs
//   GOLDEN_RECORD=1 node --test tests/field-shell-markup.spec.mjs

import { test } from 'node:test'
import { golden } from './support/golden.mjs'
import { mountSfc } from './support/mount-sfc.mjs'

const SHELL = 'src/site/components/forms/FieldShell.vue'
const VARIANTS = [
	{ id: 'naam', label: 'Naam' },
	{ id: 'naam', label: 'Naam', required: true },
	{ id: 'naam', label: 'Naam', help: 'Zoals in uw paspoort', error: 'Vul uw naam in', errorTestid: 'naam-error' },
	{ id: 'naam', label: 'Naam', error: 'Vul uw naam in', optionalLabel: '(optional)' },
	{ id: 'datum', label: 'Datum', group: true },
	{ id: 'datum', label: 'Datum', group: true, required: true, help: 'Bijvoorbeeld 1 3 2026' },
	{ id: 'datum', label: 'Datum', group: true, help: 'Hulp', error: 'Fout', errorTestid: 'datum-error' },
	{ id: 'datum', label: 'Datum', group: true, error: 'Fout' },
]

/**
 * A mounted element tree as plain data: tag, attributes and children, text
 * trimmed, comments left out.
 *
 * @param {object} node A mounted node.
 * @return {object|string|null} The node.
 */
function tree(node) {
	if (node.comment !== undefined) {
		return null
	}
	if (node.text !== undefined) {
		return node.text.trim() === '' ? null : node.text.trim()
	}
	const props = Object.fromEntries(
		Object.entries(node.props || {}).filter(([, value]) => typeof value !== 'function'),
	)
	return { tag: node.tag, props, children: (node.children || []).map(tree).filter((child) => child !== null) }
}

test('the field shell in every variant, and the error summary', async () => {
	const file = golden('field-shell-markup')
	for (const [n, props] of VARIANTS.entries()) {
		const mounted = await mountSfc(SHELL, props)
		file.check(`shell ${n}`, tree(mounted.root))
	}
	const summary = await mountSfc('src/site/components/forms/ErrorSummary.vue', {
		entries: [{ id: 'naam', message: 'Vul uw naam in' }, { id: 'datum', message: 'Vul een datum in' }],
		intro: 'Controleer:',
	})
	file.check('summary', tree(summary.root))
	file.save()
})
