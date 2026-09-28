#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// intake-entry.spec.mjs: the public site reaches the intake endpoints
// (portal-intake-form-as-an-object T09). The catalogue block lists what the
// published catalogue says today and links each entry to the form page, the
// form block renders the bound form with the signed-in visitor's own details
// and submits it for a reference, and the status block reads the real state
// behind a reference, a failed create included.
//
// Usage:
//   node --test tests/intake-entry.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import {
	bindingRouteFrom,
	fetchCatalogue,
	formRouteFor,
	initialValues,
	intakeUrl,
	loadForm,
	lookUpStatus,
	statusView,
	submitIntake,
} from '../src/site/lib/intakeApi.js'

const BASE = '/apps/portaliq/portal/api'

/**
 * A fetch double that records each call and answers from a queue.
 *
 * @param {Array<{status: number, body: object}>} answers The answers, in order.
 * @return {{fetchImpl: Function, calls: Array<object>}}
 */
function recorder(answers) {
	const calls = []
	const queue = [...answers]
	const fetchImpl = async (url, init = {}) => {
		calls.push({ url: String(url), init })
		const next = queue.shift() || { status: 200, body: {} }
		return {
			ok: next.status >= 200 && next.status < 300,
			status: next.status,
			json: async () => next.body,
		}
	}
	return { fetchImpl, calls }
}

test('every intake url names the portal slug when the site has one', () => {
	assert.equal(
		intakeUrl(BASE, '/intake/catalogue', { portal: 'gemeente-x' }),
		'/apps/portaliq/portal/api/intake/catalogue?portal=gemeente-x',
	)
	assert.equal(
		intakeUrl(BASE, '/intake/form', {
			route: 'aanvragen/verhuizing',
			portal: '',
		}),
		'/apps/portaliq/portal/api/intake/form?route=aanvragen%2Fverhuizing',
	)
})

test('the catalogue is read, never kept: each call asks the endpoint again', async () => {
	const { fetchImpl, calls } = recorder([
		{
			status: 200,
			body: {
				topics: [
					{
						topic: 'Wonen',
						entries: [
							{
								title: 'Verhuizing doorgeven',
								route: 'aanvragen/verhuizing',
								summary: '',
							},
						],
					},
				],
			},
		},
		{ status: 200, body: { topics: [] } },
	])
	const first = await fetchCatalogue(BASE, 'gemeente-x', fetchImpl)
	assert.equal(first.length, 1)
	assert.equal(first[0].entries[0].route, 'aanvragen/verhuizing')
	const second = await fetchCatalogue(BASE, 'gemeente-x', fetchImpl)
	assert.deepEqual(second, [])
	assert.equal(calls.length, 2)
})

test('an entry without a route is not offered, and a topic left empty is dropped', async () => {
	const { fetchImpl } = recorder([
		{
			status: 200,
			body: {
				topics: [
					{ topic: 'Afval', entries: [{ title: 'Kapot', route: '' }] },
					{
						topic: 'Wonen',
						entries: [{ title: 'Verhuizing', route: 'a/b' }],
					},
				],
			},
		},
	])
	const topics = await fetchCatalogue(BASE, '', fetchImpl)
	assert.deepEqual(
		topics.map((t) => t.topic),
		['Wonen'],
	)
})

test('an entry opens the form page with the binding route as one segment', () => {
	assert.equal(
		formRouteFor('/aanvragen/formulier', 'aanvragen/verhuizing'),
		'/aanvragen/formulier/aanvragen%2Fverhuizing',
	)
	assert.equal(
		bindingRouteFrom('aanvragen%2Fverhuizing', ''),
		'aanvragen/verhuizing',
	)
	// A form placed on its own page names its route; the URL cannot move it.
	assert.equal(
		bindingRouteFrom('aanvragen%2Fander', 'aanvragen/vast'),
		'aanvragen/vast',
	)
	assert.equal(bindingRouteFrom('%E0%A4%A', ''), '')
})

test('the form is loaded with the bearer, so a signed-in visitor gets their own details', async () => {
	const { fetchImpl, calls } = recorder([
		{
			status: 200,
			body: {
				kind: 'hosted',
				resolvesToNoForm: false,
				fields: [{ name: 'naam', label: 'Naam', required: true }],
				prefill: { naam: 'J. Jansen' },
				settings: {},
			},
		},
	])
	const view = await loadForm(
		BASE,
		'aanvragen/verhuizing',
		'gemeente-x',
		'tok-1',
		fetchImpl,
	)
	assert.equal(view.state, 'form')
	assert.equal(calls[0].init.headers.Authorization, 'Bearer tok-1')
	assert.deepEqual(initialValues(view.render.fields, view.render.prefill), {
		naam: 'J. Jansen',
	})
})

test('an anonymous visitor sends no bearer and gets an empty applicant block', async () => {
	const { fetchImpl, calls } = recorder([
		{
			status: 200,
			body: {
				kind: 'hosted',
				resolvesToNoForm: false,
				fields: [{ name: 'naam' }, { name: 'soort', preset: 'huur' }],
				prefill: {},
				settings: {},
			},
		},
	])
	const view = await loadForm(BASE, 'aanvragen/verhuizing', '', '', fetchImpl)
	assert.equal(calls[0].init.headers.Authorization, undefined)
	assert.deepEqual(initialValues(view.render.fields, view.render.prefill), {
		naam: '',
		soort: 'huur',
	})
})

test('a form that needs a sign-in, an unknown route, an external form and no form each say so', async () => {
	const { fetchImpl } = recorder([
		{
			status: 401,
			body: { error: 'sign_in_required', minTrust: 'substantial' },
		},
		{ status: 404, body: { error: 'form_not_found' } },
		{
			status: 200,
			body: {
				kind: 'external',
				resolvesToNoForm: false,
				externalUrl: 'https://formulieren.example.org/x',
				destination: 'formulieren.example.org',
				settings: {},
			},
		},
		{
			status: 200,
			body: {
				kind: 'hosted',
				resolvesToNoForm: true,
				reason: 'no_published_form_for_audience',
				fields: [],
				settings: {},
			},
		},
	])
	assert.equal((await loadForm(BASE, 'r', '', '', fetchImpl)).state, 'signIn')
	assert.equal((await loadForm(BASE, 'r', '', '', fetchImpl)).state, 'notFound')
	const external = await loadForm(BASE, 'r', '', '', fetchImpl)
	assert.equal(external.state, 'external')
	assert.equal(external.render.destination, 'formulieren.example.org')
	assert.equal((await loadForm(BASE, 'r', '', '', fetchImpl)).state, 'noForm')
})

test('a valid submission answers with a reference, an invalid one with the field errors', async () => {
	const { fetchImpl, calls } = recorder([
		{
			status: 200,
			body: {
				reference: 'AANVRAAG-7',
				state: 'queued',
				confirmationText: 'Bedankt.',
			},
		},
		{ status: 400, body: { errors: { postcode: 'Vul uw postcode in.' } } },
		{ status: 503, body: { error: 'not_accepted' } },
	])
	const accepted = await submitIntake(
		BASE,
		'aanvragen/verhuizing',
		{ postcode: '1234 AB' },
		'gemeente-x',
		'tok-1',
		fetchImpl,
	)
	assert.deepEqual(accepted, {
		reference: 'AANVRAAG-7',
		confirmationText: 'Bedankt.',
		errors: {},
	})
	assert.equal(calls[0].init.method, 'POST')
	assert.deepEqual(JSON.parse(calls[0].init.body), {
		route: 'aanvragen/verhuizing',
		answers: { postcode: '1234 AB' },
		portal: 'gemeente-x',
	})
	assert.equal(calls[0].init.headers.Authorization, 'Bearer tok-1')

	const refused = await submitIntake(
		BASE,
		'aanvragen/verhuizing',
		{},
		'',
		'',
		fetchImpl,
	)
	assert.equal(refused.reference, '')
	assert.deepEqual(refused.errors, { postcode: 'Vul uw postcode in.' })

	await assert.rejects(
		submitIntake(BASE, 'aanvragen/verhuizing', {}, '', '', fetchImpl),
		/503/,
	)
})

test('the status of a reference is the real state, and a failed create names what to do', async () => {
	const { fetchImpl } = recorder([
		{
			status: 200,
			body: {
				reference: 'AANVRAAG-7',
				state: 'failed',
				caseId: '',
				failureReason: 'case app down',
			},
		},
		{ status: 404, body: { error: 'reference_not_found' } },
	])
	const failed = await lookUpStatus(BASE, 'AANVRAAG-7', '', fetchImpl)
	assert.equal(failed.state, 'failed')
	assert.equal(await lookUpStatus(BASE, 'NOPE', '', fetchImpl), null)
	assert.equal(await lookUpStatus(BASE, '   ', '', fetchImpl), null)

	const view = statusView(failed)
	assert.equal(view.tone, 'error')
	assert.match(view.sentence, /nog niet geregistreerd/)
	assert.match(view.sentence, /AANVRAAG-7/)
})

test('a queued request never claims a case, a registered one does', () => {
	const queued = statusView({ reference: 'A-1', state: 'queued', caseId: '' })
	assert.doesNotMatch(queued.sentence, /zaak/)
	const registered = statusView({
		reference: 'A-1',
		state: 'registered',
		caseId: 'c-9',
	})
	assert.match(registered.sentence, /geregistreerd/)
	assert.equal(statusView(null).tone, 'error')
})

test('no user-facing sentence carries an em-dash', () => {
	const sentences = [
		statusView({ reference: 'A', state: 'queued' }).sentence,
		statusView({ reference: 'A', state: 'registered', caseId: 'c' }).sentence,
		statusView({ reference: 'A', state: 'failed' }).sentence,
		statusView(null).sentence,
	]
	for (const sentence of sentences) {
		assert.doesNotMatch(sentence, /—/)
	}
})

// The wiring, read from the caller. The endpoints and the helpers above were
// both correct for weeks with nothing calling them; these assertions fail if
// the public renderer or the page designer stops offering the three blocks.
test('the public renderer mounts the three intake blocks and hands them the portal', async () => {
	const { readFile } = await import('node:fs/promises')
	const grid = await readFile(
		new URL('../src/site/components/WidgetGrid.vue', import.meta.url),
		'utf8',
	)
	const allowList = grid.slice(
		grid.indexOf('const PUBLIC_WIDGETS = {'),
		grid.indexOf('...siteBlockRegistry'),
	)
	for (const [key, component] of [
		['intakeCatalogue', 'IntakeCatalogueBlock'],
		['intakeForm', 'IntakeFormBlock'],
		['intakeStatus', 'IntakeStatusBlock'],
	]) {
		assert.match(
			allowList,
			new RegExp(`\\b${key}: ${component},`),
			`${key} must be on the public allow-list`,
		)
		assert.match(
			grid,
			new RegExp(`import\\('\\./${component}\\.vue'\\)`),
			`${component} must be loaded`,
		)
	}
	assert.match(
		grid,
		/widgetKey === 'intakeForm'\) \{\s*return \{ \.\.\.props, portal: this\.portal, routeParam: this\.routeParam \}/,
	)
	assert.match(
		grid,
		/widgetKey === 'intakeCatalogue'\s*\|\|\s*widget\.widgetKey === 'intakeStatus'\s*\)\s*\{\s*return \{ \.\.\.props, portal: this\.portal \}/,
	)

	const catalogue = await readFile(
		new URL('../src/lib/pageWidgetCatalogue.js', import.meta.url),
		'utf8',
	)
	assert.match(
		catalogue,
		/intakeForm: \['portal', 'routeParam'\]/,
		'the designer must not offer host-supplied props',
	)
	assert.match(catalogue, /intakeCatalogue: 'Aanvragen per onderwerp'/)
})
