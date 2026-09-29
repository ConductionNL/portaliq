#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// portal-theme-choice.spec.mjs: the Theme widget on a portal's page
// (nldesign-theme-integration 1.4, 3.1, 3.2). It lists the house styles the
// portal can wear with their contrast verdict, saves a choice, asks again with
// the findings when a set is hard to read, and never shows an unmeasured set
// as readable. It also pins that the widget is placed on the portal page.
//
// Usage:
//   node --test tests/portal-theme-choice.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import {
	createPortalThemeChoice,
	isSelectable,
	verdictState,
} from '../src/lib/portalThemeChoice.js'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')

/**
 * The choice over a recording transport.
 *
 * @param {Function} answer (method, url, body) => { status, data }
 * @return {{api: object, calls: Array}}
 */
function build(answer) {
	const calls = []
	const request = async (method, url, body) => {
		calls.push({ method, url, body })
		const reply = answer(method, url, body)
		if (reply.status >= 400) {
			const error = new Error('HTTP ' + reply.status)
			error.response = reply
			throw error
		}
		return reply
	}
	const api = createPortalThemeChoice({
		get: (url) => request('GET', url),
		put: (url, body) => request('PUT', url, body),
		url: (path, params) =>
			'/apps/portaliq' + path.replace(/\{(\w+)\}/g, (m, key) => params[key]),
	})
	return { api, calls }
}

test('the sets are read for the portal on the page', async () => {
	const sets = [
		{
			id: 'vng',
			name: 'VNG',
			verdict: { evaluated: true, measured: 2, passes: true, findings: [] },
		},
	]
	const { api, calls } = build(() => ({
		status: 200,
		data: { current: 'vng', currentResolves: true, sets },
	}))
	const result = await api.load('gemeente')

	assert.equal(calls[0].url, '/apps/portaliq/api/portals/gemeente/theme')
	assert.equal(result.state, 'ready')
	assert.equal(result.current, 'vng')
	assert.deepEqual(result.sets, sets)
})

test('a verdict that measured nothing is not checked, never readable', () => {
	assert.equal(
		verdictState({ evaluated: true, measured: 2, passes: true }),
		'passes',
	)
	assert.equal(
		verdictState({ evaluated: true, measured: 2, passes: false }),
		'fails',
	)
	assert.equal(
		verdictState({ evaluated: false, measured: 0, passes: false }),
		'unchecked',
	)
	assert.equal(
		verdictState({ evaluated: true, measured: 0, passes: true }),
		'unchecked',
	)
	assert.equal(verdictState(undefined), 'unchecked')
})

test('saving sends the set and whether the administrator confirmed', async () => {
	const { api, calls } = build(() => ({ status: 200, data: { current: 'vng' } }))
	const result = await api.save('gemeente', 'vng')

	assert.equal(result.outcome, 'saved')
	assert.equal(calls[0].method, 'PUT')
	assert.deepEqual(calls[0].body, { theme: 'vng', acceptFindings: false })
})

test('a hard-to-read set comes back with its findings, and is saved once confirmed', async () => {
	const findings = [
		{
			surface: 'footer',
			token: '--nldesign-footer-legal-color',
			ratio: 1.06,
			threshold: 4.5,
		},
	]
	const { api, calls } = build((method, url, body) =>
		body.acceptFindings
			? { status: 200, data: { current: 'faint' } }
			: { status: 422, data: { error: 'contrast', verdict: { findings } } },
	)

	const first = await api.save('gemeente', 'faint')
	assert.equal(first.outcome, 'contrast')
	assert.deepEqual(first.verdict.findings, findings)

	const second = await api.save('gemeente', 'faint', true)
	assert.equal(second.outcome, 'saved')
	assert.deepEqual(calls[1].body, { theme: 'faint', acceptFindings: true })
})

test('a set the theme app no longer offers, and a failed save, are told apart', async () => {
	assert.equal(
		(
			await build(() => ({
				status: 422,
				data: { error: 'unknown_theme' },
			})).api.save('g', 'x')
		).outcome,
		'unknown',
	)
	assert.equal(
		(
			await build(() => ({
				status: 502,
				data: { error: 'save_failed' },
			})).api.save('g', 'x')
		).outcome,
		'failed',
	)
})

test('a refused house style says why, and cannot be picked', async () => {
	const refusal =
		'Property --nldesign-color-text contains a forbidden value (external resource, @import, expression, or markup).'
	const result = await build(() => ({
		status: 422,
		data: { error: 'refused', refusal },
	})).api.save('g', 'custom-gedeeld')
	assert.deepEqual(result, { outcome: 'refused', refusal })

	assert.equal(isSelectable({ id: 'custom-gedeeld', refusal }), false)
	assert.equal(isSelectable({ id: 'custom-noord', custom: true }), true)
})

test('the Theme widget sits on the portal page', () => {
	const manifest = JSON.parse(
		readFileSync(join(ROOT, 'src', 'manifest.json'), 'utf8'),
	)
	const page = manifest.pages.find((candidate) => candidate.id === 'PortalDetail')
	const widget = page.config.widgets.find(
		(candidate) => candidate.type === 'PortalTheme',
	)

	assert.ok(widget, 'PortalDetail declares a PortalTheme widget')
	assert.ok(
		page.config.layout.some((cell) => cell.widgetId === widget.id),
		'and places it in the layout',
	)
	assert.match(
		readFileSync(join(ROOT, 'src', 'registry.js'), 'utf8'),
		/PortalTheme: \{/,
	)
})
