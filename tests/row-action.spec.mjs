#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// row-action.spec.mjs: a guardian pays a school contribution from its row
// (contribution-pay-screen). Which row offers the button, what the confirm
// step shows, where the browser may go afterwards and which message each
// answer gets.
//
// Usage:
//   node --test tests/row-action.spec.mjs
//
// The manifest is shillinq's parent manifest once it declares rowField and
// rowWhen. The JSX components are compiled with the preset webpack.portal.js
// uses and written next to a .mjs copy of the module, where their relative
// imports point.

import assert from 'node:assert/strict'
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath, pathToFileURL } from 'node:url'
import { compileLoading, LOADING_MODULE } from './support/compile-loading.mjs'
import { mountSfc } from './support/mount-sfc.mjs'

const require = createRequire(import.meta.url)
const babel = require('@babel/core')
const React = require('react')
const { renderToStaticMarkup } = require('react-dom/server')

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const OUT_DIR = join(ROOT, 'node_modules', '.cache', 'portaliq-tests', 'row-action')
mkdirSync(OUT_DIR, { recursive: true })

writeFileSync(
	join(OUT_DIR, 'rowAction.mjs'),
	readFileSync(join(ROOT, 'src', 'shared', 'rowAction.js'), 'utf8'),
)

/**
 * Compile one component into the cache, pointing its imports at the cache.
 *
 * @param {string} name The component file name without extension.
 * @return {string} The compiled file's path.
 */
function compile(name) {
	const source = join(ROOT, 'src', 'portal', 'components', `${name}.jsx`)
	const code = babel
		.transformSync(readFileSync(source, 'utf8'), {
			filename: source,
			babelrc: false,
			configFile: false,
			presets: [['@babel/preset-react', { runtime: 'automatic' }]],
		})
		.code.replace("'../../shared/rowAction.js'", "'./rowAction.mjs'")
		.replace("'./Loading.jsx'", `'${LOADING_MODULE}'`)
	const out = join(OUT_DIR, `${name}.mjs`)
	writeFileSync(out, code)
	return out
}

compileLoading(OUT_DIR)
const { default: CollectionTable } = await import(
	pathToFileURL(compile('CollectionTable')).href
)
const { default: RowActionConfirm } = await import(
	pathToFileURL(compile('RowActionConfirm')).href
)
const rowAction = await import(pathToFileURL(join(OUT_DIR, 'rowAction.mjs')).href)

const t = (key) => `[${key}]`

const pay = {
	id: 'pay',
	label: 'Pay now',
	type: 'endpoint-forward',
	endpoint: '/apps/shillinq/api/portal/payments/initiate',
	method: 'POST',
	rowField: 'invoiceId',
	rowWhen: { field: 'state', in: ['issued', 'partially-paid', 'overdue'] },
}
const close = {
	id: 'close',
	label: 'Close',
	type: 'update',
	set: { status: 'closed' },
}

const salesInvoices = {
	id: 'salesInvoices',
	register: 'shillinq',
	schema: 'ARInvoice',
	noticeField: 'invoiceNote',
	columns: [
		{ field: 'invoiceNumber', label: 'Invoice' },
		{ field: 'state', label: 'Status', render: 'badge' },
	],
}

const VOLUNTARY =
	'This contribution is voluntary. Your child takes part whether you pay or not.'
const issued = {
	id: 'inv-1',
	invoiceNumber: 'CTB-2026-0001',
	state: 'issued',
	invoiceNote: VOLUNTARY,
}
const paid = { id: 'inv-2', invoiceNumber: 'CTB-2026-0002', state: 'paid' }

test('an endpoint row action is told apart from an update transition', () => {
	assert.equal(rowAction.isEndpointRowAction(pay), true)
	assert.equal(rowAction.isEndpointRowAction(close), false)
	assert.equal(
		rowAction.isEndpointRowAction({ ...pay, rowField: undefined }),
		false,
	)
	assert.equal(rowAction.isEndpointRowAction(null), false)
})

test('offersRowAction follows rowWhen and nothing else', () => {
	assert.equal(rowAction.offersRowAction(pay, issued), true)
	assert.equal(rowAction.offersRowAction(pay, { state: 'overdue' }), true)
	assert.equal(rowAction.offersRowAction(pay, paid), false)
	assert.equal(rowAction.offersRowAction(pay, {}), false)
	assert.equal(
		rowAction.offersRowAction({ ...pay, rowWhen: undefined }, paid),
		true,
	)
	assert.equal(rowAction.offersRowAction(close, paid), true)
})

test('the table shows the pay button only on the rows that can still be paid', () => {
	const html = renderToStaticMarkup(
		React.createElement(CollectionTable, {
			collection: salesInvoices,
			objects: [issued, paid],
			rowActions: [pay],
			offers: rowAction.offersRowAction,
			onRowAction: () => {},
		}),
	)
	const rows = html.split('<tr').slice(2)
	assert.equal(rows.length, 2)
	assert.match(rows[0], />Pay now</)
	assert.doesNotMatch(rows[1], />Pay now</)
})

test('the table without offers keeps showing every action on every row', () => {
	const html = renderToStaticMarkup(
		React.createElement(CollectionTable, {
			collection: salesInvoices,
			objects: [issued, paid],
			rowActions: [close],
			onRowAction: () => {},
		}),
	)
	assert.equal(html.split('>Close</button>').length - 1, 2)
})

test('the confirm step shows the notice on a voluntary contribution, and none otherwise', () => {
	const render = (row) =>
		renderToStaticMarkup(
			React.createElement(RowActionConfirm, {
				action: pay,
				collection: salesInvoices,
				row,
				api: {},
				t,
			}),
		)

	const voluntary = render(issued)
	assert.match(voluntary, /<h4[^>]*>Pay now<\/h4>/)
	assert.equal(voluntary.split(VOLUNTARY).length - 1, 1)
	assert.match(voluntary, /class="portaliq-notice"/)
	assert.match(voluntary, />\[Continue\]</)
	assert.match(voluntary, />\[Cancel\]</)
	assert.match(voluntary, /role="status"/)

	const plain = render({ ...issued, invoiceNote: undefined })
	assert.doesNotMatch(plain, /portaliq-notice/)
})

test('redirectTarget follows only an https URL from a 2xx answer', () => {
	const ok = (body) => ({ ok: true, status: 200, body })
	assert.equal(
		rowAction.redirectTarget(
			ok({ checkoutUrl: 'https://pay.example.nl/checkout/abc' }),
		),
		'https://pay.example.nl/checkout/abc',
	)
	assert.equal(
		rowAction.redirectTarget(ok({ redirectUrl: 'https://sign.example.nl/s/1' })),
		'https://sign.example.nl/s/1',
	)
	assert.equal(
		rowAction.redirectTarget({
			ok: false,
			status: 403,
			body: { checkoutUrl: 'https://pay.example.nl' },
		}),
		null,
	)
})

test('redirectTarget refuses anything but https', () => {
	const ok = (body) => ({ ok: true, status: 200, body })
	for (const url of [
		'javascript:alert(1)',
		'http://pay.example.nl/checkout',
		'/portal/elsewhere',
		'data:text/html,hi',
		'not a url',
		'',
		42,
	]) {
		assert.equal(
			rowAction.redirectTarget(ok({ checkoutUrl: url })),
			null,
			String(url),
		)
	}
	assert.equal(rowAction.redirectTarget(ok({})), null)
	assert.equal(rowAction.redirectTarget(null), null)
})

test('outcome maps each status to one message', () => {
	assert.equal(rowAction.outcomeKey({ ok: true, status: 200, body: {} }), 'Done.')
	assert.equal(
		rowAction.outcomeKey({
			ok: true,
			status: 200,
			body: { checkoutUrl: 'http://pay.example.nl' },
		}),
		'The next page could not be opened.',
	)
	for (const status of [403, 404, 409]) {
		assert.equal(
			rowAction.outcomeKey({ ok: false, status, body: {} }),
			'This can no longer be done for this item.',
		)
	}
	for (const status of [502, 503, 0]) {
		assert.equal(
			rowAction.outcomeKey({
				ok: false,
				status,
				body: { status: 'deferred' },
			}),
			'This is not available right now. Try again later.',
		)
	}
})

test('running the action sends only the row and the action, then goes to the checkout', async () => {
	const calls = []
	const api = {
		async forwardRowAction(collection, rowId, actionId) {
			calls.push({ collection: collection.id, rowId, actionId })
			return {
				ok: true,
				status: 200,
				body: { checkoutUrl: 'https://pay.example.nl/checkout/abc' },
			}
		},
	}
	const outcome = await rowAction.runRowAction(api, salesInvoices, issued, pay)
	assert.deepEqual(calls, [
		{ collection: 'salesInvoices', rowId: 'inv-1', actionId: 'pay' },
	])
	assert.deepEqual(outcome, {
		redirect: 'https://pay.example.nl/checkout/abc',
		messageKey: '',
	})
})

test('running the action when online payment is off stays on the portal with a message', async () => {
	const api = {
		forwardRowAction: async () => ({
			ok: false,
			status: 503,
			body: { status: 'deferred' },
		}),
	}
	assert.deepEqual(await rowAction.runRowAction(api, salesInvoices, issued, pay), {
		redirect: null,
		messageKey: 'This is not available right now. Try again later.',
	})
	assert.deepEqual(await rowAction.runRowAction(api, salesInvoices, {}, pay), {
		redirect: null,
		messageKey: 'This is not available right now. Try again later.',
	})
})

test('rowNotice reads the declared field only', () => {
	assert.equal(rowAction.rowNotice(salesInvoices, issued), VOLUNTARY)
	assert.equal(rowAction.rowNotice(salesInvoices, paid), '')
	assert.equal(rowAction.rowNotice({ id: 'x' }, issued), '')
	assert.equal(rowAction.rowNotice(salesInvoices, { invoiceNote: 12 }), '')
})

test('a page-level action shows the leaf app answer instead of discarding it (#804)', async () => {
	const calls = []
	const refusing = {
		async forwardAction(app, actionId, body) {
			calls.push({ app, actionId, body })
			return { ok: false, status: 403, body: { error: 'forbidden' } }
		},
	}
	assert.deepEqual(await rowAction.runAction(refusing, 'filinq', { id: 'sign' }), {
		redirect: null,
		messageKey: 'This can no longer be done for this item.',
	})
	assert.deepEqual(calls, [{ app: 'filinq', actionId: 'sign', body: {} }])

	const done = { forwardAction: async () => ({ ok: true, status: 200, body: {} }) }
	assert.deepEqual(await rowAction.runAction(done, 'filinq', { id: 'sign' }), {
		redirect: null,
		messageKey: 'Done.',
	})
	assert.deepEqual(await rowAction.runAction(done, '', { id: 'sign' }), {
		redirect: null,
		messageKey: 'This is not available right now. Try again later.',
	})
})

// The site's Vue port (site-reaches-portal-parity T12 and T13, REQ-SRP-025,
// REQ-SRP-026, REQ-SRP-027) on the same shared src/shared/rowAction.js.

/**
 * A fake portal api that records every forward.
 *
 * @param {object} answer What every forward answers.
 * @return {object} The api with `calls`.
 */
function forwardingApi(answer) {
	const calls = []
	return {
		calls,
		// The real api sends `{}` when no answers are given.
		async forwardRowAction(collection, rowId, actionId, answers = {}) {
			calls.push({ collection: collection.id, rowId, actionId, answers })
			return answer
		},
		async forwardAction(app, actionId, body) {
			calls.push({ app, actionId, body })
			return answer
		},
		async updateObject(action, id, body) {
			calls.push({ update: action.id, id, body })
			return { ok: true }
		},
	}
}

test('the site confirm step shows the notice and sends nothing until Continue', async () => {
	const api = forwardingApi({ ok: true, status: 200, body: {} })
	const step = await mountSfc('src/site/modals/c/RowActionConfirm.vue', {
		action: pay,
		collection: salesInvoices,
		row: issued,
		api,
		t,
	})

	assert.match(step.text(), new RegExp(`Pay now ${VOLUNTARY}`))
	assert.equal(
		step.textOf(step.focused()),
		'Pay now',
		'focus moves to what the resident confirms',
	)
	assert.equal(api.calls.length, 0)
	await step.fire(step.find('rowaction-continue'), 'click')
	assert.deepEqual(api.calls, [
		{
			collection: 'salesInvoices',
			rowId: 'inv-1',
			actionId: 'pay',
			answers: {},
		},
	])
	assert.equal(step.textOf(step.find('rowaction-status')), '[Done.]')
	assert.equal(step.emitted.done.length, 1)
	assert.equal(step.find('rowaction-continue'), null, 'the step cannot run twice')
})

test('the site confirm step follows a checked redirect and never an unsafe one', async () => {
	const went = []
	const safe = forwardingApi({
		ok: true,
		status: 200,
		body: { checkoutUrl: 'https://pay.example/checkout/1' },
	})
	const step = await mountSfc('src/site/modals/c/RowActionConfirm.vue', {
		action: pay,
		collection: salesInvoices,
		row: issued,
		api: safe,
		t,
		navigate: (url) => went.push(url),
	})
	await step.fire(step.find('rowaction-continue'), 'click')
	assert.deepEqual(went, ['https://pay.example/checkout/1'])

	const unsafe = forwardingApi({
		ok: true,
		status: 200,
		body: { redirectUrl: 'javascript:alert(1)' },
	})
	const other = await mountSfc('src/site/modals/c/RowActionConfirm.vue', {
		action: pay,
		collection: salesInvoices,
		row: issued,
		api: unsafe,
		t,
		navigate: (url) => went.push(url),
	})
	await other.fire(other.find('rowaction-continue'), 'click')
	assert.equal(went.length, 1)
	assert.equal(
		other.textOf(other.find('rowaction-status')),
		'[The next page could not be opened.]',
	)
})

test('an endpoint or cta action on a site page shows the leaf answer in a status region', async () => {
	const api = forwardingApi({ ok: false, status: 409, body: {} })
	const button = await mountSfc('src/site/components/c/ActionButton.vue', {
		action: { id: 'startTask', label: 'Start', endpoint: '/x' },
		app: 'learniq',
		api,
		t,
	})
	await button.fire(button.findAll((n) => n.tag === 'button')[0], 'click')

	assert.deepEqual(api.calls, [
		{ app: 'learniq', actionId: 'startTask', body: {} },
	])
	const status = button.find('action-status-startTask')
	assert.equal(status.props.role, 'status')
	assert.equal(
		button.textOf(status),
		'[This can no longer be done for this item.]',
	)
})

test('an action block renders a create action as a form and a cta as a labelled button', async () => {
	const contribution = {
		app: 'learniq',
		actions: [
			{
				id: 'createNote',
				type: 'create',
				label: 'Write a note',
				fields: ['title'],
				fieldConfigs: {},
			},
			{ id: 'startTask', type: 'endpoint', label: 'Start', endpoint: '/x' },
			{ id: 'noEndpoint', type: 'endpoint', label: 'Broken' },
		],
	}
	const api = forwardingApi({ ok: true, status: 200, body: {} })
	const form = await mountSfc('src/site/components/c/ActionBlock.vue', {
		block: { type: 'action', action: 'createNote' },
		contribution,
		api,
		t,
	})
	await form.flush()
	assert.ok(form.find('schema-form'))
	assert.match(form.text(), /^Write a note/)

	const cta = await mountSfc('src/site/components/c/ActionBlock.vue', {
		block: { type: 'cta', action: 'startTask', label: 'Begin de toets' },
		contribution,
		api,
		t,
	})
	assert.equal(cta.text(), 'Begin de toets')

	const broken = await mountSfc('src/site/components/c/ActionBlock.vue', {
		block: { type: 'action', action: 'noEndpoint' },
		contribution,
		api,
		t,
	})
	assert.equal(broken.findAll((n) => n.tag === 'button')[0].props.disabled, true)

	const unknown = await mountSfc('src/site/components/c/ActionBlock.vue', {
		block: { type: 'action', action: 'ghost' },
		contribution,
		api,
		t,
	})
	assert.equal(
		unknown.text(),
		'',
		'a block naming an undeclared action renders nothing',
	)
})

test('a status transition sends no field data', async () => {
	const { runRowTransition } = await import('../src/site/components/c/forms.js')
	const api = forwardingApi({ ok: true })
	assert.deepEqual(
		await runRowTransition(api, close, { '@self': { id: 'req-9' } }),
		{ ok: true },
	)
	assert.deepEqual(api.calls, [{ update: 'close', id: 'req-9', body: {} }])
	assert.deepEqual(await runRowTransition(api, close, {}), { ok: false })
})

test('the row action step picks the confirm step for a plain endpoint action', async () => {
	const api = forwardingApi({ ok: true, status: 200, body: {} })
	const step = await mountSfc('src/site/components/c/RowActionDialog.vue', {
		action: pay,
		collection: salesInvoices,
		row: issued,
		api,
		t,
	})
	await step.flush()
	assert.ok(step.find('rowaction-confirm'))
})
