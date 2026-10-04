// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// site-multi-step-forms wave 3 (T6, T7, T7b; REQ-SMF-010, -011, -020, -022):
// a form in steps shows one step at a time with a progress list, checks
// only the step's fields (required fields included) on "Volgende stap",
// never on "Vorige stap", skips a step whose fields are all hidden, ends
// with a review whose "Wijzigen" returns to the review, and shows the
// action's confirmation with its placeholders filled. The same flow runs
// for a create action, an attached endpoint action and a published form.
//
// Mounted elements are compared with `assert.ok(a === b)`, never
// `assert.equal(a, b)`: a failing `equal` renders the whole app (see
// tests/site-form-fields.spec.mjs).
//
// Usage:
//   node --test tests/site-form-steps.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { afterEach, test } from 'node:test'
import {
	confirmationText,
	flowSteps,
	resumeStep,
	retentionSentence,
	stepErrors,
	stepHeading,
	stepTo,
} from '../src/site/components/forms/steps.js'
import { mountSfc } from './support/mount-sfc.mjs'

const NL = JSON.parse(
	readFileSync(new URL('../src/shared/i18n/nl.json', import.meta.url), 'utf8'),
)

/**
 * The site's Dutch translator, as the shell hands it to a page.
 *
 * @param {string} key The English source string.
 * @param {object} [vars] Placeholder values.
 * @return {string} The Dutch text.
 */
function t(key, vars = {}) {
	let text = NL[key] ?? key
	for (const [name, value] of Object.entries(vars)) {
		text = text.split(`{${name}}`).join(String(value))
	}
	return text
}

const FORM = 'src/site/components/c/SchemaForm.vue'
const ATTACHED = 'src/site/components/c/AttachedActions.vue'
const INTAKE = 'src/site/components/IntakeFormBlock.vue'

const savedDocument = globalThis.document
const savedWindow = globalThis.window

afterEach(() => {
	globalThis.document = savedDocument
	globalThis.window = savedWindow
})

/**
 * dossiq's Woo request as normalised: four steps, `onderwerp` and
 * `periodeVan` required (requiredFields), a hidden `collectionId`.
 *
 * @param {object} [overrides] Keys to replace.
 * @return {object} The action.
 */
function wooAction(overrides = {}) {
	return {
		id: 'startWooVerzoek',
		label: 'Woo-verzoek',
		fields: ['onderwerp', 'omschrijving', 'periodeVan', 'verzoekerNaam'],
		fieldConfigs: {
			onderwerp: { label: 'Waar gaat uw verzoek over?', required: true },
			omschrijving: {
				label: 'Welke informatie wilt u hebben?',
				size: 'large',
			},
			periodeVan: {
				label: 'Vanaf welke datum zoekt u informatie?',
				required: true,
				input: 'date',
			},
			verzoekerNaam: { label: 'Uw naam' },
		},
		steps: [
			{
				id: 'vraag',
				title: 'Uw vraag',
				fields: ['onderwerp', 'omschrijving'],
			},
			{
				id: 'periode',
				title: 'Periode en documenten',
				fields: ['periodeVan'],
			},
			{ id: 'gegevens', title: 'Uw gegevens', fields: ['verzoekerNaam'] },
			{
				id: 'controle',
				title: 'Controleren en versturen',
				fields: [],
				review: true,
			},
		],
		confirmation: {
			title: 'Wij hebben uw verzoek ontvangen',
			body: 'Uw zaaknummer is {identifier}. U krijgt uiterlijk {deadline} antwoord.',
		},
		submitLabel: 'Verzoek versturen',
		...overrides,
	}
}

/**
 * Type into a field by its element id.
 *
 * @param {object} form The mounted form.
 * @param {string} id The element id.
 * @param {string} value The value.
 * @return {Promise<void>}
 */
async function type(form, id, value) {
	const el = form.findAll((n) => n.props.id === id)[0]
	await form.fire(el, el.tag === 'select' ? 'change' : 'input', { value })
}

/**
 * Press the form's submit ("Volgende stap" or send).
 *
 * @param {object} form The mounted form.
 * @param {string} testid The form's testid.
 * @return {Promise<void>}
 */
async function press(form, testid = 'schema-form') {
	await form.fire(form.find(testid), 'submit')
}

/**
 * The link texts of the error summary.
 *
 * @param {object} form The mounted form.
 * @return {string[]} The texts.
 */
function summaryLinks(form) {
	return form
		.findAll((n) =>
			String(n.props['data-testid'] || '').startsWith('error-summary-link-'),
		)
		.map((a) => a.props['data-testid'].replace('error-summary-link-', ''))
}

test('the step helpers: a review last, skipping hidden steps, the heading, a confirmation without a missing value', () => {
	const flow = flowSteps(
		[
			{ id: 'a', title: 'Uw vraag', fields: ['x'] },
			{ id: 'more', title: '', fields: ['y'] },
		],
		{ other: 'Overige vragen', review: 'Controleren en versturen' },
	)
	assert.deepEqual(
		flow.map((s) => [s.id, s.title, s.review]),
		[
			['a', 'Uw vraag', false],
			['more', 'Overige vragen', false],
			['review', 'Controleren en versturen', true],
		],
	)
	assert.deepEqual(flowSteps([], { other: '', review: '' }), [])

	const shown = (field) => field !== 'y'
	assert.equal(
		stepTo(flow, 0, 1, shown),
		2,
		'the all-hidden step is skipped forward',
	)
	assert.equal(stepTo(flow, 2, -1, shown), 0, 'and back')
	assert.equal(stepTo(flow, 0, -1, shown), 0, 'nothing before the first step')

	assert.equal(
		stepHeading('Stap {n} van {m}: {title}', 2, 4, 'Periode en documenten'),
		'Stap 2 van 4: periode en documenten',
	)
	assert.equal(
		stepHeading('Stap {n} van {m}: {title}', 1, 4, 'Woo-verzoek'),
		'Stap 1 van 4: woo-verzoek',
	)
	assert.equal(
		stepHeading('Step {n} of {m}: {title}', 1, 2, 'KVK'),
		'Step 1 of 2: KVK',
	)

	assert.deepEqual(stepErrors({ x: 'X', y: 'Y' }, flow[0]), { x: 'X' })

	assert.equal(
		confirmationText(
			'Uw zaaknummer is {identifier}. U krijgt uiterlijk {deadline} antwoord.',
			{ identifier: '2026-0003' },
		),
		'Uw zaaknummer is 2026-0003.',
	)
	assert.equal(confirmationText('Bedankt.', null), 'Bedankt.')
	assert.equal(confirmationText(undefined, {}), '')
})

test('a Woo request in steps: progress, per-step checks, the review round trip and the confirmation', async () => {
	globalThis.document = {
		title: 'Woo',
		getElementById: () => null,
		createElement: () => ({}),
		documentElement: { lang: 'nl' },
	}
	const sent = []
	const send = async (body) => {
		sent.push(body)
		return { ok: true, object: { identifier: '2026-0003' } }
	}
	const form = await mountSfc(FORM, {
		action: wooAction(),
		api: { fetchOptions: async () => [] },
		send,
		t,
	})
	await form.flush()

	assert.equal(
		form.textOf(form.find('schema-form-step-heading')),
		'Stap 1 van 4: uw vraag',
	)
	assert.equal(form.find('form-progress-step-0').props['aria-current'], 'step')
	assert.equal(
		form.findAll((n) =>
			String(n.props['data-testid'] || '').startsWith('form-progress-step-'),
		).length,
		4,
	)
	assert.equal(
		form.textOf(form.find('form-progress-short')),
		'Stap 1 van 4',
		'the short progress',
	)
	assert.ok(
		form.find('schema-form-previous') === null,
		'no way back from the first step',
	)
	assert.ok(
		form.findAll((n) => n.props.id === 'f-startWooVerzoek-periodeVan').length
			=== 0,
		'one step at a time',
	)

	// "Volgende stap" with the required subject empty: the summary names only this step's field.
	await press(form)
	assert.deepEqual(summaryLinks(form), ['onderwerp'])
	assert.ok(form.focused() === form.find('error-summary-heading'))
	assert.equal(
		form.textOf(form.find('schema-form-step-heading')),
		'Stap 1 van 4: uw vraag',
		'the step stays',
	)

	await type(form, 'f-startWooVerzoek-onderwerp', 'De nieuwe brug')
	await press(form)
	const heading = form.find('schema-form-step-heading')
	assert.equal(form.textOf(heading), 'Stap 2 van 4: periode en documenten')
	assert.ok(form.focused() === heading, 'focus on the step heading')
	assert.equal(form.find('form-progress-step-1').props['aria-current'], 'step')
	assert.match(form.textOf(form.find('form-progress-step-0')), /Klaar/)
	assert.ok(form.find('error-summary') === null, 'the summary is gone')

	// The required start date holds per step too.
	await press(form)
	assert.deepEqual(summaryLinks(form), ['periodeVan'])

	// "Vorige stap" never checks.
	await form.fire(form.find('schema-form-previous'), 'click')
	assert.equal(
		form.textOf(form.find('schema-form-step-heading')),
		'Stap 1 van 4: uw vraag',
	)
	assert.ok(form.find('error-summary') === null)
	await press(form)

	await type(form, 'f-startWooVerzoek-periodeVan', '1')
	await type(form, 'f-startWooVerzoek-periodeVan-month', '3')
	await type(form, 'f-startWooVerzoek-periodeVan-year', '2026')
	await press(form)
	assert.equal(
		form.textOf(form.find('schema-form-step-heading')),
		'Stap 3 van 4: uw gegevens',
	)
	await press(form)

	// The review: every answer under its question, per step.
	assert.equal(
		form.textOf(form.find('schema-form-step-heading')),
		'Stap 4 van 4: controleren en versturen',
	)
	assert.equal(form.textOf(form.find('review-answer-onderwerp')), 'De nieuwe brug')
	assert.equal(form.textOf(form.find('review-answer-periodeVan')), '1 maart 2026')
	assert.equal(
		form.textOf(form.find('review-answer-verzoekerNaam')),
		'Niet ingevuld',
	)
	assert.equal(form.find('review-edit-1').props['aria-label'], 'Stap 2 wijzigen')

	// "Stap 2 wijzigen", change the date, "Volgende stap": back on the review.
	await form.fire(form.find('review-edit-1'), 'click')
	assert.equal(
		form.textOf(form.find('schema-form-step-heading')),
		'Stap 2 van 4: periode en documenten',
	)
	await type(form, 'f-startWooVerzoek-periodeVan', '2')
	await press(form)
	assert.equal(form.textOf(form.find('review-answer-periodeVan')), '2 maart 2026')

	await press(form)
	assert.equal(sent.length, 1)
	assert.deepEqual(sent[0], {
		onderwerp: 'De nieuwe brug',
		omschrijving: '',
		periodeVan: '2026-03-02',
		verzoekerNaam: '',
	})
	const confirmation = form.find('schema-form-confirmation-heading')
	assert.equal(form.textOf(confirmation), 'Wij hebben uw verzoek ontvangen')
	assert.ok(form.focused() === confirmation, 'focus on the confirmation heading')
	assert.equal(
		form.textOf(form.find('schema-form-confirmation-body')),
		'Uw zaaknummer is 2026-0003.',
		'the sentence without a deadline is left out',
	)
	assert.ok(
		form.find('schema-form') === null,
		'the confirmation replaces the form',
	)
})

test('a step whose fields are all hidden is skipped, and a refusal on send opens the step that holds it', async () => {
	globalThis.document = {
		title: 'Woo',
		getElementById: () => null,
		createElement: () => ({}),
		documentElement: { lang: 'nl' },
	}
	const action = wooAction({
		fields: ['collectionId', 'onderwerp'],
		fieldConfigs: {
			collectionId: { visible: false },
			onderwerp: { label: 'Waar gaat uw verzoek over?' },
		},
		steps: [
			{ id: 'dossier', title: 'Dossier', fields: ['collectionId'] },
			{ id: 'vraag', title: 'Uw vraag', fields: ['onderwerp'] },
		],
	})
	const send = async () => ({
		ok: false,
		object: null,
		errors: { onderwerp: 'Vertel waar uw verzoek over gaat' },
	})
	const form = await mountSfc(FORM, {
		action,
		api: { fetchOptions: async () => [] },
		send,
		t,
	})
	await form.flush()

	// The first step holds only a hidden field: it shows nothing, Volgende skips past it both ways.
	await press(form)
	assert.equal(
		form.textOf(form.find('schema-form-step-heading')),
		'Stap 2 van 3: uw vraag',
	)
	await press(form)
	assert.equal(
		form.textOf(form.find('schema-form-step-heading')),
		'Stap 3 van 3: controleren en versturen',
	)
	await form.fire(form.find('schema-form-previous'), 'click')
	await form.fire(form.find('schema-form-previous'), 'click')
	assert.equal(
		form.textOf(form.find('schema-form-step-heading')),
		'Stap 2 van 3: uw vraag',
		'the hidden step is skipped back too',
	)

	await press(form)
	await press(form)
	assert.equal(
		form.textOf(form.find('schema-form-step-heading')),
		'Stap 2 van 3: uw vraag',
		'the refusal opens the step',
	)
	assert.deepEqual(summaryLinks(form), ['onderwerp'])
	assert.equal(
		form.textOf(form.find('error-summary-link-onderwerp')),
		'Vertel waar uw verzoek over gaat',
	)
})

test('an attached Woo action runs the same steps and forwards through the row action', async () => {
	globalThis.document = {
		title: 'Dossier',
		getElementById: () => null,
		createElement: () => ({}),
		documentElement: { lang: 'nl' },
	}
	const calls = []
	const api = {
		fetchOptions: async () => [],
		forwardRowAction: async (...args) => {
			calls.push(args)
			return { ok: true, status: 201, body: { identifier: '2026-0004' } }
		},
	}
	const action = wooAction({
		app: 'dossiq',
		fields: ['onderwerp'],
		fieldConfigs: {
			onderwerp: { label: 'Waar gaat uw verzoek over?', required: true },
		},
		steps: [{ id: 'vraag', title: 'Uw vraag', fields: ['onderwerp'] }],
	})
	const block = await mountSfc(ATTACHED, {
		collection: {
			id: 'mijnDossiers',
			register: 'opencatalogi',
			schema: 'collection',
			attachedActions: [action],
		},
		row: { id: 'dos-1' },
		api,
		t,
	})
	await block.fire(block.find('attached-action-startWooVerzoek'), 'click')
	assert.ok(block.find('attached-action-steps') !== null)
	assert.equal(
		block.textOf(block.find('schema-form-step-heading')),
		'Stap 1 van 2: uw vraag',
	)

	await type(block, 'f-startWooVerzoek-onderwerp', 'De brug')
	await press(block)
	await press(block)
	assert.equal(calls.length, 1)
	assert.equal(calls[0][1], 'dos-1')
	assert.equal(calls[0][2], 'startWooVerzoek')
	assert.equal(calls[0][3].onderwerp, 'De brug')
	assert.equal(
		block.textOf(block.find('schema-form-confirmation-body')),
		'Uw zaaknummer is 2026-0004.',
	)
})

test('a published form in steps: the review, then the confirmation heading takes focus', async () => {
	const sent = []
	const render = {
		formName: 'Afval melden',
		fields: [
			{ name: 'adres', label: 'Waar ligt het?', required: true },
			{ name: 'soort', label: 'Wat ligt er?' },
		],
		steps: [
			{ id: 'waar', title: 'Waar', fields: ['adres'] },
			{ id: 'wat', title: 'Wat', fields: ['soort'] },
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
					ok: true,
					status: 200,
					json: async () => ({
						reference: 'MLD-7',
						confirmationText: 'Wij halen het op.',
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
		createElement: () => ({}),
	}
	const form = await mountSfc(INTAKE, { route: 'afval', portal: 'gemeente' })
	await form.flush()
	const formEl = () => form.findAll((n) => n.tag === 'form')[0]

	assert.equal(
		form.textOf(form.find('intake-form-step-heading')),
		'Stap 1 van 3: waar',
	)
	await form.fire(formEl(), 'submit')
	assert.deepEqual(summaryLinks(form), ['adres'])
	await form.fire(form.find('intake-field-adres'), 'input', {
		value: 'Dorpsstraat 1',
	})
	await form.fire(formEl(), 'submit')
	assert.equal(
		form.textOf(form.find('intake-form-step-heading')),
		'Stap 2 van 3: wat',
	)
	await form.fire(form.find('intake-field-soort'), 'input', { value: 'Grofvuil' })
	await form.fire(formEl(), 'submit')
	assert.equal(form.textOf(form.find('review-answer-soort')), 'Grofvuil')
	assert.equal(form.find('review-edit-0').props['aria-label'], 'Stap 1 wijzigen')

	await form.fire(formEl(), 'submit')
	assert.equal(sent.length, 1)
	assert.deepEqual(sent[0].answers, { adres: 'Dorpsstraat 1', soort: 'Grofvuil' })
	const done = form.find('intake-form-done-heading')
	assert.ok(form.focused() === done, 'focus on the confirmation heading')
	assert.match(
		form.textOf(form.find('intake-form-done')),
		/Wij halen het op\. Uw kenmerk: MLD-7/,
	)
})

test('the draft helpers: where a resumed draft opens, and the retention sentence', () => {
	const flow = flowSteps(
		[
			{ id: 'vraag', title: 'Uw vraag', fields: ['onderwerp'] },
			{
				id: 'periode',
				title: 'Periode',
				fields: ['periodeVan', 'periodeTot'],
			},
			{ id: 'gegevens', title: 'Uw gegevens', fields: ['naam'] },
		],
		{ other: 'Overige vragen', review: 'Controleren en versturen' },
	)
	const required = (field) => field !== 'periodeTot'

	assert.equal(
		resumeStep(
			flow,
			{ onderwerp: 'Brug', periodeVan: '2026-03-01', naam: '' },
			required,
		),
		2,
		'the first step with a gap',
	)
	assert.equal(
		resumeStep(
			flow,
			{ onderwerp: 'Brug', periodeVan: '', naam: 'Sanne' },
			required,
		),
		1,
	)
	assert.equal(
		resumeStep(
			flow,
			{ onderwerp: 'Brug', periodeVan: '2026-03-01', naam: 'Sanne' },
			required,
		),
		3,
		'nothing missing: the review',
	)
	assert.equal(
		resumeStep(
			flow,
			{
				onderwerp: 'Brug',
				periodeVan: '2026-03-01',
				periodeTot: '',
				naam: 'Sanne',
			},
			required,
		),
		3,
		'an empty optional answer is not a gap',
	)

	assert.equal(
		retentionSentence(
			'Uw antwoorden zijn opgeslagen. Wij bewaren ze tot {date}, zodat u later verder kunt.',
			'2026-11-04T12:00:00+00:00',
			'nl',
		),
		'Uw antwoorden zijn opgeslagen. Wij bewaren ze tot 4 november 2026, zodat u later verder kunt.',
	)
	assert.equal(
		retentionSentence('tot {date}', '', 'nl'),
		'',
		'no date, no promise',
	)
})

test('save and resume: the button saves the answers, and the form comes back where the gap is', async () => {
	globalThis.document = {
		title: 'Woo',
		getElementById: () => null,
		documentElement: { lang: 'nl' },
		createElement: () => ({}),
	}
	const calls = { saved: [], read: 0, discarded: [] }
	const api = {
		fetchOptions: async () => [],
		async myDraft() {
			calls.read++
			return {
				answers: { onderwerp: 'De nieuwe brug' },
				step: 0,
				expiresAt: '2026-11-04T12:00:00+00:00',
			}
		},
		async saveDraft(app, actionId, body) {
			calls.saved.push([app, actionId, body])
			return {
				answers: body,
				step: body.step,
				expiresAt: '2026-11-04T12:00:00+00:00',
			}
		},
		async discardDraft(app, actionId) {
			calls.discarded.push([app, actionId])
			return true
		},
	}
	const action = wooAction({
		app: 'dossiq',
		draft: { retentionDays: 30 },
		fields: ['onderwerp', 'periodeVan'],
		fieldConfigs: {
			onderwerp: { label: 'Waar gaat uw verzoek over?', required: true },
			periodeVan: {
				label: 'Vanaf welke datum?',
				required: true,
				input: 'date',
			},
		},
		steps: [
			{ id: 'vraag', title: 'Uw vraag', fields: ['onderwerp'] },
			{
				id: 'periode',
				title: 'Periode en documenten',
				fields: ['periodeVan'],
			},
			{
				id: 'controle',
				title: 'Controleren en versturen',
				fields: [],
				review: true,
			},
		],
	})
	const send = async () => ({ ok: true, object: { identifier: '2026-0003' } })
	const form = await mountSfc(FORM, { action, api, send, t, app: 'dossiq' })
	await form.flush()

	// The saved answer came back and step 1 opened, because step 1 is the gap.
	assert.equal(calls.read, 1)
	assert.equal(
		form.textOf(form.find('schema-form-step-heading')),
		'Stap 2 van 3: periode en documenten',
	)
	assert.equal(
		form.textOf(form.find('schema-form-retention')),
		'Uw antwoorden zijn opgeslagen. Wij bewaren ze tot 4 november 2026, zodat u later verder kunt.',
	)

	// The button sits in the step navigation and sends what is typed so far.
	await type(form, 'f-startWooVerzoek-periodeVan', '1')
	await type(form, 'f-startWooVerzoek-periodeVan-month', '3')
	await type(form, 'f-startWooVerzoek-periodeVan-year', '2026')
	const save = form.find('schema-form-save-draft')
	assert.equal(form.textOf(save), 'Opslaan en later verdergaan')
	await form.fire(save, 'click')
	assert.deepEqual(calls.saved, [
		[
			'dossiq',
			'startWooVerzoek',
			{ onderwerp: 'De nieuwe brug', periodeVan: '2026-03-01', step: 1 },
		],
	])

	// Sending the action spends the draft: portaliq keeps no copy.
	await press(form)
	await press(form)
	assert.deepEqual(calls.discarded, [['dossiq', 'startWooVerzoek']])
	assert.ok(
		form.find('schema-form-retention') === null,
		'the promise goes with the draft',
	)
})

test('without a draft declaration, or without a session, the form offers no saving', async () => {
	globalThis.document = {
		title: 'Woo',
		getElementById: () => null,
		documentElement: { lang: 'nl' },
		createElement: () => ({}),
	}
	const plain = await mountSfc(FORM, {
		action: wooAction(),
		api: { fetchOptions: async () => [] },
		send: async () => ({ ok: true, object: {} }),
		t,
	})
	await plain.flush()
	assert.ok(
		plain.find('schema-form-save-draft') === null,
		'the action never asked for drafts',
	)

	// A signed-out visitor: the api has no draft methods at all.
	const signedOut = await mountSfc(FORM, {
		action: wooAction({ draft: { retentionDays: 30 } }),
		api: { fetchOptions: async () => [] },
		send: async () => ({ ok: true, object: {} }),
		t,
	})
	await signedOut.flush()
	assert.ok(signedOut.find('schema-form-save-draft') === null)
	assert.ok(signedOut.find('schema-form-retention') === null)
})
