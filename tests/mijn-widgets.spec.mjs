#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// mijn-widgets.spec.mjs: the Mijn omgeving widgets a CMS page may place
// (site-nlds-widget-palette T9, REQ-SNW-012). They read only what the
// signed-in shell hands down, look a placement's names up in the visitor's
// own contributions, and show a sign-in prompt and read nothing signed out.
// The two content widgets of the wave (steps, files) keep only what they may
// draw.
//
// Usage:
//   node --test tests/mijn-widgets.spec.mjs
//
// @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-a-mijn-omgeving-widget-must-show-only-the-visitors-own-data-req-snw-012

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { loaders, metas, SIGNED_IN_WIDGETS } from '../src/site/widgets/index.js'
import {
	authoredFigures,
	authoredFiles,
	authoredSteps,
	ownBlock,
	ownCollection,
	ownContribution,
	ownRecord,
	ownRows,
	ownShell,
	ownStore,
	portalTaskRows,
	recordRouteOf,
	rowId,
	signInWays,
} from '../src/site/widgets/ownData.js'
import { loadSfc, renderSfc } from './support/render-sfc.mjs'

const WAVE = [
	'nlCases',
	'nlTasks',
	'nlInbox',
	'nlTimeline',
	'nlFigures',
	'nlCalendar',
	'nlSteps',
	'nlFileList',
	'nlRecordSwitcher',
]

const CASES = { id: 'mijn-zaken', register: 'r', schema: 'zaak', label: 'Zaken' }

/**
 * A shell whose api records every call.
 *
 * @param {object} [answers] What the api answers.
 * @return {{mijn: object, calls: Array<string>}} The shell and its calls.
 */
function shell(answers = {}) {
	const calls = []
	const api = {
		async fetchCollection(collection) {
			calls.push(`collection:${collection.id}`)
			if (answers.throws) {
				throw new Error('down')
			}
			return Object.hasOwn(answers, collection.id)
				? answers[collection.id]
				: []
		},
		async fetchTasks() {
			calls.push('tasks')
			return answers.tasks ?? { results: [], total: 0 }
		},
		async fetchInbox() {
			calls.push('inbox')
			return []
		},
	}
	return {
		calls,
		mijn: {
			api,
			contributions: [
				{
					app: 'dossiq',
					collections: [
						CASES,
						null,
						{ id: 'besluiten', register: 'r', schema: 'b' },
					],
					pages: [
						{
							id: 'overzicht',
							blocks: [
								{
									type: 'kpi',
									collection: 'mijn-zaken',
									cards: [{ field: 'n', label: 'Zaken' }],
								},
								{
									type: 'calendar',
									sources: [
										{ collection: 'mijn-zaken' },
										{ collection: 'elders' },
									],
								},
							],
						},
					],
				},
			],
			nav: [],
			t: null,
			locale: 'nl',
		},
	}
}

test('the wave registers nine widgets, the Mijn omgeving ones signed in and the content ones public', () => {
	for (const key of WAVE) {
		assert.equal(typeof loaders[key], 'function', `${key} has no loader`)
		assert.equal(metas[key].key, key)
	}
	assert.deepEqual(
		[...SIGNED_IN_WIDGETS].sort(),
		Object.keys(metas)
			.filter((key) => metas[key].scope === 'signedIn')
			.sort(),
		'the grid hands the shell data to exactly the signed-in widgets',
	)
	for (const key of SIGNED_IN_WIDGETS) {
		assert.equal(metas[key].group, 'mijn', `${key} sits in Mijn omgeving`)
	}
	assert.equal(metas.nlSteps.scope, 'public')
	assert.equal(metas.nlFileList.scope, 'public')
	assert.equal(metas.nlSteps.group, 'content')
	assert.equal(metas.nlFileList.group, 'content')
})

test('a placement can name no data, endpoint or subject of its own', () => {
	// The authored fields name an app, a collection or a page; never the
	// shell's data, the api or the ways in.
	for (const key of SIGNED_IN_WIDGETS) {
		const names = metas[key].fields.map((field) => field.name)
		for (const forbidden of [
			'mijn',
			'api',
			'signInRoutes',
			'routeParam',
			'rows',
			'subject',
		]) {
			assert.equal(
				names.includes(forbidden),
				false,
				`${key} lets an author set ${forbidden}`,
			)
		}
	}
	const grid = readFileSync(
		new URL('../src/site/components/WidgetGrid.vue', import.meta.url),
		'utf8',
	)
	assert.match(
		grid,
		/\.\.\.props,\s*mijn: this\.signedIn === true \? this\.mijn : null,\s*signInRoutes: this\.signInRoutes,/,
		'the shell data comes after the authored props, and only with a session',
	)
	assert.match(grid, /SIGNED_IN_WIDGETS\.includes\(widget\.widgetKey\)/)
	const app = readFileSync(new URL('../src/site/App.vue', import.meta.url), 'utf8')
	assert.match(app, /mijn: this\.mijnContext,/)
	assert.match(app, /mijnContext\(\) \{\s*if \(!this\.session\) \{\s*return null/)
})

test("a name is looked up in the visitor's own contributions only", () => {
	const { mijn } = shell()
	assert.equal(ownShell(null), null)
	assert.equal(ownShell({ contributions: [] }), null, 'no api is no shell')
	assert.equal(ownContribution(mijn, 'dossiq').app, 'dossiq')
	assert.equal(
		ownContribution(mijn, 'learniq'),
		null,
		'an app the visitor has nothing from',
	)
	assert.equal(ownContribution(mijn, ''), null)
	assert.equal(ownContribution(null, 'dossiq'), null)
	assert.equal(ownCollection(mijn, 'dossiq', 'mijn-zaken'), CASES)
	assert.equal(
		ownCollection(mijn, 'dossiq', 'other'),
		null,
		'a collection the contribution does not declare',
	)
	assert.equal(ownCollection(mijn, 'dossiq', ''), null)
	assert.equal(
		ownBlock(mijn, 'dossiq', 'overzicht', 'kpi').collection,
		'mijn-zaken',
	)
	assert.equal(ownBlock(mijn, 'dossiq', 'overzicht', 'calendar').sources.length, 2)
	assert.equal(ownBlock(mijn, 'dossiq', 'overzicht', 'tasks'), null)
	assert.equal(ownBlock(mijn, 'dossiq', 'elders', 'kpi'), null)
	assert.equal(ownBlock(mijn, 'dossiq', '', 'kpi'), null)
})

test('rows come through the scoped read, and a failed read is not an empty list', async () => {
	const ok = shell({ 'mijn-zaken': [{ id: 'z1' }] })
	assert.deepEqual(await ownRows(ok.mijn, CASES), [{ id: 'z1' }])
	assert.deepEqual(ok.calls, ['collection:mijn-zaken'])

	const refused = shell({ 'mijn-zaken': null })
	assert.equal(await ownRows(refused.mijn, CASES), null)
	const down = shell({ throws: true })
	assert.equal(await ownRows(down.mijn, CASES), null)
	assert.equal(await ownRows(null, CASES), null, 'signed out reads nothing')
	assert.equal(await ownRows(ok.mijn, null), null)

	const both = shell({ 'mijn-zaken': [{ id: 'z1' }] })
	const { store, failed } = await ownStore(both.mijn, 'dossiq', [
		'mijn-zaken',
		'elders',
		'mijn-zaken',
	])
	assert.deepEqual(store, { 'mijn-zaken': { objects: [{ id: 'z1' }] } })
	assert.equal(failed, false)
	assert.deepEqual(
		both.calls,
		['collection:mijn-zaken'],
		'an undeclared source is not read, a repeat once',
	)
	assert.equal(
		(await ownStore(refused.mijn, 'dossiq', ['mijn-zaken'])).failed,
		true,
	)
})

test('portal tasks answer null on a failed read', async () => {
	const { mijn } = shell({ tasks: { results: [{ title: 'Upload' }], total: 1 } })
	assert.deepEqual(await portalTaskRows(mijn), [{ title: 'Upload' }])
	assert.equal(
		await portalTaskRows(shell({ tasks: { results: [], failed: true } }).mijn),
		null,
	)
	assert.equal(await portalTaskRows(null), null)
	const broken = shell()
	broken.mijn.api.fetchTasks = async () => {
		throw new Error('down')
	}
	assert.equal(await portalTaskRows(broken.mijn), null)
})

test("the route opens only one of the visitor's own records", () => {
	const rows = [{ id: 'a' }, { uuid: 'b' }, { '@self': { id: 'c' } }]
	assert.equal(rowId(rows[1]), 'b')
	assert.equal(rowId(rows[2]), 'c')
	assert.equal(rowId(null), '')
	assert.equal(ownRecord(rows, 'c'), rows[2])
	assert.equal(ownRecord(rows, 'someone-else'), null)
	assert.equal(ownRecord(rows, ''), null)
	assert.equal(ownRecord(null, 'a'), null)
	assert.equal(recordRouteOf('/kinderen/', 'a b'), '/kinderen/a%20b')
	assert.equal(
		recordRouteOf('https://elders.example', 'a'),
		'',
		'not a route in the site',
	)
	assert.equal(recordRouteOf('//elders.example', 'a'), '')
	assert.equal(recordRouteOf('/kinderen', ''), '')
})

test("the ways in are the shell's, and authored content keeps only what it may draw", () => {
	assert.deepEqual(
		signInWays([
			{ mode: 'digid', label: 'Inloggen met DigiD', href: '/sign-in/digid' },
			{
				key: 'broker',
				mode: 'oidc',
				label: ' Met eHerkenning ',
				href: '/sign-in/broker',
			},
			{ mode: 'x', label: '', href: '/x' },
			{ mode: 'y', label: 'Y' },
			null,
		]),
		[
			{ id: 'digid', label: 'Inloggen met DigiD', href: '/sign-in/digid' },
			{ id: 'broker', label: 'Met eHerkenning', href: '/sign-in/broker' },
		],
	)
	assert.deepEqual(signInWays(null), [])

	assert.deepEqual(
		authoredFigures([
			{ value: 3, label: 'Open' },
			{ value: '2', label: '' },
			{ value: {}, label: 'x' },
		]),
		{
			cards: [{ field: 'figure0', label: 'Open' }],
			row: { figure0: 3 },
		},
	)
	assert.deepEqual(authoredFigures(null), { cards: [], row: null })

	assert.deepEqual(
		authoredSteps([
			{ label: 'Aanvraag', state: 'done', date: '2026-10-01' },
			{ label: 'Beoordeling', state: 'current', text: 'Wij kijken ernaar.' },
			{ label: 'Besluit', state: 'weird' },
			{ label: '' },
		]),
		[
			{
				label: 'Aanvraag',
				state: 'done',
				description: '',
				date: '2026-10-01',
			},
			{
				label: 'Beoordeling',
				state: 'current',
				description: 'Wij kijken ernaar.',
				date: '',
			},
			{ label: 'Besluit', state: 'upcoming', description: '', date: '' },
		],
	)

	assert.deepEqual(
		authoredFiles([
			{
				label: 'Folder',
				href: 'https://example.org/folder.pdf',
				line: 'PDF, 84 kB',
			},
			{ label: 'Kwaad', href: 'javascript:alert(1)' },
			{ label: '', href: 'https://example.org/x.pdf' },
		]),
		[
			{
				label: 'Folder',
				href: 'https://example.org/folder.pdf',
				line: 'PDF, 84 kB',
			},
		],
	)
})

test('signed out, a case list on a CMS page shows a sign-in prompt and sends no case request', async () => {
	const html = await renderSfc('src/site/widgets/nlCases/NlCases.vue', {
		app: 'dossiq',
		collection: 'mijn-zaken',
		label: 'Mijn zaken',
		mijn: null,
		signInRoutes: [
			{ mode: 'digid', label: 'Inloggen met DigiD', href: '/sign-in/digid' },
		],
	})
	assert.match(html, /data-testid="mijn-sign-in-prompt"/)
	assert.match(html, /Log in om dit te zien\./)
	assert.match(html, /href="\/sign-in\/digid"/)
	assert.doesNotMatch(html, /mijn-cases-block/)

	// The read itself: signed out, load() asks nothing.
	const component = await loadSfc('src/site/widgets/nlCases/NlCases.vue')
	const { calls } = shell()
	const self = {
		mijn: null,
		app: 'dossiq',
		collection: 'mijn-zaken',
		rows: [],
		loading: false,
		failed: false,
	}
	self.signedIn = component.mixins[0].computed.signedIn.call(self)
	self.ownCollection = component.mixins[0].computed.ownCollection.call(self)
	await component.mixins[0].methods.load.call(self)
	assert.deepEqual(calls, [])
	assert.equal(self.loading, false)
})

test('signed in, a case list reads the named collection through the shell', async () => {
	const component = await loadSfc('src/site/widgets/nlCases/NlCases.vue')
	const { mijn, calls } = shell({ 'mijn-zaken': [{ id: 'z1' }] })
	const self = {
		mijn,
		app: 'dossiq',
		collection: 'mijn-zaken',
		rows: [],
		loading: false,
		failed: false,
	}
	const mixin = component.mixins[0]
	self.signedIn = mixin.computed.signedIn.call(self)
	self.ownCollection = mixin.computed.ownCollection.call(self)
	await mixin.methods.load.call(self)
	assert.deepEqual(calls, ['collection:mijn-zaken'])
	assert.deepEqual(self.rows, [{ id: 'z1' }])
	assert.equal(self.failed, false)

	// A collection the visitor's contribution does not declare is not read.
	const other = shell()
	const foreign = {
		mijn: other.mijn,
		app: 'dossiq',
		collection: 'iemand-anders',
		rows: [],
	}
	foreign.signedIn = mixin.computed.signedIn.call(foreign)
	foreign.ownCollection = mixin.computed.ownCollection.call(foreign)
	await mixin.methods.load.call(foreign)
	assert.deepEqual(other.calls, [])

	assert.equal(mixin.computed.rowLimit.call({ limit: '50' }), 20)
	assert.equal(mixin.computed.rowLimit.call({ limit: 0 }), 5)
	assert.equal(mixin.computed.rowLimit.call({ limit: 3 }), 3)
})

test('every signed-in widget shows the prompt signed out', async () => {
	const stubs = {}
	for (const key of SIGNED_IN_WIDGETS) {
		const html = await renderSfc(
			`src/site/widgets/${key}/Nl${key.slice(2)}.vue`,
			{ app: 'dossiq', mijn: null },
			stubs,
		)
		assert.match(
			html,
			/data-testid="mijn-sign-in-prompt"/,
			`${key} shows no sign-in prompt`,
		)
		assert.match(
			html,
			/href="[^"]*mijn"/,
			`${key} offers no way to the signed-in area`,
		)
	}
})

test('the tasks widget reads the portal tasks when it names no collection', async () => {
	const component = await loadSfc('src/site/widgets/nlTasks/NlTasks.vue')
	const { mijn, calls } = shell({
		tasks: { results: [{ title: 'Upload', dueAt: '2026-12-01' }] },
	})
	const self = {
		mijn,
		collection: '',
		rows: [],
		loading: false,
		failed: false,
		signedIn: true,
	}
	await component.methods.load.call(self)
	assert.deepEqual(calls, ['tasks'])
	assert.equal(self.rows.length, 1)
	assert.deepEqual(
		component.computed.taskBlock.call({ collection: '', rowLimit: 5, label: '' })
			.dueField,
		'dueAt',
	)
	assert.equal(
		component.computed.taskBlock.call({
			collection: 'c',
			dueField: 'deadline',
			rowLimit: 5,
			label: '',
		}).dueField,
		'deadline',
	)
})

test('the figures and calendar widgets read the sources of the named block', async () => {
	const figures = await loadSfc('src/site/widgets/nlFigures/NlFigures.vue')
	const { mijn, calls } = shell({ 'mijn-zaken': [{ n: 4 }] })
	const self = { mijn, app: 'dossiq', page: 'overzicht', rows: [], signedIn: true }
	self.kpiBlock = figures.computed.kpiBlock.call(self)
	await figures.methods.load.call(self)
	assert.deepEqual(calls, ['collection:mijn-zaken'])
	assert.deepEqual(figures.computed.figures.call(self).row, { n: 4 })
	const typed = figures.computed.figures.call({
		kpiBlock: null,
		items: [{ value: 2, label: 'Open' }],
	})
	assert.deepEqual(typed.cards, [{ field: 'figure0', label: 'Open' }])

	const calendar = await loadSfc('src/site/widgets/nlCalendar/NlCalendar.vue')
	const second = shell({ 'mijn-zaken': [] })
	const cal = {
		mijn: second.mijn,
		app: 'dossiq',
		page: 'overzicht',
		signedIn: true,
		store: {},
	}
	cal.calendarBlock = calendar.computed.calendarBlock.call(cal)
	await calendar.methods.load.call(cal)
	assert.deepEqual(
		second.calls,
		['collection:mijn-zaken'],
		'a source the contribution does not declare is not read',
	)
	assert.equal(
		calendar.computed.chosenRange.call({ range: 'week', calendarBlock: null }),
		'week',
	)
	assert.equal(
		calendar.computed.chosenRange.call({
			range: 'year',
			calendarBlock: { range: 'month' },
		}),
		'month',
	)
})

test("the record switcher opens only the visitor's own record on the authored page", async () => {
	const component = await loadSfc(
		'src/site/widgets/nlRecordSwitcher/NlRecordSwitcher.vue',
	)
	const emitted = []
	const self = {
		rows: [{ id: 'k1' }],
		route: '/kind',
		$emit: (...args) => emitted.push(args),
	}
	component.methods.choose.call(self, 'k1')
	component.methods.choose.call(self, 'iemand-anders')
	assert.deepEqual(emitted, [['navigate', '/kind/k1']])
})

test('the steps and files widgets render what an author typed, and nothing unsafe', async () => {
	const steps = await renderSfc('src/site/widgets/nlSteps/NlSteps.vue', {
		heading: 'Zo werkt het',
		steps: [
			{ label: 'Aanvraag', state: 'done' },
			{ label: 'Beoordeling', state: 'current' },
		],
	})
	assert.match(steps, /Zo werkt het/)
	assert.match(steps, /aria-current="step"/)
	assert.match(steps, /Beoordeling/)

	const files = await renderSfc('src/site/widgets/nlFileList/NlFileList.vue', {
		heading: 'Downloads',
		files: [
			{ label: 'Folder', href: 'https://example.org/folder.pdf' },
			{ label: 'Kwaad', href: 'javascript:alert(1)' },
		],
	})
	assert.match(files, /Folder/)
	assert.doesNotMatch(files, /Kwaad/)
})
