#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// record-and-search-links.spec.mjs: a link to one public record
// (`?record=<id>`), to a federated search result and to an intake form keeps
// the portal, the language and the page's route, in the addressing of the
// host (record-and-search-links-keep-their-portal REQ-RSL-001). A bare
// `?record=` dropped `portal` and `route` and opened another portal on a
// host that serves several (Zuiddrecht live test, 14 October 2026).
//
// Usage:
//   node --test tests/record-and-search-links.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { addressOfRecord } from '../src/site/lib/siteAddress.js'
import { instance, t } from './support/page-instance.mjs'
import { loadSfc } from './support/render-sfc.mjs'

const SITE = '/index.php/apps/portaliq/site'
const LONG = { addressing: { root: false, siteRoute: SITE, landingRoute: '' } }
const AT_ROOT = { addressing: { root: true, siteRoute: SITE, landingRoute: '' } }

/**
 * A browser on this address, with this runtime config.
 *
 * @param {string} href The address.
 * @param {object} config The runtime config.
 * @return {void}
 */
function browserAt(href, config) {
	globalThis.document = {
		getElementById: () => null,
		querySelector: () => null,
	}
	globalThis.window = {
		location: new URL(href),
		PORTALIQ_SITE_CONFIG: config,
	}
}

test('a record link keeps the portal, the language and the route', () => {
	const href = `https://cloud.example${SITE}?portal=zuiddrecht&lang=en&route=/bestuur/besluiten&record=old`
	assert.equal(
		addressOfRecord('besluit 7', href, LONG).toString(),
		`https://cloud.example${SITE}?portal=zuiddrecht&lang=en&route=%2Fbestuur%2Fbesluiten&record=besluit+7`,
	)
})

test('on a host that serves the site at its root a record link is short', () => {
	assert.equal(
		addressOfRecord(
			'7',
			'https://zuiddrecht.example/bestuur/besluiten?x=1',
			AT_ROOT,
		).toString(),
		'https://zuiddrecht.example/bestuur/besluiten?record=7',
	)
})

test('the public records block links a record through the page address', async () => {
	const Block = await loadSfc('src/site/components/PublicRecordsBlock.vue')
	browserAt(
		`https://cloud.example${SITE}?portal=zuiddrecht&route=/bestuur/vergaderingen`,
		LONG,
	)
	const block = instance(Block, { t })
	const url = new URL(block.hrefFor({ id: 'vergadering-1' }))
	assert.equal(url.searchParams.get('portal'), 'zuiddrecht')
	assert.equal(url.searchParams.get('route'), '/bestuur/vergaderingen')
	assert.equal(url.searchParams.get('record'), 'vergadering-1')
})

test('a search result links to its page on the same portal', async () => {
	const Block = await loadSfc('src/site/components/FederatedSearchBlock.vue', {
		'@conduction/nextcloud-vue/public': 'export const CnSiteSearch = {}',
	})
	browserAt(
		`https://cloud.example${SITE}?portal=zuiddrecht&lang=en&route=/zoeken&_search=afval`,
		LONG,
	)
	const block = instance(Block, { t })
	block.detailRoute = '/publicatie'
	const href = block.detailHref({ id: 'pub-1', kind: 'publication' })
	assert.equal(
		href,
		`https://cloud.example${SITE}?portal=zuiddrecht&lang=en&route=%2Fpublicatie%2Fpub-1`,
	)

	browserAt('https://zuiddrecht.example/zoeken?_search=afval', AT_ROOT)
	assert.equal(
		block.detailHref({ id: 'pub-1', kind: 'publication' }),
		'https://zuiddrecht.example/publicatie/pub-1',
	)
})

test('an intake catalogue entry links to its form on the same portal', async () => {
	const Block = await loadSfc('src/site/components/IntakeCatalogueBlock.vue')
	browserAt(
		`https://cloud.example${SITE}?portal=zuiddrecht&route=/aanvragen`,
		LONG,
	)
	const block = instance(Block, { t })
	const url = new URL(block.hrefFor({ route: 'parkeervergunning' }))
	assert.equal(url.searchParams.get('portal'), 'zuiddrecht')
	assert.ok(url.searchParams.get('route'))
})
