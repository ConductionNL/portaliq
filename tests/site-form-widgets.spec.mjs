// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// site-multi-step-forms wave 2 (T3, T4; REQ-SMF-004, REQ-SMF-005): a file
// field looks like a button and lists the chosen file with a remove control;
// a field with options may ask for radio cards (`widget: choices`, optionally
// a subset plus an "other" card), and a date field for named days
// (`widget: dateChoices`). Neither changes what is sent.
//
// Mounted elements are compared with `assert.ok(a === b)`, never
// `assert.equal(a, b)`: a failing `equal` renders the whole app (see
// tests/site-form-fields.spec.mjs).
//
// Usage:
//   node --test tests/site-form-widgets.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { optionOf } from '../src/shared/portalApi.js'
import { choiceSplit, namedDays } from '../src/site/components/forms/fields.js'
import { mountSfc } from './support/mount-sfc.mjs'

const CARDS = 'src/site/components/forms/ChoiceCards.vue'
const DAYS = 'src/site/components/forms/DateChoices.vue'
const UPLOAD = 'src/site/components/forms/FileUpload.vue'
const FORM = 'src/site/components/c/SchemaForm.vue'

const KINDS = [
	{ value: 'illness', label: 'Ziek' },
	{ value: 'medical-appointment', label: 'Dokter of tandarts' },
	{ value: 'bereavement', label: 'Overlijden' },
	{ value: 'religious', label: 'Religieuze feestdag' },
	{ value: 'family', label: 'Familieomstandigheden' },
	{ value: 'other', label: 'Anders' },
]

/**
 * The visible labels of the radio cards in a mounted tree, in order.
 *
 * @param {object} mounted The mounted component.
 * @return {string[]} The card labels.
 */
function cardLabels(mounted) {
	return mounted
		.findAll(
			(n) => n.tag === 'span' && n.props.class === 'pq-choice-card__label',
		)
		.map((n) => mounted.textOf(n))
}

/**
 * A fake portal api that records what the form sends.
 *
 * @return {object} The api and its call log.
 */
function fakeApi() {
	const calls = { created: [] }
	return {
		calls,
		async fetchOptions() {
			return []
		},
		async createObject(action, body) {
			calls.created.push(body)
			return { ok: true, object: { id: 'new-1', ...body } }
		},
		async uploadFieldFile() {
			return { ok: true }
		},
	}
}

test('named days: a name over the date, as wilgenboom/MobielDetail draws them', () => {
	// Friday 2 October 2026: the next working day is Monday, named by its weekday.
	const friday = new Date(2026, 9, 2, 10, 0)
	assert.deepEqual(namedDays(friday, { count: 2, locale: 'nl' }), [
		{ value: '2026-10-02', label: 'Vandaag', description: 'Vrijdag 2 oktober, de hele dag' },
		{ value: '2026-10-05', label: 'Maandag 5 oktober', description: '' },
	])
	// Monday 5 October 2026, as on the board: "Vandaag", then "Morgen".
	const monday = new Date(2026, 9, 5, 8, 0)
	assert.deepEqual(namedDays(monday, { count: 2, locale: 'nl' }).map((d) => [d.label, d.description]), [
		['Vandaag', 'Maandag 5 oktober, de hele dag'],
		['Morgen', 'Dinsdag 6 oktober'],
	])
	assert.equal(
		namedDays(monday, { count: 1, locale: 'en', todayWord: 'Today', wholeDayWords: 'the whole day' })[0].description,
		'Monday, October 5, the whole day',
		'the whole-day words follow the site language',
	)
	assert.deepEqual(
		namedDays(monday, { count: 2, locale: 'en', todayWord: 'Today', tomorrowWord: 'Tomorrow' }).map((d) => d.label),
		['Today', 'Tomorrow'],
	)
	assert.deepEqual(
		namedDays(friday, { count: 3 }).map((d) => d.value),
		['2026-10-02', '2026-10-05', '2026-10-06'],
		'the weekend is skipped',
	)
	assert.equal(namedDays(friday, { count: 9 }).length, 2, 'out of range reads 2')
	assert.equal(namedDays(friday, { count: 1 }).length, 1)
})

test('choice split: a subset in its own order, the rest behind the other card', () => {
	const split = choiceSplit(KINDS, ['medical-appointment', 'iban', 'illness'])
	assert.deepEqual(
		split.cards.map((o) => o.value),
		['medical-appointment', 'illness'],
	)
	assert.deepEqual(
		split.rest.map((o) => o.value),
		['bereavement', 'religious', 'family', 'other'],
	)
	assert.deepEqual(
		choiceSplit(KINDS, []).cards.length,
		6,
		'no subset: every option',
	)
	assert.deepEqual(
		choiceSplit(KINDS, ['iban']).rest,
		[],
		'nothing usable: every option',
	)
})

test('three cards out of six kinds; the other card reveals a select with the rest', async () => {
	const cards = await mountSfc(CARDS, {
		id: 'reasonKind',
		options: KINDS,
		choiceOptions: ['illness', 'medical-appointment'],
		otherLabel: 'Een andere reden',
	})
	assert.deepEqual(cardLabels(cards), [
		'Ziek',
		'Dokter of tandarts',
		'Een andere reden',
	])
	const radios = cards.findAll((n) => n.tag === 'input')
	assert.ok(
		radios.every(
			(r) => r.props.type === 'radio' && r.props.name === 'reasonKind',
		),
	)
	assert.equal(
		radios[0].props.id,
		'reasonKind',
		'the first card carries the field id',
	)
	assert.ok(
		cards.find('reasonKind-rest') === null,
		'the select waits for the other card',
	)

	await cards.fire(cards.find('reasonKind-choice-illness'), 'change')
	assert.equal(cards.emitted['update:modelValue'].at(-1)[0], 'illness')

	await cards.fire(cards.find('reasonKind-choice-other'), 'change')
	assert.equal(
		cards.emitted['update:modelValue'].at(-1)[0],
		'',
		'a card value is cleared',
	)
	const select = cards.find('reasonKind-rest')
	assert.ok(select !== null, 'the select with the other kinds appears')
	assert.match(
		cards.textOf(select),
		/Overlijden Religieuze feestdag Familieomstandigheden Anders/,
	)
	assert.doesNotMatch(cards.textOf(select), /Ziek/)
	await cards.fire(select, 'change', { value: 'family' })
	assert.equal(cards.emitted['update:modelValue'].at(-1)[0], 'family')
})

test('a child card carries its group and teacher as a second line (wilgenboom/MobielDetail)', async () => {
	const cards = await mountSfc(CARDS, {
		id: 'learnerRef',
		options: [
			{ value: 'vera', label: 'Vera', description: 'Groep 7 · Meester Daan' },
			{ value: 'sami', label: 'Sami', description: 'Groep 4 · Juf Esra' },
			{ value: 'noor', label: 'Noor' },
		],
	})
	assert.deepEqual(cardLabels(cards), ['Vera', 'Sami', 'Noor'])
	assert.deepEqual(
		cards
			.findAll((n) => n.tag === 'span' && n.props.class === 'pq-choice-card__description')
			.map((n) => cards.textOf(n)),
		['Groep 7 · Meester Daan', 'Groep 4 · Juf Esra'],
		'an option without a description draws no empty line',
	)
	await cards.fire(cards.find('learnerRef-choice-sami'), 'change')
	assert.equal(cards.emitted['update:modelValue'].at(-1)[0], 'sami')
})

test('a collection option carries the row field its provider names as its description', () => {
	const provider = { valueField: 'id', labelField: 'givenName', descriptionField: 'groupLabel' }
	assert.deepEqual(optionOf({ id: 'vera', givenName: 'Vera', groupLabel: 'Groep 7 · Meester Daan' }, provider), {
		value: 'vera',
		label: 'Vera',
		description: 'Groep 7 · Meester Daan',
	})
	assert.deepEqual(optionOf({ id: 'noor', givenName: 'Noor', groupLabel: '  ' }, provider), { value: 'noor', label: 'Noor' })
	assert.deepEqual(optionOf({ id: 'sami', givenName: 'Sami', groupLabel: 'Groep 4' }, { valueField: 'id', labelField: 'givenName' }), {
		value: 'sami',
		label: 'Sami',
	})
})

test('named days: a day sends its date, "Een andere dag" opens the date group', async () => {
	const days = await mountSfc(DAYS, {
		id: 'dateFrom',
		now: new Date(2026, 9, 2, 10, 0),
		count: 2,
		locale: 'nl',
	})
	assert.deepEqual(cardLabels(days), [
		'Vandaag',
		'Maandag 5 oktober',
		'Een andere dag',
	])
	assert.deepEqual(
		days
			.findAll((n) => n.tag === 'span' && n.props.class === 'pq-choice-card__description')
			.map((n) => days.textOf(n)),
		['Vrijdag 2 oktober, de hele dag'],
		'today carries its date as a second line',
	)
	await days.fire(days.find('dateFrom-day-1'), 'change')
	assert.equal(days.emitted['update:modelValue'].at(-1)[0], '2026-10-05')

	assert.equal(
		days.findAll((n) => n.props.id === 'dateFrom-date').length,
		0,
		'no date group yet',
	)
	await days.fire(days.find('dateFrom-day-other'), 'change')
	const box = (suffix) =>
		days.findAll((n) => n.props.id === `dateFrom-date${suffix}`)[0]
	assert.ok(box('') !== undefined, 'the Dag box appears')
	await days.fire(box(''), 'input', { value: '9' })
	await days.fire(box('-month'), 'input', { value: '10' })
	await days.fire(box('-year'), 'input', { value: '2026' })
	assert.equal(days.emitted['update:modelValue'].at(-1)[0], '2026-10-09')
})

test('a file field: a button-like label, the chosen file by name, a remove control', async () => {
	const upload = await mountSfc(UPLOAD, {
		id: 'bijlage',
		files: [{ name: 'afsprakenkaart.jpg', size: 1000 }],
		labelledBy: 'bijlage-label',
		limitText: 'Een bestand mag 10 MB groot zijn.',
	})
	const input = upload.findAll((n) => n.tag === 'input')[0]
	assert.equal(input.props.type, 'file')
	assert.equal(input.props['aria-labelledby'], 'bijlage-label bijlage-button')
	assert.match(input.props['aria-describedby'], /bijlage-limit bijlage-picked/)
	const button = upload.find('file-upload-button')
	assert.equal(button.tag, 'label')
	assert.equal(button.props.for, 'bijlage')
	assert.equal(upload.textOf(button), 'Bestand of foto kiezen')
	assert.match(button.props.class, /utrecht-button--secondary-action/)
	const list = upload.find('file-upload-list')
	assert.equal(list.props['aria-live'], 'polite')
	assert.match(upload.textOf(list), /afsprakenkaart\.jpg/)

	const remove = upload.find('file-upload-remove-0')
	assert.equal(remove.tag, 'button')
	assert.equal(upload.textOf(remove), 'afsprakenkaart.jpg verwijderen')
	await upload.fire(remove, 'click')
	assert.deepEqual(upload.emitted.pick.at(-1)[0], [])
	const fresh = upload.findAll((n) => n.tag === 'input')[0]
	assert.ok(upload.focused() === fresh, 'focus goes back to the input')

	await upload.fire(fresh, 'change', { files: [{ name: 'foto.png' }] })
	assert.deepEqual(
		upload.emitted.pick.at(-1)[0].map((f) => f.name),
		['foto.png'],
	)
})

test('through the action form: cards and the other select send the same value a select would', async () => {
	const action = {
		id: 'createExcuseRequest',
		type: 'create',
		label: 'Afwezig melden',
		register: 'learniq',
		schema: 'excuse-request',
		fields: ['reasonKind', 'dateFrom'],
		fieldConfigs: {
			reasonKind: {
				label: 'Waarom is Vera afwezig?',
				required: true,
				widget: 'choices',
				choiceOptions: ['illness', 'medical-appointment'],
				otherLabel: 'Een andere reden',
			},
			dateFrom: {
				label: 'Vanaf welke dag?',
				required: true,
				input: 'date',
				widget: 'dateChoices',
				dateChoices: 2,
			},
		},
		optionsProviders: { reasonKind: { type: 'static', options: KINDS } },
	}
	const api = fakeApi()
	const form = await mountSfc(FORM, { action, api, locale: 'nl' })
	await form.flush()

	const field = form.find('schema-field-reasonKind')
	assert.equal(field.tag, 'fieldset', 'the cards sit in a fieldset')
	assert.match(
		form.textOf(field),
		/^Waarom is Vera afwezig\? Ziek Dokter of tandarts Een andere reden/,
	)

	await form.fire(
		form.find('f-createExcuseRequest-reasonKind-choice-other'),
		'change',
	)
	await form.fire(form.find('f-createExcuseRequest-reasonKind-rest'), 'change', {
		value: 'family',
	})
	await form.fire(form.find('f-createExcuseRequest-dateFrom-day-0'), 'change')
	await form.fire(form.find('schema-form'), 'submit')

	assert.equal(api.calls.created.length, 1)
	const today = new Date()
	const iso = [
		today.getFullYear(),
		String(today.getMonth() + 1).padStart(2, '0'),
		String(today.getDate()).padStart(2, '0'),
	].join('-')
	assert.deepEqual(api.calls.created[0], { reasonKind: 'family', dateFrom: iso })
})

test('an action field may word its own error; the summary line is the same on every form', async () => {
	const action = {
		id: 'createExcuseRequest',
		type: 'create',
		label: 'Afwezig melden',
		fields: ['dateTo', 'reasonKind'],
		fieldConfigs: {
			dateTo: {
				label: 'Tot en met welke dag?',
				required: true,
				input: 'date',
				requiredMessage: 'Kies de laatste dag dat Vera afwezig is',
			},
			reasonKind: { label: 'Soort afwezigheid', required: true },
		},
		optionsProviders: { reasonKind: { type: 'static', options: KINDS } },
	}
	const api = fakeApi()
	const form = await mountSfc(FORM, { action, api })
	await form.flush()
	await form.fire(form.find('schema-form'), 'submit')

	assert.equal(api.calls.created.length, 0)
	assert.equal(
		form.textOf(form.find('error-summary-link-dateTo')),
		'Kies de laatste dag dat Vera afwezig is',
	)
	assert.equal(
		form.textOf(form.find('schema-field-error-dateTo')),
		'Kies de laatste dag dat Vera afwezig is',
		'the same words stand under the field',
	)
	assert.equal(
		form.textOf(form.find('error-summary-link-reasonKind')),
		'Soort afwezigheid is required.',
		'without its own words the generic message stays',
	)
	assert.match(
		form.textOf(form.find('error-summary')),
		/Fill this in\. Then you can continue\./,
	)
})

test('the server refusal of an empty required field lands in the summary of an action form', async () => {
	const action = {
		id: 'bookConferenceSlot',
		type: 'create',
		label: 'Tijd boeken',
		register: 'learniq',
		schema: 'conference-signup',
		fields: ['slotId', 'notes'],
		// What a stale page holds: it does not know slotId became required.
		fieldConfigs: { slotId: { label: 'Tijd' }, notes: { label: 'Toelichting' } },
	}
	const api = {
		calls: [],
		async fetchOptions() {
			return []
		},
		async createObject(a, body) {
			api.calls.push(body)
			return {
				ok: false,
				status: 400,
				object: null,
				error: 'required_missing',
				errors: { slotId: '', ghost: 'not on this form' },
			}
		},
	}
	const form = await mountSfc(FORM, { action, api })
	await form.flush()
	await form.fire(form.find('schema-form'), 'submit')

	assert.equal(
		api.calls.length,
		1,
		'the client let it through; the server refused',
	)
	assert.equal(
		form.textOf(form.find('error-summary-link-slotId')),
		'Tijd is required.',
	)
	assert.ok(
		form.find('error-summary-link-ghost') === null,
		'a field the form does not show is not listed',
	)
	assert.ok(form.focused() === form.find('error-summary-heading'))
	assert.ok(form.find('schema-form-error') === null, 'no generic failure line')
})

test('an attached action shows the server refusal in its summary too', async () => {
	const api = {
		forwardRowAction: async () => ({
			ok: false,
			status: 400,
			body: {
				error: 'required_missing',
				errors: { onderwerp: 'Vertel waar uw verzoek over gaat' },
			},
		}),
	}
	const collection = {
		id: 'mijnDossiers',
		register: 'opencatalogi',
		schema: 'collection',
		attachedActions: [
			{
				app: 'dossiq',
				id: 'startWooVerzoek',
				label: 'Start een Woo-verzoek',
				fields: ['onderwerp'],
				fieldConfigs: { onderwerp: { label: 'Waar gaat uw verzoek over?' } },
			},
		],
	}
	const block = await mountSfc('src/site/components/c/AttachedActions.vue', {
		collection,
		row: { id: 'dos-1' },
		api,
	})
	await block.fire(block.find('attached-action-startWooVerzoek'), 'click')
	await block.fire(block.findAll((n) => n.tag === 'form')[0], 'submit')

	assert.equal(
		block.textOf(block.find('error-summary-link-onderwerp')),
		'Vertel waar uw verzoek over gaat',
	)
	assert.ok(block.focused() === block.find('error-summary-heading'))
})
