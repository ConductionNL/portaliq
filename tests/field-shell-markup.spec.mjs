#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// field-shell-markup.spec.mjs: the markup a form field's shell (a label or a
// fieldset with its legend, the description, the error with its icon) and
// the error summary render, pinned in a golden file before the shell's two
// branches and the error icon were drawn once.
//
// Usage:
//   node --test tests/field-shell-markup.spec.mjs
//   GOLDEN_RECORD=1 node --test tests/field-shell-markup.spec.mjs

import { test } from 'node:test'
import { golden } from './support/golden.mjs'
import { renderSfc } from './support/render-sfc.mjs'

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

test('the field shell in every variant, and the error summary', async () => {
	const file = golden('field-shell-markup')
	for (const [n, props] of VARIANTS.entries()) {
		file.check(`shell ${n}`, await renderSfc(SHELL, props))
	}
	file.check('summary empty', await renderSfc('src/site/components/forms/ErrorSummary.vue', {}))
	file.check('summary', await renderSfc('src/site/components/forms/ErrorSummary.vue', {
		entries: [{ id: 'naam', message: 'Vul uw naam in' }, { id: 'datum', message: 'Vul een datum in' }],
		intro: 'Controleer:',
	}))
	file.save()
})
