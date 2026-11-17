#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// site-contrast.spec.mjs: the contrast rule the rendered-page check
// (tests/site-surfaces.spec.mjs) runs in a browser (nldesign-theme-integration
// 3.3). Text is measured against the first ancestor that PAINTS a background,
// translucent text is composited first, and a scrim is not a surface.
//
// Usage:
//   node --test tests/site-contrast.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { auditContrast } from '../src/site/lib/contrast.js'

/**
 * A minimal element: what auditContrast reads and nothing more.
 *
 * @param {object} spec  `{bg, color, text, ariaHidden, width}`.
 * @param {Array} kids   Child elements.
 * @return {object} The element.
 */
function el(spec, kids = []) {
	const node = {
		style: {
			backgroundColor: spec.bg || 'rgba(0, 0, 0, 0)',
			color: spec.color || 'rgb(0, 0, 0)',
		},
		ariaHidden: spec.ariaHidden === true,
		parentElement: null,
		children: kids,
		textContent: spec.text ?? '',
		getBoundingClientRect: () => ({ width: spec.width ?? 100, height: 20 }),
		closest(selector) {
			assert.equal(selector, '[aria-hidden="true"]')
			for (let n = this; n; n = n.parentElement) {
				if (n.ariaHidden) {
					return n
				}
			}
			return null
		},
		querySelectorAll() {
			const out = []
			const walk = (n) =>
				n.children.forEach((c) => {
					out.push(c)
					walk(c)
				})
			walk(this)
			return out
		},
	}
	kids.forEach((k) => {
		k.parentElement = node
	})
	if (kids.length && !spec.text) {
		node.textContent = kids.map((k) => k.textContent).join(' ')
	}
	return node
}

/**
 * A view whose body is `body`.
 *
 * @param {object} body The root element.
 * @return {object} `{document, getComputedStyle}`.
 */
function view(body) {
	return { document: { body }, getComputedStyle: (n) => n.style }
}

test('text on a descendant surface is measured against THAT surface, not the band', () => {
	// White text on a dark card inside a white band: readable. Judged against
	// the band it would be a phantom 1.0 failure.
	const label = el({ text: 'Zoeken', color: 'rgb(255, 255, 255)' })
	const card = el({ bg: 'rgb(0, 51, 102)' }, [label])
	const band = el({ bg: 'rgb(255, 255, 255)' }, [card])
	const body = el({}, [band])

	const result = auditContrast(body, view(body))

	assert.equal(result.measured, 1)
	assert.deepEqual(result.failures, [])
})

test('the same text on the band itself fails, with the ratio and the surface named', () => {
	const label = el({ text: 'Zoeken', color: 'rgb(255, 255, 255)' })
	const band = el({ bg: 'rgb(255, 255, 255)' }, [label])
	const body = el({}, [band])

	const result = auditContrast(body, view(body))

	assert.equal(result.measured, 1)
	assert.equal(result.failures.length, 1)
	assert.equal(result.failures[0].ratio, 1)
	assert.equal(result.failures[0].on, 'rgb(255, 255, 255)')
	assert.equal(result.failures[0].text, 'Zoeken')
})

test('translucent text is composited over its backdrop before it is measured', () => {
	// White at 22% on navy is barely visible; read as opaque it scores ~14:1.
	const link = el({ text: 'Privacy', color: 'rgba(255, 255, 255, 0.22)' })
	const footer = el({ bg: 'rgb(1, 21, 72)' }, [link])
	const body = el({}, [footer])

	const result = auditContrast(body, view(body))

	assert.equal(result.failures.length, 1)
	assert.ok(result.failures[0].ratio < 4.5, `ratio ${result.failures[0].ratio}`)
})

test('a nearly transparent scrim is skipped for the surface under it', () => {
	const text = el({ text: 'Melding', color: 'rgb(255, 255, 255)' })
	const scrim = el({ bg: 'rgba(255, 255, 255, 0.08)' }, [text])
	const band = el({ bg: 'rgb(0, 0, 0)' }, [scrim])
	const body = el({}, [band])

	const result = auditContrast(body, view(body))

	assert.deepEqual(result.failures, [])
	assert.equal(result.measured, 1)
})

test('with nothing painted the page background is used, then white', () => {
	const dark = el({ text: 'Welkom', color: 'rgb(255, 255, 255)' })
	const darkBody = el({ bg: 'rgb(20, 20, 20)' }, [dark])
	assert.deepEqual(auditContrast(darkBody, view(darkBody)).failures, [])

	const light = el({ text: 'Welkom', color: 'rgb(255, 255, 255)' })
	const plainBody = el({}, [light])
	assert.equal(
		auditContrast(plainBody, view(plainBody)).failures[0].on,
		'rgb(255, 255, 255)',
	)
})

test('decoration, containers, empty and tiny elements are not measured', () => {
	const hidden = el({
		text: 'Icoon',
		color: 'rgb(255, 255, 255)',
		ariaHidden: true,
	})
	const tiny = el({ text: 'x', color: 'rgb(255, 255, 255)', width: 2 })
	const empty = el({ text: '   ', color: 'rgb(255, 255, 255)' })
	const inner = el({ text: 'Binnen', color: 'rgb(0, 0, 0)' })
	const container = el({}, [inner])
	const body = el({ bg: 'rgb(255, 255, 255)' }, [hidden, tiny, empty, container])

	const result = auditContrast(body, view(body))

	// Only `inner` counts: black on white, a pass.
	assert.equal(result.measured, 1)
	assert.deepEqual(result.failures, [])
})

test('the function survives being stringified, as the rendered-page check injects it', () => {
	const rebuilt = new Function(`return (${auditContrast.toString()})`)()
	const label = el({ text: 'Zoeken', color: 'rgb(255, 255, 255)' })
	const body = el({ bg: 'rgb(255, 255, 255)' }, [label])

	assert.equal(rebuilt(body, view(body)).failures.length, 1)
})
