#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// subject-landing.spec.mjs: a subject's own page and the featured subjects
// (home-and-theme-landing-pages REQ-HTL-002, REQ-HTL-003). The fixture holds
// the keys of opencatalogi's subject routes (tests/fixtures/subjects-contract.json).
//
// Usage:
//   node --test tests/subject-landing.spec.mjs
//
// @spec openspec/changes/home-and-theme-landing-pages/specs/portal-federated-search/spec.md

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { featuredFrom, fetchFeatured, fetchSubject, imageOf, subjectFrom, subjectHref } from '../src/site/lib/subjects.js'
import { loadSfc, renderSfc } from './support/render-sfc.mjs'

const CONTRACT = JSON.parse(readFileSync(new URL('./fixtures/subjects-contract.json', import.meta.url), 'utf8'))
const LANDING = 'src/site/widgets/nlSubjectLanding/NlSubjectLanding.vue'
const FEATURED = 'src/site/widgets/nlFeaturedSubjects/NlFeaturedSubjects.vue'

/**
 * A fetch that answers a status and a body, and remembers what it was asked.
 *
 * @param {number} status The status.
 * @param {object} body The body.
 * @param {Array<object>} asked Where the calls are recorded.
 * @return {Function} The fetch.
 */
function answer(status, body, asked = []) {
	return async (url, init) => {
		asked.push({ url, init })
		return { ok: status < 400, status, json: async () => body }
	}
}

test('the subject\'s image, title and description render', async () => {
	const { subject } = await fetchSubject('parkeren', { fetchImpl: answer(200, CONTRACT.one) })
	assert.equal(subject.title, 'Parkeren')
	assert.deepEqual(subject.image, { url: '/img/parkeren.jpg', alt: 'Een parkeerplaats' })
	const component = await loadSfc(LANDING)
	assert.ok(component.components.FederatedSearchBlock, 'the search block sits under the description')
	const html = await renderSfc(LANDING, { routeParam: 'parkeren' })
	assert.match(html, /Het onderwerp wordt geladen|nl-subject-landing/)
	const template = readFileSync(LANDING, 'utf8')
	assert.match(template, /:lockedFilters="\{ themes: subject\.id \}"/, 'locked to the subject')
	assert.match(template, /:alt="subject\.image\.alt"/, 'the image carries its alt text')
	assert.match(template, /subject\.description \|\| subject\.summary/)
})

test('an unknown subject renders not found', async () => {
	for (const status of [404, 403]) {
		assert.deepEqual(await fetchSubject('bestaat-niet', { fetchImpl: answer(status, {}) }), { state: 'notFound', subject: null })
	}
	assert.equal((await fetchSubject('x', { fetchImpl: answer(200, { id: 'x' }) })).state, 'notFound', 'a row with no title')
	assert.equal((await fetchSubject('x', { fetchImpl: answer(500, {}) })).state, 'failed')
	assert.equal((await fetchSubject('x', { fetchImpl: async () => { throw new Error('offline') } })).state, 'failed')
	for (const slug of ['', '../etc', 'a/b', 'a b']) {
		const asked = []
		assert.equal((await fetchSubject(slug, { fetchImpl: answer(200, CONTRACT.one, asked) })).state, 'notFound')
		assert.equal(asked.length, 0, `"${slug}" is not asked for`)
	}
	const component = await loadSfc(LANDING)
	const vm = { routeParam: '', subject: null, state: 'loading', slug: () => 'bestaat-niet' }
	globalThis.window = { fetch: answer(404, {}) }
	try {
		await component.mounted.call(vm)
	} finally {
		delete globalThis.window
	}
	assert.equal(vm.state, 'notFound')
	const html = await renderSfc(LANDING, { routeParam: 'bestaat-niet', backLabel: 'Alle onderwerpen' })
	assert.doesNotMatch(html, /nl-subject-image/)
})

test('the subject is read without the viewer\'s session and the slug comes from the route', async () => {
	const asked = []
	await fetchSubject('parkeren', { fetchImpl: answer(200, CONTRACT.one, asked) })
	assert.equal(asked[0].url, '/index.php/apps/opencatalogi/api/themes/parkeren')
	assert.equal(asked[0].init.credentials, 'omit')
	const component = await loadSfc(LANDING)
	assert.equal(component.methods.slug.call({ routeParam: 'parkeren' }), 'parkeren')
	globalThis.window = { location: { pathname: '/onderwerp/fietsen' } }
	try {
		assert.equal(component.methods.slug.call({ routeParam: '' }), 'fietsen')
	} finally {
		delete globalThis.window
	}
})

test('only featured rows are listed, in featuredOrder', () => {
	const shown = featuredFrom(CONTRACT.list, 6)
	assert.deepEqual(shown.map((s) => s.slug), ['parkeren', 'fietsen', 'afval'], 'ordered, a row not featured and a row without a title left out')
	assert.equal(featuredFrom(CONTRACT.list, 2).length, 2, 'at most the number the author set')
	assert.deepEqual(featuredFrom({ results: [{ id: 'a', slug: 'a', title: 'A' }] }, 6), [], 'an older opencatalogi that ignores the filter shows nothing, not everything')
	assert.deepEqual(featuredFrom({}, 6), [])
	assert.deepEqual(featuredFrom(null, 6), [])
	assert.equal(shown[0].publicationCount, 14)
	assert.equal(shown[2].publicationCount, 3)
	assert.equal(subjectFrom({ title: 'x', slug: '../x', featured: true }).slug, '', 'a slug that is not a path segment is dropped')
	assert.equal(featuredFrom({ results: [{ title: 'x', slug: '../x', featured: true }] }).length, 0, 'and so cannot be linked to')
	assert.deepEqual(imageOf('/a.png', 'Titel'), { url: '/a.png', alt: 'Titel' }, 'the title is the alt text when none is given')
	assert.equal(imageOf('', 'x'), null)
	assert.equal(subjectHref('parkeren'), '/onderwerp/parkeren')
	assert.equal(subjectHref('a b', '/thema/'), '/thema/a%20b')
})

test('the featured widget asks for featured rows anonymously and tells when the catalogue is absent', async () => {
	const asked = []
	const ok = await fetchFeatured({ count: 6, fetchImpl: answer(200, CONTRACT.list, asked) })
	assert.equal(ok.state, 'ok')
	assert.equal(new URL(asked[0].url).searchParams.get('featured'), 'true')
	assert.equal(asked[0].init.credentials, 'omit')
	assert.equal((await fetchFeatured({ fetchImpl: answer(404, {}) })).state, 'absent')
	assert.equal((await fetchFeatured({ fetchImpl: answer(500, {}) })).state, 'failed')
	const component = await loadSfc(FEATURED)
	const vm = { say: component.methods.say }
	assert.equal(component.methods.countText.call(vm, { publicationCount: 1 }), '1 publicatie')
	assert.equal(component.methods.countText.call(vm, { publicationCount: 14 }), '14 publicaties')
	assert.equal(component.methods.hrefOf.call({ subjectRoute: '/onderwerp' }, { slug: 'parkeren' }), '/onderwerp/parkeren')
	const html = await renderSfc(FEATURED, { heading: 'Uitgelichte onderwerpen' })
	assert.match(html, /Uitgelichte onderwerpen/)
})
