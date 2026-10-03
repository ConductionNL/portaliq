// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The site's shared form field layer (site-multi-step-forms wave 1, T1 and
// T2; REQ-SMF-001 to 003): "(niet verplicht)" inside the label of an optional
// field and aria-required on a required one; an error summary whose heading
// takes focus and whose links focus their field; a date asked as day, month
// and year that sends yyyy-mm-dd and refuses 31-2-2026. The published intake
// form and the landing page form run through the same layer.
//
// Usage:
//   node --test tests/site-form-fields.spec.mjs

import assert from 'node:assert/strict'
import { afterEach, test } from 'node:test'
import {
	dateParts,
	dateProblem,
	dateValue,
	explainsOptional,
	plainFieldErrors,
	summaryEntries,
} from '../src/site/components/forms/fields.js'
import { mountSfc } from './support/mount-sfc.mjs'

const SHELL = 'src/site/components/forms/FieldShell.vue'
const SUMMARY = 'src/site/components/forms/ErrorSummary.vue'
const DATE = 'src/site/components/forms/DateInputGroup.vue'
const INTAKE = 'src/site/components/IntakeFormBlock.vue'
const LANDING = 'src/site/components/FormBlock.vue'

const savedDocument = globalThis.document
const savedWindow = globalThis.window

afterEach(() => {
	globalThis.document = savedDocument
	globalThis.window = savedWindow
})

/**
 * A document whose `getElementById` searches a mounted tree, and whose title
 * can be read back.
 *
 * @param {object} mounted The mounted component.
 * @param {string} [title] The starting title.
 * @return {object} The fake document.
 */
function fakeDocument(mounted, title = 'Afwezig melden') {
	return {
		title,
		getElementById: (id) => mounted.findAll((n) => n.props.id === id)[0] || null,
	}
}

/**
 * Type a date into a date group's three boxes.
 *
 * @param {object} mounted The mounted component.
 * @param {string} id The Dag box's id.
 * @param {string[]} parts Day, month, year.
 * @return {Promise<void>}
 */
async function typeDate(mounted, id, [day, month, year]) {
	const box = (suffix) =>
		mounted.findAll((n) => n.props.id === `${id}${suffix}`)[0]
	await mounted.fire(box(''), 'input', { value: day })
	await mounted.fire(box('-month'), 'input', { value: month })
	await mounted.fire(box('-year'), 'input', { value: year })
}

test('a date group value: yyyy-mm-dd for a real date, the typed parts otherwise', () => {
	assert.equal(dateValue('1', '3', '2026'), '2026-03-01')
	assert.equal(dateValue(' 01 ', '03', '2026'), '2026-03-01')
	assert.equal(dateValue('29', '2', '2028'), '2028-02-29', 'a leap day')
	assert.equal(dateValue('', '', ''), '')
	assert.equal(dateValue('31', '2', '2026'), '31-2-2026', '31 February is no date')
	assert.equal(dateValue('29', '2', '2026'), '29-2-2026')
	assert.equal(dateValue('1', '13', '2026'), '1-13-2026')
	assert.equal(dateValue('1', '3', '26'), '1-3-26', 'a two-digit year is refused')
	assert.equal(dateValue('1', '', '2026'), '1--2026', 'incomplete')
	assert.equal(dateValue('a', '3', '2026'), 'a-3-2026')

	assert.deepEqual(dateParts('2026-03-01'), {
		day: '1',
		month: '3',
		year: '2026',
	})
	assert.deepEqual(dateParts('2026-03-01T10:00:00+00:00'), {
		day: '1',
		month: '3',
		year: '2026',
	})
	assert.deepEqual(dateParts('31-2-2026'), {
		day: '31',
		month: '2',
		year: '2026',
	})
	assert.deepEqual(dateParts(''), { day: '', month: '', year: '' })

	assert.equal(dateProblem(''), false)
	assert.equal(dateProblem('2026-03-01'), false)
	assert.equal(dateProblem('2026-02-31'), true)
	assert.equal(dateProblem('31-2-2026'), true)
})

test('the optional sentence shows only on a form that mixes required and optional', () => {
	assert.equal(explainsOptional([true, false]), true)
	assert.equal(explainsOptional([true, true]), false)
	assert.equal(explainsOptional([false, false]), false)
	assert.equal(explainsOptional([]), false)
})

test('summary entries follow the form order; an unknown field is kept, unlinked', () => {
	assert.deepEqual(
		summaryEntries(
			['a', 'b', 'c'],
			{ c: 'C is wrong', ghost: 'Server says no', a: 'A is wrong', b: '' },
			(field) => `id-${field}`,
		),
		[
			{ field: 'a', target: 'id-a', message: 'A is wrong' },
			{ field: 'c', target: 'id-c', message: 'C is wrong' },
			{ field: 'ghost', target: '', message: 'Server says no' },
		],
	)
})

test('plain field errors: a required field left empty and an impossible date', () => {
	const fields = [
		{ name: 'naam', label: 'Naam', required: true, date: false },
		{ name: 'van', label: 'Vanaf', required: false, date: true },
		{ name: 'toel', label: 'Toelichting', required: false, date: false },
	]
	assert.deepEqual(plainFieldErrors(fields, { naam: ' ', van: '31-2-2026' }), {
		naam: 'Naam is verplicht.',
		van: 'Vanaf: vul een geldige datum in, bijvoorbeeld 1 3 2026.',
	})
	assert.deepEqual(plainFieldErrors(fields, { naam: 'Sanne', van: '' }), {})
})

test('the suffix sits inside the label of an optional field, never on a required one', async () => {
	const optional = await mountSfc(SHELL, {
		id: 'uitleg',
		label: 'Wilt u iets toelichten?',
	})
	const label = optional.findAll((n) => n.tag === 'label')[0]
	assert.equal(label.props.for, 'uitleg')
	assert.equal(optional.textOf(label), 'Wilt u iets toelichten? (niet verplicht)')
	assert.ok(
		optional.findAll(
			(n) => n.props['data-testid'] === 'label-suffix' && n.parent === label,
		).length === 1,
		'the suffix is a child of the label',
	)

	const required = await mountSfc(SHELL, {
		id: 'naam',
		label: 'Naam',
		required: true,
	})
	assert.equal(required.find('label-suffix'), null)
	assert.doesNotMatch(required.text(), /\*/)

	const group = await mountSfc(SHELL, {
		id: 'van',
		label: 'Vanaf welke datum?',
		group: true,
		help: 'Bijvoorbeeld 1 3 2026',
		error: 'Vul een geldige datum in',
	})
	const fieldset = group.findAll((n) => n.tag === 'fieldset')[0]
	const legend = fieldset.children.find((n) => n.tag === 'legend')
	assert.equal(group.textOf(legend), 'Vanaf welke datum? (niet verplicht)')
	assert.equal(fieldset.props['aria-describedby'], 'van-help van-error')
})

test('the summary heading takes focus, each link focuses its field, the title says Fout', async () => {
	const summary = await mountSfc(SUMMARY, {
		entries: [
			{
				field: 'tot',
				target: 'f-tot',
				message: 'Kies de laatste dag dat Vera afwezig is',
			},
			{ field: 'server', target: '', message: 'Probeer het later opnieuw' },
		],
	})
	// The field the link points at, in the same fake tree.
	const target = { props: { id: 'f-tot' }, focus() {} }
	let focusedField = null
	target.focus = () => {
		focusedField = target
	}
	globalThis.document = {
		title: 'Afwezig melden',
		getElementById: (id) => (id === 'f-tot' ? target : null),
	}

	const heading = summary.find('error-summary-heading')
	assert.equal(summary.textOf(heading), 'Er ontbreekt nog iets')
	assert.equal(heading.props.tabindex, '-1')
	summary.vm.focus()
	assert.equal(summary.focused(), heading)

	const link = summary.find('error-summary-link-tot')
	assert.equal(link.tag, 'a')
	assert.equal(link.props.href, '#f-tot')
	assert.equal(summary.textOf(link), 'Kies de laatste dag dat Vera afwezig is')
	await summary.fire(link, 'click')
	assert.equal(focusedField, target, 'the link moved focus to the field')
	assert.equal(
		summary.find('error-summary-link-server'),
		null,
		'an error without a field is text, not a dead link',
	)
	assert.match(summary.text(), /Probeer het later opnieuw/)

	summary.vm.syncTitle()
	assert.equal(globalThis.document.title, 'Fout: Afwezig melden')
	summary.vm.syncTitle()
	assert.equal(globalThis.document.title, 'Fout: Afwezig melden', 'once')
})

test('the date group sends yyyy-mm-dd, keeps a bad date as typed, and shows a cleared value', async () => {
	const group = await mountSfc(DATE, { id: 'van', required: true })
	const boxes = group.findAll((n) => n.tag === 'input')
	assert.deepEqual(
		boxes.map((b) => [b.props.id, b.props.inputmode, b.props['aria-required']]),
		[
			['van', 'numeric', 'true'],
			['van-month', 'numeric', 'true'],
			['van-year', 'numeric', 'true'],
		],
	)
	assert.deepEqual(
		group
			.findAll((n) => n.tag === 'label')
			.map((l) => [l.props.for, group.textOf(l)]),
		[
			['van', 'Dag'],
			['van-month', 'Maand'],
			['van-year', 'Jaar'],
		],
	)

	await typeDate(group, 'van', ['1', '3', '2026'])
	assert.equal(group.emitted['update:modelValue'].at(-1)[0], '2026-03-01')

	await typeDate(group, 'van', ['31', '2', '2026'])
	assert.equal(group.emitted['update:modelValue'].at(-1)[0], '31-2-2026')
})

test('a published form marks optional fields, summarises a missed date and refuses 31-2-2026', async () => {
	const sent = []
	const render = {
		formName: 'Afwezig melden',
		fields: [
			{
				name: 'tot',
				label: 'Tot en met welke dag?',
				type: 'date',
				required: true,
			},
			{ name: 'uitleg', label: 'Wilt u iets toelichten?', type: 'textarea' },
			{ name: 'email', label: 'E-mailadres', type: 'email', required: true },
		],
	}
	globalThis.window = {
		location: { hash: '', pathname: '/site', search: '' },
		history: { replaceState() {} },
		sessionStorage: { getItem: () => null, setItem() {}, removeItem() {} },
		localStorage: { getItem: () => null, setItem() {}, removeItem() {} },
		fetch: async (url, init) => {
			if (String(url).includes('/intake/submit')) {
				sent.push(JSON.parse(init.body))
				return {
					ok: false,
					status: 400,
					json: async () => ({
						errors: {
							email: 'Dit e-mailadres kunnen wij niet gebruiken.',
						},
					}),
				}
			}
			return { ok: true, status: 200, json: async () => render }
		},
	}
	globalThis.document = {
		title: '',
		referrer: '',
		querySelector: () => null,
		getElementById: () => null,
		// runtime-dom reads this when vue is first imported under a document.
		createElement: () => ({}),
	}
	const form = await mountSfc(INTAKE, { route: 'afwezig', portal: 'school' })
	await form.flush()
	assert.equal(form.vm.state, 'form', 'the form loaded')
	globalThis.document = fakeDocument(form)

	assert.equal(
		form.textOf(form.find('intake-form-optional-note')),
		'Een veld zonder "niet verplicht" moet u invullen.',
	)
	assert.doesNotMatch(form.text(), /\*/, 'no asterisk')
	const label = form.findAll(
		(n) => n.tag === 'label' && n.props.for === 'pq-intake-field-uitleg',
	)[0]
	assert.equal(form.textOf(label), 'Wilt u iets toelichten? (niet verplicht)')
	const email = form.find('intake-field-email')
	assert.equal(email.props['aria-required'], 'true')
	assert.equal(email.props.required, undefined)

	// The date left empty: nothing is sent, the summary has focus.
	await form.fire(form.findAll((n) => n.tag === 'form')[0], 'submit')
	assert.equal(sent.length, 0)
	const heading = form.find('error-summary-heading')
	assert.equal(form.focused(), heading)
	const link = form.find('error-summary-link-tot')
	assert.equal(form.textOf(link), 'Tot en met welke dag? is verplicht.')
	await form.fire(link, 'click')
	assert.equal(
		form.focused(),
		form.find('intake-field-tot'),
		'the link lands in the Dag box',
	)
	assert.equal(form.find('intake-field-tot').props.id, 'pq-intake-field-tot')

	// 31 February is refused before it is sent.
	await typeDate(form, 'pq-intake-field-tot', ['31', '2', '2026'])
	await form.fire(form.find('intake-field-email'), 'input', {
		value: 'vera@example.org',
	})
	await form.fire(form.findAll((n) => n.tag === 'form')[0], 'submit')
	assert.equal(sent.length, 0)
	assert.match(
		form.textOf(form.find('error-summary-link-tot')),
		/vul een geldige datum in, bijvoorbeeld 1 3 2026/,
	)

	// A real date goes out as yyyy-mm-dd; the server's refusal lands in the summary.
	await typeDate(form, 'pq-intake-field-tot', ['2', '10', '2026'])
	await form.fire(form.findAll((n) => n.tag === 'form')[0], 'submit')
	assert.equal(sent.length, 1)
	assert.equal(sent[0].answers.tot, '2026-10-02')
	assert.equal(
		form.textOf(form.find('error-summary-link-email')),
		'Dit e-mailadres kunnen wij niet gebruiken.',
	)
	assert.equal(
		form.textOf(form.find('intake-field-error-email')),
		'Dit e-mailadres kunnen wij niet gebruiken.',
		'the message also stands under the field',
	)
	assert.equal(form.focused(), form.find('error-summary-heading'))
})

test('the landing page form marks optional fields and summarises a missed required one', async () => {
	globalThis.window = {
		location: { hash: '', pathname: '/site', search: '' },
		sessionStorage: { getItem: () => null, setItem() {}, removeItem() {} },
		localStorage: { getItem: () => null, setItem() {}, removeItem() {} },
	}
	globalThis.document = {
		referrer: '',
		title: '',
		getElementById: () => null,
		createElement: () => ({}),
	}
	const form = await mountSfc(LANDING, {
		formId: 'lead',
		portal: 'demo',
		fields: [
			{ id: 'name', label: 'Naam', type: 'text', required: true },
			{ id: 'phone', label: 'Telefoon', type: 'tel' },
		],
	})
	await form.flush()
	globalThis.document = fakeDocument(form)

	const formEl = form.find('site-form')
	assert.notEqual(
		formEl.props.novalidate,
		undefined,
		'the browser bubble stays out',
	)
	assert.equal(form.find('form-field-name').props['aria-required'], 'true')
	assert.equal(form.find('form-field-name').props.required, undefined)
	assert.match(form.text(), /Telefoon \(niet verplicht\)/)
	assert.doesNotMatch(form.text(), /\*/)

	await form.fire(formEl, 'submit')
	assert.equal(form.focused(), form.find('error-summary-heading'))
	assert.equal(
		form.textOf(form.find('error-summary-link-name')),
		'Naam is verplicht.',
	)
	assert.equal(form.find('form-status-success'), null, 'nothing was sent')
})
