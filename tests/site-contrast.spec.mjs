// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
//
// site-contrast.spec.mjs — the one contrast rule the surface check and an
// editor share (portal-theme-blocks-and-contributed-pages REQ-PTB-013),
// measured over a minimal stand-in DOM: what is painted behind the text,
// translucent text composited first, and 3:1 for large text.
//
// Usage: node --test tests/site-contrast.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { auditContrast } from '../src/site/lib/contrast.js'

/**
 * A stand-in element with the computed style the audit reads.
 *
 * @param {object} style    Its computed colour, background and font.
 * @param {string} text     Its text; children make it a container.
 * @param {Array}  children Child elements.
 * @return {object} The element.
 */
function el(style, text = '', children = []) {
	const node = {
		style: {
			color: 'rgb(0, 0, 0)',
			backgroundColor: 'rgba(0, 0, 0, 0)',
			fontSize: '16px',
			fontWeight: '400',
			...style,
		},
		children,
		parentElement: null,
		hidden: false,
		get textContent() {
			return text || children.map((c) => c.textContent).join('')
		},
		closest(selector) {
			for (let n = this; n; n = n.parentElement) {
				if (selector === '[aria-hidden="true"]' && n.hidden) {
					return n
				}
			}
			return null
		},
		getBoundingClientRect() {
			return { width: 100, height: 20 }
		},
		querySelectorAll() {
			const all = []
			const walk = (n) => n.children.forEach((c) => {
				all.push(c)
				walk(c)
			})
			walk(this)
			return all
		},
	}
	children.forEach((c) => {
		c.parentElement = node
	})
	return node
}

globalThis.getComputedStyle = (node) => node.style

test('dark text on a white page passes', () => {
	const page = el({ backgroundColor: 'rgb(255, 255, 255)' }, '', [el({ color: 'rgb(33, 33, 33)' }, 'Afval')])
	const { failures, measured } = auditContrast(page)
	assert.equal(measured, 1)
	assert.deepEqual(failures, [])
})

test('light grey body text on white fails AA and names the text', () => {
	const page = el({ backgroundColor: 'rgb(255, 255, 255)' }, '', [el({ color: 'rgb(170, 170, 170)' }, 'Openingstijden')])
	const { failures } = auditContrast(page)
	assert.equal(failures.length, 1)
	assert.equal(failures[0].text, 'Openingstijden')
	assert.equal(failures[0].required, 4.5)
})

test('translucent white on navy is composited before it is measured', () => {
	// Read as opaque white this scores far above 4.5:1; composited it fails.
	const band = el({ backgroundColor: 'rgb(21, 66, 115)' }, '', [el({ color: 'rgba(255, 255, 255, 0.22)' }, 'Privacy')])
	const { failures } = auditContrast(el({}, '', [band]))
	assert.equal(failures.length, 1)
	assert.ok(failures[0].ratio < 2)
})

test('the backdrop is the nearest ancestor that paints, skipping a faint scrim', () => {
	const scrim = el({ backgroundColor: 'rgba(255, 255, 255, 0.08)' }, '', [el({ color: 'rgb(255, 255, 255)' }, 'Contact')])
	const band = el({ backgroundColor: 'rgb(21, 66, 115)' }, '', [scrim])
	const { failures, measured } = auditContrast(el({ backgroundColor: 'rgb(255, 255, 255)' }, '', [band]))
	assert.equal(measured, 1)
	assert.deepEqual(failures, [])
})

test('large text is held to 3:1, body text of the same colour to 4.5:1', () => {
	// #888 on white is about 3.5:1.
	const grey = 'rgb(136, 136, 136)'
	const page = el({ backgroundColor: 'rgb(255, 255, 255)' }, '', [
		el({ color: grey, fontSize: '32px' }, 'Kop'),
		el({ color: grey, fontSize: '19px', fontWeight: '700' }, 'Vet'),
		el({ color: grey, fontSize: '19px', fontWeight: '400' }, 'Gewoon'),
	])
	const { failures, measured } = auditContrast(page)
	assert.equal(measured, 3)
	assert.deepEqual(failures.map((f) => f.text), ['Gewoon'])
})

test('decoration and containers are not measured', () => {
	const hidden = el({ color: 'rgb(250, 250, 250)' }, 'x-icon')
	hidden.hidden = true
	const page = el({ backgroundColor: 'rgb(255, 255, 255)' }, '', [
		hidden,
		el({ color: 'rgb(250, 250, 250)' }, '', [el({ color: 'rgb(0, 0, 0)' }, 'Binnen')]),
	])
	const { failures, measured } = auditContrast(page)
	assert.equal(measured, 1)
	assert.deepEqual(failures, [])
})

test('the audit carries no outside reference, so it can be injected as source', () => {
	const injected = new Function(`return (${auditContrast.toString()})`)()
	const page = el({ backgroundColor: 'rgb(255, 255, 255)' }, '', [el({ color: 'rgb(170, 170, 170)' }, 'Los')])
	assert.equal(injected(page).failures.length, 1)
})
