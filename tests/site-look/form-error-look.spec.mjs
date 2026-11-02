#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// form-error-look.spec.mjs: the errors of a form as the FormulierFouten board
// draws them. The summary on the error tint with a thick bar at its start and
// an icon beside its heading; a field with an error gets a red bar along its
// start and its message in dark red with the same icon, so the error never
// rests on colour alone (WCAG 1.4.1).
//
// Usage:
//   node --test tests/site-look/form-error-look.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { renderSfc } from '../support/render-sfc.mjs'

/**
 * A component's style block, whitespace collapsed.
 *
 * @param {string} file The component.
 * @return {string} The CSS.
 */
function style(file) {
	const source = readFileSync(new URL(`../../${file}`, import.meta.url), 'utf8')
	return source.slice(source.indexOf('<style')).replace(/\s+/g, ' ')
}

test('the summary carries its icon and sits on the error tint with a thick start bar', async () => {
	const html = await renderSfc('src/site/components/forms/ErrorSummary.vue', {
		entries: [
			{ field: 'email', target: 'f-email', message: 'Vul een e-mailadres in' },
		],
		heading: 'Er is 1 fout',
	})
	assert.match(
		html,
		/data-testid="error-summary"><svg class="pq-error-icon"[^>]*aria-hidden="true"/,
	)
	const css = style('src/site/components/forms/ErrorSummary.vue')
	assert.match(
		css,
		/\.pq-error-summary \{[^}]*background: var\(\s*--nldesign-component-status-badge-error-background-color/,
	)
	assert.match(css, /\.pq-error-summary \{[^}]*border-inline-start-width: 6px/)
})

test('a field with an error shows its message with the icon, a field without none', async () => {
	const file = 'src/site/components/forms/FieldShell.vue'
	const wrong = await renderSfc(file, {
		id: 'f-email',
		label: 'E-mailadres',
		required: true,
		error: 'Vul een e-mailadres in met een @',
	})
	assert.match(
		wrong,
		/class="utrecht-form-field-error-message pq-field-shell__error"[^>]*><svg class="pq-error-icon"[^>]*aria-hidden="true"/,
	)
	const right = await renderSfc(file, {
		id: 'f-email',
		label: 'E-mailadres',
		required: true,
	})
	assert.doesNotMatch(right, /pq-error-icon/)
	const css = style(file)
	assert.match(
		css,
		/\.pq-field-shell\.utrecht-form-field--invalid \{[^}]*border-inline-start: 4px solid/,
	)
	assert.match(
		css,
		/\.pq-field-shell__error \{[^}]*color: var\(\s*--nldesign-color-accent-text/,
	)
})
