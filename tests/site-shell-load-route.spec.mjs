#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// site-shell-load-route.spec.mjs: how the site shell (src/site/App.vue)
// loads a route: the signed-in area and a shared dossier without a CMS read,
// the accessibility statement authored or generated, a page, the parent of
// a route that addresses a thing, and the not-found, sign-in and failure
// answers, with the state left behind and what runs after. Pinned before
// loadRoute was split into named steps.
//
// Usage:
//   node --test tests/site-shell-load-route.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { instance } from './support/page-instance.mjs'
import { sfcOptionsLoaded } from './support/sfc-options-loaded.mjs'

const App = await sfcOptionsLoaded('src/site/App.vue')

/**
 * A browser whose content API answers each `/page` route from a table: a
 * page object, or a number for an error status.
 *
 * @param {object} pages Route to answer.
 * @return {Array<object>} The page reads made, with route, portal, locale and cache.
 */
function browser(pages) {
	const reads = []
	const storage = { getItem: () => null, setItem() {}, removeItem() {} }
	globalThis.window = {
		location: { hash: '', search: '', pathname: '/', origin: 'https://s.nl', href: 'https://s.nl/' },
		localStorage: storage,
		sessionStorage: storage,
		history: { replaceState() {} },
	}
	globalThis.fetch = async (url, init) => {
		const query = new URL(url).searchParams
		const route = query.get('route')
		reads.push({ route, portal: query.get('portal'), locale: query.get('locale'), cache: init.cache || null })
		const answer = pages[route]
		if (typeof answer === 'number' || answer === undefined) {
			return { ok: false, status: answer ?? 404, json: async () => ({}) }
		}
		return { ok: true, status: 200, json: async () => answer }
	}
	return reads
}

/**
 * The shell on a portal, its side steps recorded rather than run.
 *
 * @return {object} The stand-in shell, with `steps` listing what ran after the read.
 */
function shell() {
	const vm = instance(App, { portalSlug: 'demo' })
	vm.steps = []
	vm.chosenLocale = 'en'
	vm.subject = { title: 'Oud' }
	vm.signInNeeded = true
	vm.statementAuthored = true
	vm.routeParam = 'stale'
	vm.sharedDossierTitle = 'Oud dossier'
	vm.error = new Error('stale')
	vm.page = { title: 'stale' }
	vm.loading = 'unset'
	vm.followAccountRoute = () => vm.steps.push('followAccountRoute')
	vm.applyDocumentTitle = () => vm.steps.push(['applyDocumentTitle', vm.loading])
	vm.refreshEditingContext = async () => vm.steps.push(['refreshEditingContext', vm.loading])
	return vm
}

/**
 * The state a load leaves behind.
 *
 * @param {object} vm The shell.
 * @return {object} The fields loadRoute writes.
 */
function stateOf(vm) {
	return {
		page: vm.page,
		error: vm.error === null ? null : vm.error.status ?? vm.error.message,
		routeParam: vm.routeParam,
		loading: vm.loading,
		signInNeeded: vm.signInNeeded,
		subject: vm.subject,
		statementAuthored: vm.statementAuthored,
		sharedDossierTitle: vm.sharedDossierTitle,
		steps: vm.steps,
	}
}

test('the signed-in area and a shared dossier open without a CMS read', async () => {
	const reads = browser({})
	const account = shell()
	await account.loadRoute('/mijn/zaken')
	assert.deepEqual(stateOf(account), {
		page: null, error: null, routeParam: '', loading: false, signInNeeded: false, subject: null,
		statementAuthored: true, sharedDossierTitle: 'Oud dossier',
		steps: ['followAccountRoute', ['applyDocumentTitle', false]],
	})
	const dossier = shell()
	await dossier.loadRoute('/gedeeld-dossier/abc')
	assert.deepEqual(stateOf(dossier), {
		page: null, error: null, routeParam: '', loading: false, signInNeeded: false, subject: null,
		statementAuthored: true, sharedDossierTitle: '',
		steps: [['applyDocumentTitle', false]],
	})
	assert.deepEqual(reads, [])
})

test('the statement reads the authored page first, else shows the generated one', async () => {
	const statement = { title: 'Toegankelijkheid' }
	const reads = browser({ '/toegankelijkheid': statement })
	const authored = shell()
	await authored.loadRoute('/toegankelijkheid', { fresh: true })
	assert.deepEqual(stateOf(authored), {
		page: statement, error: null, routeParam: '', loading: false, signInNeeded: false, subject: null,
		statementAuthored: true, sharedDossierTitle: 'Oud dossier',
		steps: [['applyDocumentTitle', false], ['refreshEditingContext', false]],
	})
	assert.deepEqual(reads, [{ route: '/toegankelijkheid', portal: 'demo', locale: 'en', cache: 'reload' }])

	browser({ '/toegankelijkheid': 500 })
	const generated = shell()
	await generated.loadRoute('/toegankelijkheid')
	assert.deepEqual(stateOf(generated), {
		page: null, error: null, routeParam: '', loading: false, signInNeeded: false, subject: null,
		statementAuthored: false, sharedDossierTitle: 'Oud dossier',
		steps: [['applyDocumentTitle', false]],
	})
})

test('a page is read for its route, and the editing context after it', async () => {
	const page = { title: 'Afval' }
	const reads = browser({ '/afval': page })
	const vm = shell()
	await vm.loadRoute('/afval')
	assert.deepEqual(stateOf(vm), {
		page, error: null, routeParam: '', loading: false, signInNeeded: false, subject: null,
		statementAuthored: false, sharedDossierTitle: 'Oud dossier',
		steps: [['refreshEditingContext', false]],
	})
	assert.deepEqual(reads, [{ route: '/afval', portal: 'demo', locale: 'en', cache: null }])
})

test('a route that addresses a thing falls back one level on a 404 only', async () => {
	const parent = { title: 'Publicatie' }
	const reads = browser({ '/publicatie/8f21': 404, '/publicatie': parent })
	const vm = shell()
	await vm.loadRoute('/publicatie/8f21', { fresh: true })
	assert.deepEqual(stateOf(vm), {
		page: parent, error: null, routeParam: '8f21', loading: false, signInNeeded: false, subject: null,
		statementAuthored: false, sharedDossierTitle: 'Oud dossier',
		steps: [],
	})
	assert.deepEqual(reads.map((r) => [r.route, r.cache]), [['/publicatie/8f21', 'reload'], ['/publicatie', 'reload']])

	browser({ '/publicatie/8f21': 404, '/publicatie': 503 })
	const failed = shell()
	await failed.loadRoute('/publicatie/8f21')
	assert.deepEqual(stateOf(failed), {
		page: null, error: 503, routeParam: '', loading: false, signInNeeded: false, subject: null,
		statementAuthored: false, sharedDossierTitle: 'Oud dossier',
		steps: [],
	})
})

test('a top-level miss, a page behind the sign-in and a failure leave the page out', async () => {
	for (const [status, signIn] of [[404, false], [401, true], [403, true], [500, false]]) {
		const reads = browser({ '/onbekend': status })
		const vm = shell()
		await vm.loadRoute('/onbekend')
		assert.deepEqual(stateOf(vm), {
			page: null, error: status, routeParam: '', loading: false, signInNeeded: signIn, subject: null,
			statementAuthored: false, sharedDossierTitle: 'Oud dossier',
			steps: [['refreshEditingContext', false]],
		}, String(status))
		assert.equal(reads.length, 1, 'no parent read for a top-level route')
	}
	for (const status of [401, 500]) {
		const reads = browser({ '/a/b': status, '/a': { title: 'A' } })
		const vm = shell()
		await vm.loadRoute('/a/b')
		assert.equal(vm.page, null)
		assert.equal(vm.error.status, status)
		assert.equal(reads.length, 1, 'only a 404 reads the parent')
	}
})
