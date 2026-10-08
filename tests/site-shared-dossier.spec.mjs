#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// site-shared-dossier.spec.mjs: the public page behind a shared dossier link
// (hydra woo-citizen-journey J3.4). The route opencatalogi hands out, the
// anonymous read, what the page keeps of the answer, the page in each state,
// the shell's wiring, and both languages for every string it shows.
//
// Usage:
//   node --test tests/site-shared-dossier.spec.mjs
//
// @spec openspec/changes/site-shared-dossier/specs/site-shared-dossier/spec.md#requirement-a-shared-dossier-link-must-open-a-public-page-req-ssd-001

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import { instanceRootFrom } from '../src/site/lib/instanceRoot.js'
import {
	fetchSharedDossier,
	isSharedDossierRoute,
	SHARED_DOSSIER_ENDPOINT,
	sharedDossierToken,
	sharedDossierView,
} from '../src/site/lib/sharedDossier.js'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const bundle = (locale) => JSON.parse(readFileSync(join(ROOT, 'src', 'shared', 'i18n', `${locale}.json`), 'utf8'))
const t = (key) => key

const TOKEN = `00000000-0000-4000-8000-000000000001.${'ab'.repeat(24)}`

/** opencatalogi's shared answer, as CollectionShareService::shared() makes it. */
const ANSWER = {
	title: 'Windpark',
	description: 'Alles over het windpark',
	items: [
		{
			id: 'i1',
			publication: 'p1',
			attachment: null,
			note: 'eerste',
			addedAt: '2026-10-01T10:00:00+00:00',
			title: 'Besluit windpark',
			url: 'https://gemeente.example/publicatie/p1',
		},
		{ id: 'i2', publication: 'p2', note: '', title: 'Advies', url: 'javascript:alert(1)' },
	],
	// Never in the answer, but never on the page if it ever were.
	owner: 'subject-1',
}

test('the route opencatalogi hands out names a shared dossier and carries its token', () => {
	assert.equal(isSharedDossierRoute(`/gedeeld-dossier/${TOKEN}`), true)
	assert.equal(isSharedDossierRoute('/gedeeld-dossier'), true)
	assert.equal(isSharedDossierRoute('/publicatie/abc'), false)
	assert.equal(isSharedDossierRoute('/gedeeld-dossiers'), false)
	assert.equal(sharedDossierToken(`/gedeeld-dossier/${TOKEN}`), TOKEN)
	assert.equal(sharedDossierToken('/gedeeld-dossier/nonsense'), '')
	assert.equal(sharedDossierToken(`/gedeeld-dossier/${TOKEN}/x`), '')
	assert.equal(sharedDossierToken(`/publicatie/${TOKEN}`), '')
})

test('the page keeps the title, the note and per item a title, a safe link and a note, never the owner', () => {
	const view = sharedDossierView(ANSWER)
	assert.deepEqual(view, {
		title: 'Windpark',
		description: 'Alles over het windpark',
		items: [
			{ id: 'i1', title: 'Besluit windpark', href: 'https://gemeente.example/publicatie/p1', note: 'eerste' },
			{ id: 'i2', title: 'Advies', href: '', note: '' },
		],
	})
	assert.doesNotMatch(JSON.stringify(view), /subject-1/)
	assert.deepEqual(sharedDossierView(null), { title: '', description: '', items: [] })
})

test('the read goes to opencatalogi under the instance root the content API names', async () => {
	const urls = []
	const fetchImpl = async (url) => {
		urls.push(url)
		return { ok: true, status: 200, json: async () => ANSWER }
	}
	await fetchSharedDossier(TOKEN, fetchImpl, instanceRootFrom('/nextcloud/index.php/apps/portaliq/api/content'))
	await fetchSharedDossier(TOKEN, fetchImpl, instanceRootFrom('/apps/portaliq/api/content/site'))
	await fetchSharedDossier(TOKEN, fetchImpl, instanceRootFrom('/api/content'))
	assert.deepEqual(urls, [
		`/nextcloud/index.php/apps/opencatalogi/api/collections/shared/${TOKEN}`,
		`/apps/opencatalogi/api/collections/shared/${TOKEN}`,
		`/index.php/apps/opencatalogi/api/collections/shared/${TOKEN}`,
	])
})

test('the read is anonymous, and a 404 and a failure are told apart', async () => {
	const calls = []
	const ok = await fetchSharedDossier(TOKEN, async (url, init) => {
		calls.push([url, init])
		return { ok: true, status: 200, json: async () => ANSWER }
	})
	assert.equal(ok.status, 'ok')
	assert.equal(ok.dossier.title, 'Windpark')
	assert.equal(calls[0][0], '/index.php' + SHARED_DOSSIER_ENDPOINT + TOKEN)
	assert.equal(calls[0][1].credentials, 'omit', 'the visitor session stays home')
	assert.equal(calls[0][1].headers.Authorization, undefined)

	const gone = await fetchSharedDossier(TOKEN, async () => ({ ok: false, status: 404 }))
	assert.deepEqual(gone, { status: 'notFound' })
	const broken = await fetchSharedDossier(TOKEN, async () => ({ ok: false, status: 500 }))
	assert.deepEqual(broken, { status: 'error' })
	const offline = await fetchSharedDossier(TOKEN, async () => { throw new Error('offline') })
	assert.deepEqual(offline, { status: 'error' })

	let asked = false
	const forged = await fetchSharedDossier('nonsense', async () => { asked = true })
	assert.deepEqual(forged, { status: 'notFound' })
	assert.equal(asked, false, 'a token opencatalogi cannot have made is not sent')
})

const { renderSfc } = await import('./support/render-sfc.mjs')

test('the page shows the dossier, its public documents with links and notes, and no owner', async () => {
	const html = await renderSfc('src/site/components/SharedDossierPage.vue', {
		token: TOKEN,
		t,
		initialAnswer: { status: 'ok', dossier: sharedDossierView(ANSWER) },
	})
	assert.match(html, /<h1[^>]*>\s*Windpark\s*<\/h1>/)
	assert.match(html, /You see only the documents that are public now\./)
	assert.match(html, /Alles over het windpark/)
	assert.match(html, /<a class="utrecht-link" href="https:\/\/gemeente\.example\/publicatie\/p1">Besluit windpark<\/a>/)
	assert.match(html, /eerste/)
	assert.match(html, /<span>Advies<\/span>/, 'an unsafe link renders as text')
	assert.doesNotMatch(html, /javascript:/)
	assert.doesNotMatch(html, /subject-1/)
	assert.equal((html.match(/data-testid="shared-dossier-item"/g) || []).length, 2)
})

test('the page says when the dossier is empty, the link is gone, or the read failed', async () => {
	const empty = await renderSfc('src/site/components/SharedDossierPage.vue', {
		token: TOKEN,
		t,
		initialAnswer: { status: 'ok', dossier: sharedDossierView({ title: 'Leeg', items: [] }) },
	})
	assert.match(empty, /This dossier has no public documents right now\./)

	const gone = await renderSfc('src/site/components/SharedDossierPage.vue', { token: TOKEN, t, initialAnswer: { status: 'notFound' } })
	assert.match(gone, /role="alert"/)
	assert.match(gone, /This link no longer works\. Ask the person who shared it for a new link\./)

	const broken = await renderSfc('src/site/components/SharedDossierPage.vue', { token: TOKEN, t, initialAnswer: { status: 'error' } })
	assert.match(broken, /We cannot show this dossier right now\. Try again later\./)
})

test('the shell renders the page for the route without reading a CMS page, and loads it on demand', () => {
	const app = readFileSync(join(ROOT, 'src', 'site', 'App.vue'), 'utf8')
	assert.match(app, /const SharedDossierPage = defineAsyncComponent\(\s*\(\) => import\('\.\/components\/SharedDossierPage\.vue'\),?\s*\)/)
	assert.match(app, /<SharedDossierPage\s+v-else-if="sharedDossierRoute"\s+:token="sharedDossierToken"/)
	const load = app.slice(app.indexOf('async loadRoute(route'))
	const early = load.indexOf('if (isSharedDossierRoute(route))')
	assert.ok(early > 0 && early < load.indexOf('fetchPage(route'), 'the route returns before any CMS read')
})

test('every string the page shows exists in English and Dutch', () => {
	const page = readFileSync(join(ROOT, 'src', 'site', 'components', 'SharedDossierPage.vue'), 'utf8')
	const keys = [...page.matchAll(/\bt\(\s*'([^']+)'/g)].map((m) => m[1])
	assert.ok(keys.length >= 5)
	const en = bundle('en')
	const nl = bundle('nl')
	for (const key of keys) {
		assert.ok(en[key], `en: ${key}`)
		assert.ok(nl[key], `nl: ${key}`)
		assert.doesNotMatch(nl[key], /—/)
	}
	assert.equal(nl['Shared dossier'], 'Gedeeld dossier')
})
