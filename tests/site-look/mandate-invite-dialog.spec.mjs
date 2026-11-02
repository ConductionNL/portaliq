#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// mandate-invite-dialog.spec.mjs: "Iemand machtigen" as the Machtigen board
// draws it: 640px wide, no frame, 12px corners and a shadow on a dimmed page;
// a head with the eyebrow "Toegang tot zaken", the title and a close button;
// the fields 20px apart at 28px inside, labels and legends at 600, 22px
// choices, the e-mail field full width; "Annuleren" and "Uitnodiging sturen"
// 12px apart at the end of a footer band under a hairline.
//
// Usage:
//   node --test tests/site-look/mandate-invite-dialog.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

const FILE = 'src/site/modals/e/MandateInviteModal.vue'

/**
 * The rule body for one selector in the dialog's style.
 *
 * @param {string} selector The exact selector.
 * @return {string} The declarations, whitespace collapsed.
 */
function rule(selector) {
	const source = readFileSync(new URL(`../../${FILE}`, import.meta.url), 'utf8')
	const css = source
		.slice(source.indexOf('<style'))
		.replace(/\/\*[\s\S]*?\*\//g, ' ')
		.replace(/\s+/g, ' ')
	const escaped = selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
	const match = css.match(new RegExp(`(?:^|[}>]) ?${escaped} \\{([^}]*)\\}`))
	return match ? match[1] : ''
}

const source = readFileSync(new URL(`../../${FILE}`, import.meta.url), 'utf8')
const template = source.slice(0, source.indexOf('<script'))

test('the dialog is 640px wide, frameless, 12px corners, with a shadow', () => {
	const body = rule('.pq-mandate-invite')
	assert.match(body, /inline-size: min\(40rem, calc\(100vw - 2rem\)\)/)
	assert.match(body, /border: 0;/)
	assert.match(body, /border-radius: 0\.75rem/)
	assert.match(body, /box-shadow: 0 12px 40px rgb\(0 0 0 \/ 25%\)/)
	assert.match(body, /padding: 0;/)
	assert.match(rule('.pq-mandate-invite::backdrop'), /background:/)
})

test('the head carries the eyebrow, the title and a close button', () => {
	const head = template.slice(
		template.indexOf('pq-mandate-invite__head'),
		template.indexOf('pq-mandate-invite__body'),
	)
	assert.ok(
		head.indexOf("t('Access to cases')") < head.indexOf("t('Authorise someone')"),
		'the eyebrow stands above the title',
	)
	assert.match(head, /class="pq-mandate-invite__eyebrow"/)
	assert.match(
		head,
		/<button\s+type="button"\s+class="pq-mandate-invite__close"\s+:aria-label="t\('Close'\)"\s+data-testid="mandate-invite-close"\s+@click="\$emit\('cancel'\)">/,
	)
	assert.match(rule('.pq-mandate-invite__head'), /padding: 22px 28px 0;/)
	assert.match(rule('.pq-mandate-invite__eyebrow'), /font-size: 0\.9375rem; font-weight: 600;/)
	assert.match(rule('.pq-mandate-invite__title'), /font-size: 1\.5rem;/)
	assert.match(rule('.pq-mandate-invite__close'), /block-size: 44px; inline-size: 44px;/)
})

test('the fields stand 20px apart, 28px inside, the e-mail field full width', () => {
	assert.match(
		rule('.pq-mandate-invite__body'),
		/display: flex; flex-direction: column; gap: 20px; padding: 16px 28px 24px;/,
	)
	assert.match(rule('.pq-mandate-invite__email'), /inline-size: 100%; max-inline-size: none;/)
})

test('labels and legends read 17px at 600; choices are 22px', () => {
	assert.match(
		rule('.pq-mandate-invite__label'),
		/font-size: 1\.0625rem; font-weight: 600;/,
	)
	assert.match(rule('.pq-mandate-invite__choice input'), /block-size: 22px; inline-size: 22px;/)
	assert.match(rule('.pq-mandate-invite__choice'), /gap: 10px; min-block-size: 32px;/)
	assert.match(rule('.pq-mandate-invite__types'), /gap: 2px; padding: 6px 0 0 32px;/)
	assert.equal(
		(template.match(/class="[^"]*pq-mandate-invite__label[^"]*"/g) || []).length,
		3,
		'the e-mail label and both legends',
	)
})

test('the actions stand 12px apart at the end of a footer band', () => {
	const body = rule('.pq-mandate-invite__buttons')
	assert.match(body, /gap: 0\.75rem/)
	assert.match(body, /justify-content: flex-end/)
	assert.match(body, /padding: 18px 28px;/)
	assert.match(body, /border-block-start: 1px solid/)
})
