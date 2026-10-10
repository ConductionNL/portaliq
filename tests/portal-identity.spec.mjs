#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// portal-identity.spec.mjs: the portal's favicon, logo, hero image and kind
// of organisation, picked in the admin (portal-identity-from-the-admin
// REQ-PIA-001, REQ-PIA-003).
//
// Usage:
//   node --test tests/portal-identity.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import {
	choiceOf,
	createPortalIdentity,
	portalWithIdentity,
} from '../src/lib/portalIdentity.js'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')

const TYPES = {
	installed: true,
	options: [
		{ uri: 'https://tooi.example/gemeente', label: 'gemeente' },
		{ uri: 'https://tooi.example/waterschap', label: 'waterschap' },
	],
}

const PORTAL = { id: 'p1', slug: 'ws', title: 'Waterschap', logo: 'https://cdn.example/logo.svg' }

test('an administrator sets the three images and the kind of organisation', () => {
	const written = portalWithIdentity(
		PORTAL,
		{ favicon: 'm1', logo: 'm2', heroImage: 'm3', organisationType: 'https://tooi.example/waterschap' },
		TYPES,
	)

	assert.equal(written.favicon, 'media:m1')
	assert.equal(written.logo, 'media:m2')
	assert.equal(written.heroImage, 'media:m3')
	assert.equal(written.organisationType, 'https://tooi.example/waterschap')
	assert.equal(written.organisationTypeLabel, 'waterschap')
	assert.deepEqual(choiceOf(written), {
		favicon: 'm1',
		logo: 'm2',
		heroImage: 'm3',
		organisationType: 'https://tooi.example/waterschap',
	})
})

test('no free text is stored as a kind, and a missing list keeps what is stored', () => {
	const free = portalWithIdentity(
		{ ...PORTAL, organisationType: 'x', organisationTypeLabel: 'x' },
		{ organisationType: 'Gemeente Zuiddrecht' },
		TYPES,
	)
	assert.equal('organisationType' in free, false)
	assert.equal('organisationTypeLabel' in free, false)

	const kept = portalWithIdentity(
		{ ...PORTAL, organisationType: 'https://tooi.example/gemeente', organisationTypeLabel: 'gemeente' },
		{ organisationType: '' },
		{ installed: false, options: [] },
	)
	assert.equal(kept.organisationTypeLabel, 'gemeente')
	// A logo given as a web address stays when no image is picked.
	assert.equal(kept.logo, 'https://cdn.example/logo.svg')
})

test("the save reads the portal, writes it whole and shows the guard's refusal", async () => {
	const calls = []
	const api = createPortalIdentity({
		get: async (address) => {
			calls.push(['get', address])
			if (address.includes('organisation-types')) {
				return { data: TYPES }
			}
			if (address.includes('/media?')) {
				return {
					data: {
						results: [
							{ id: 'm1', portal: 'ws', status: 'published', kind: 'image', title: 'Icoon' },
							{ id: 'f1', portal: 'ws', status: 'published', kind: 'file', title: 'PDF' },
						],
					},
				}
			}
			return { data: { ...PORTAL } }
		},
		put: async (address, body) => {
			calls.push(['put', address, body])
			if (body.favicon === 'media:jpg') {
				const error = new Error('refused')
				error.response = { status: 400, data: { message: 'A favicon must be a PNG, SVG or ICO file.' } }
				throw error
			}
		},
		url: (path) => '/apps/portaliq' + path,
		ocUrl: (path, params) => path.replace('{id}', params?.id || ''),
		translate: (key) => key,
	})

	assert.deepEqual((await api.images('ws')).items.map((item) => item.id), ['m1'])
	assert.equal((await api.types()).installed, true)

	const ok = await api.save('p1', { favicon: 'm1' }, TYPES)
	assert.deepEqual(ok, { ok: true, message: 'Your choices are saved.' })
	const put = calls.find((call) => call[0] === 'put')
	assert.equal(put[1], '/apps/openregister/api/objects/portaliq/portal/p1')
	assert.equal(put[2].favicon, 'media:m1')
	assert.equal(put[2].title, 'Waterschap')

	const refused = await api.save('p1', { favicon: 'jpg' }, TYPES)
	assert.deepEqual(refused, { ok: false, message: 'A favicon must be a PNG, SVG or ICO file.' })
})

test('without the TOOI list the picker hears it is not installed', async () => {
	const api = createPortalIdentity({
		get: async () => {
			throw new Error('down')
		},
		put: async () => {},
		url: (path) => path,
		ocUrl: (path) => path,
		translate: (key) => key,
	})
	assert.deepEqual(await api.types(), { installed: false, options: [] })
})

test('the identity widget sits on the portal page and is registered', () => {
	const manifest = JSON.parse(readFileSync(join(ROOT, 'src', 'manifest.json'), 'utf8'))
	const page = manifest.pages.find((candidate) => candidate.id === 'PortalDetail')
	const widget = page.config.widgets.find((candidate) => candidate.type === 'PortalIdentity')
	assert.ok(widget, 'PortalDetail declares a PortalIdentity widget')
	assert.ok(page.config.layout.some((cell) => cell.widgetId === widget.id), 'and places it in the layout')
	assert.match(readFileSync(join(ROOT, 'src', 'registry.js'), 'utf8'), /PortalIdentity: \{/)
	const vue = readFileSync(join(ROOT, 'src', 'widgets', 'PortalIdentity.vue'), 'utf8')
	assert.match(vue, /createPortalIdentity\(/)
	assert.match(vue, /PNG, SVG or ICO/)
})
