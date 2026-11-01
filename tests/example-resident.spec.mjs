#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// example-resident.spec.mjs: every example-set fragment portaliq ships
// (lib/Settings/example-sets/*.json) held against what this app can check
// without an instance:
//
//   - it names its example resident: a shipped example site, a portal account
//     object and a sign-in change of its own, and a mode the portal schema allows;
//   - the portal account fits the `portalAccount` schema of the register this
//     app ships;
//   - every placeholder is one OpenRegister's example-set loader fills, the
//     resident is a cast member, every lookup is named by the object that
//     uses it, and every object it quotes was declared before it;
//   - the sign-in card and every text a resident reads follow the house rules
//     (no em-dash, no Title Case on the card);
//   - the Zuiddrecht resident keeps the six cases the Woo journey and the
//     resident boards depend on.
//
// The cases live in another app's register and the cast lives in
// OpenRegister, so `occ openregister:example-set:install <set> --check`
// proves the rest on a live instance. This check is the half that runs
// without one.
//
// Usage:
//   node --test tests/example-resident.spec.mjs
//
// @spec openspec/changes/resident-on-example-set/specs/example-resident/spec.md

import assert from 'node:assert/strict'
import { existsSync, readdirSync, readFileSync } from 'node:fs'
import { test } from 'node:test'

const root = new URL('../', import.meta.url)
const read = (path) => readFileSync(new URL(path, root), 'utf8')

const register = JSON.parse(read('lib/Settings/portaliq_register.json'))
const schemas = register.components.schemas
const siteIds = readdirSync(new URL('lib/Settings/sites/', root))
	.filter((f) => f.endsWith('.json'))
	.map((f) => f.replace(/\.json$/, ''))
/**
 * A fragment with the lists of its `<set>.d/` parts appended in file-name
 * order, as OpenRegister's ExampleSetCatalogue gathers it.
 *
 * @param {string} file The fragment's file name.
 * @return {object} The gathered fragment.
 */
function gathered(file) {
	const fragment = JSON.parse(read(`lib/Settings/example-sets/${file}`))
	const folder = `lib/Settings/example-sets/${file.replace(/\.json$/, '')}.d/`
	if (!existsSync(new URL(folder, root))) {
		return fragment
	}
	for (const part of readdirSync(new URL(folder, root)).filter((f) => f.endsWith('.json')).sort()) {
		for (const [key, value] of Object.entries(JSON.parse(read(folder + part)))) {
			if (key === 'set' || key.startsWith('$') || key.startsWith('@')) {
				continue
			}
			assert.ok(Array.isArray(value), `${folder}${part}: a part may only add lists, not "${key}"`)
			fragment[key] = [...(fragment[key] || []), ...value]
		}
	}
	return fragment
}

const fragments = readdirSync(new URL('lib/Settings/example-sets/', root))
	.filter((f) => f.endsWith('.json'))
	.map((file) => ({ file, fragment: gathered(file) }))

/**
 * The day count of a `{{date:n}}` or `{{datetime:n hh:mm}}` placeholder.
 *
 * @param {string} value The placeholder.
 * @return {number} The days from the install day.
 */
function dayCount(value) {
	return Number(String(value).match(/\{\{date(?:time)?:([+-]?\d+)/)?.[1])
}

const PLACEHOLDER = /\{\{([^{}]+)\}\}/g
const FORMS = [
	/^cast:[a-z0-9-]+\.[A-Za-z.]+$/,
	/^lookup:[A-Za-z][A-Za-z0-9]*$/,
	/^object:[a-z0-9-]+\.[A-Za-z]+$/,
	/^date:[+-]?\d{1,4}$/,
	/^datetime:[+-]?\d{1,4} ([01]\d|2[0-3]):[0-5]\d$/,
]

/**
 * Every string in a value, with its path.
 *
 * @param {*} value The value.
 * @param {string} path Where we are.
 * @return {Array<[string, string]>} Path and text pairs.
 */
function strings(value, path = '') {
	if (typeof value === 'string') {
		return [[path, value]]
	}
	if (Array.isArray(value)) {
		return value.flatMap((item, index) => strings(item, `${path}[${index}]`))
	}
	if (value && typeof value === 'object') {
		return Object.entries(value).flatMap(([key, child]) =>
			strings(child, path ? `${path}.${key}` : key),
		)
	}
	return []
}

test('there is at least one shipped example-set fragment', () => {
	assert.ok(fragments.length > 0)
})

for (const { file, fragment } of fragments) {
	// One resident may be written as an object, several as a list
	// (demo-sign-in-beside-the-account).
	const residents = [].concat(fragment.exampleResident || [])
	const accountOf = (resident) => fragment.objects.find((object) => object.key === resident.account)
	const portals = [...new Set(residents.map((resident) => resident.portal))]
	const signIn = (fragment.changes || []).find(
		(change) => change.schema === 'portal' && portals.includes(change.where?.slug),
	)

	test(`${file}: names its example residents, their site and the way in`, () => {
		assert.ok(residents.length > 0, 'exampleResident is declared')
		assert.equal(fragment.set ?? file.replace(/\.json$/, ''), file.replace(/\.json$/, ''))
		for (const resident of residents) {
			assert.ok(siteIds.includes(resident.portal), `${resident.portal} is a shipped example site`)
			assert.match(resident.cast, /^[a-z0-9-]+$/)
			const account = accountOf(resident)
			assert.ok(account, `${resident.account} is an object of this fragment`)
			assert.equal(account.schema, 'portalAccount')
			for (const [key, value] of Object.entries(resident.card || {})) {
				assert.ok(['title', 'text', 'button'].includes(key), `${resident.cast}: card.${key} is a card field`)
				assert.equal(typeof value, 'string')
			}
		}
		assert.equal(new Set(residents.map((resident) => resident.cast)).size, residents.length, 'each resident once')
		// The demo route signs in through the `nextcloud` way, so the set adds it
		// with the account card of the board.
		assert.ok(signIn, 'a change adds the way in to the portal')
		const modes = schemas.portal.properties.authentication.properties.modes.items.enum
		assert.ok(modes.includes('nextcloud'))
		assert.equal(signIn.append['authentication.modes'], 'nextcloud')
		const label = signIn.set['authentication.modeLabels.nextcloud']
		for (const key of ['title', 'text', 'button']) {
			assert.equal(typeof label[key], 'string')
		}
	})

	test(`${file}: the residents' portal accounts fit the portalAccount schema`, () => {
		const schema = schemas.portalAccount
		for (const resident of residents) {
			const account = accountOf(resident)
			for (const key of schema.required || []) {
				assert.ok(key in account.data, `${key} is required`)
			}
			for (const [key, value] of Object.entries(account.data)) {
				const property = schema.properties[key]
				assert.ok(property, `${key} is a property of portalAccount`)
				const type = Array.isArray(value) ? 'array' : typeof value
				assert.equal(type, property.type)
				if (property.enum) {
					assert.ok(property.enum.includes(value), `${key}: ${value} is allowed`)
				}
			}
			assert.equal(account.data.subjectRef, `{{cast:${resident.cast}.uid}}`)
			assert.equal(account.data.identityRef, 'example-resident')
			assert.ok(!('email' in account.data), 'no e-mail address, so the resident meets the prompt for one')
		}
	})

	test(`${file}: every object is well formed and every placeholder can be filled`, () => {
		const seen = new Set()
		for (const object of fragment.objects) {
			assert.match(object.key, /^[a-z0-9-]+$/)
			assert.ok(!seen.has(object.key), `${object.key} is declared once`)
			assert.equal(typeof object.register, 'string')
			assert.equal(typeof object.schema, 'string')
			assert.ok(object.data && typeof object.data === 'object')
			for (const field of object.jsonFields || []) {
				assert.ok(field in object.data, `${object.key}: jsonFields names ${field}, which data holds`)
			}
			const lookups = Object.keys(object.lookups || {})
			for (const [name, lookup] of Object.entries(object.lookups || {})) {
				assert.equal(typeof lookup.schema, 'string', `${object.key}: lookup ${name} names a schema`)
				assert.ok(Object.keys(lookup.where || {}).length > 0, `${object.key}: lookup ${name} asks for something`)
			}
			const texts = [
				...strings(object.data, `${object.key}.data`),
				...strings(object.lookups || {}, `${object.key}.lookups`),
			]
			for (const [path, text] of texts) {
				assert.ok(!text.includes('{{subject}}'), `${path}: the resident is a cast member now`)
				for (const match of text.matchAll(PLACEHOLDER)) {
					const inner = match[1].trim()
					assert.ok(FORMS.some((form) => form.test(inner)), `${path}: {{${inner}}} is a form the loader fills`)
					if (inner.startsWith('lookup:')) {
						assert.ok(lookups.includes(inner.slice(7)), `${path}: lookup ${inner.slice(7)} is declared on this object`)
					}
					if (inner.startsWith('object:')) {
						const key = inner.slice(7).split('.')[0]
						assert.ok(seen.has(key), `${path}: ${key} was declared before this object`)
					}
				}
			}
			seen.add(object.key)
		}
	})

	test(`${file}: the texts follow the house rules`, () => {
		const texts = strings(fragment).filter(([path]) => !path.startsWith('$comment'))
		for (const [path, text] of texts) {
			assert.ok(!text.includes('—'), `${path}: no em-dash`)
			assert.ok(!text.includes('--'), `${path}: no double dash`)
		}
		const cards = [
			signIn.set['authentication.modeLabels.nextcloud'],
			...residents.map((resident) => resident.card || {}),
		]
		for (const [key, text] of cards.flatMap((card) => Object.entries(card))) {
			const words = text.split(' ')
			const capitals = words.slice(1).filter((word) => /^[A-Z][a-z]/.test(word))
			assert.ok(
				capitals.length < Math.max(2, words.length / 2),
				`sign-in card ${key}: sentence case, not Title Case`,
			)
		}
	})
}

test('zuiddrecht.json: every portal account and mandate fits its schema, and the four residents are the one-click residents', () => {
	const zuiddrecht = fragments.find(({ file }) => file === 'zuiddrecht.json').fragment
	const fits = (object) => {
		const schema = schemas[object.schema]
		for (const key of schema.required || []) {
			assert.ok(key in object.data, `${object.key}: ${key} is required`)
		}
		for (const [key, value] of Object.entries(object.data)) {
			const property = schema.properties[key]
			assert.ok(property, `${object.key}: ${key} is a property of ${object.schema}`)
			const type = Array.isArray(value) ? 'array' : typeof value
			assert.equal(type, property.type, `${object.key}: ${key} is a ${property.type}`)
			if (property.enum && !value.includes('{{')) {
				assert.ok(property.enum.includes(value), `${object.key}: ${key} ${value} is allowed`)
			}
		}
	}
	const accounts = zuiddrecht.objects.filter((object) => object.schema === 'portalAccount')
	const mandates = zuiddrecht.objects.filter((object) => object.schema === 'portalMandate')
	assert.deepEqual(
		accounts.map((object) => object.data.subjectRef),
		[
			...['sanne', 'henk', 'linda', 'fatima', 'janwillem', 'mark'].map((key) => `{{cast:${key}.uid}}`),
			// Sanne's caseworkers, named where a plan reads its participants (zuiddrecht.d/20-samenwerken.json).
			'mark.jansen',
			'linda.smit',
		],
	)
	accounts.forEach(fits)
	mandates.forEach(fits)
	for (const mandate of mandates) {
		// A company the cast does not hold (Bakkerij Lindelaan B.V. of the Machtigingen board) is named by its KvK number.
		assert.match(mandate.data.onBehalfOf, /^((subject|kvk):\{\{cast:[a-z]+\.(uid|kvk)\}\}|kvk:\d{8})$/, `${mandate.key} names a typed party`)
	}
	assert.deepEqual(
		accounts.filter((object) => object.data.identityRef === 'example-resident').map((object) => object.key),
		zuiddrecht.exampleResident.map((resident) => resident.account),
		'exactly the residents are one click away',
	)
	assert.deepEqual(
		zuiddrecht.exampleResident.map((resident) => resident.cast),
		['sanne', 'henk', 'linda', 'fatima'],
		'the business contacts get no one-click card',
	)
})

test('zuiddrecht.json gives Sanne the addresses of the MijnAccount board, and still the prompt for a confirmed e-mail address', () => {
	const zuiddrecht = fragments.find(({ file }) => file === 'zuiddrecht.json').fragment
	const sanne = zuiddrecht.objects.find((object) => object.key === 'portaalaccount-sanne').data
	assert.deepEqual(
		sanne.contactAddresses.map((entry) => [entry.kind, entry.value, entry.confirmed, entry.preferred]),
		[
			['email', 'sanne.devries@example.nl', true, true],
			['email', 's.devries@werk.example.nl', false, false],
			['phone', '+31612345678', false, true],
		],
		'MijnAccount: a preferred address, one waiting for confirmation, one phone number',
	)
	assert.ok(!('email' in sanne), 'the account keeps no notification address, so the MijnOverzicht prompt stays')
	assert.match(
		sanne.contactAddresses.find((entry) => entry.kind === 'phone').value,
		/^\+31\d{9}$/,
		'a phone number in E.164',
	)
	for (const entry of sanne.contactAddresses.filter((address) => address.kind === 'email')) {
		assert.match(entry.value, /@[a-z.]*example\.nl$/, 'a reserved example domain')
	}
})

test('zuiddrecht.json lets Sanne act for a company and follow two access requests, as the Machtigingen board draws her', () => {
	const zuiddrecht = fragments.find(({ file }) => file === 'zuiddrecht.json').fragment
	const sanne = '{{cast:sanne.uid}}'
	const held = zuiddrecht.objects.filter(
		(object) => object.schema === 'portalMandate' && object.data.subjectRef === sanne,
	)
	assert.equal(held.length, 1, 'one mandate held, so "Namens wie u werkt" offers a choice')
	assert.equal(held[0].data.onBehalfOf, 'kvk:87654321')
	assert.equal(held[0].data.label, 'Bakkerij Lindelaan B.V.', 'Machtigingen: Uw machtiging voor Bakkerij Lindelaan B.V.')
	assert.equal(dayCount(held[0].data.grantedAt), -26, 'given on 12 September')
	const requests = zuiddrecht.objects.filter(
		(object) => object.schema === 'portalAccessRequest' && object.data.subjectRef === sanne,
	)
	assert.deepEqual(requests.map((object) => object.data.state).sort(), ['granted', 'pending'])
	assert.deepEqual(
		requests.map((object) => [object.data.onBehalfOf, dayCount(object.data.requestedAt)]),
		[['Bakkerij Lindelaan B.V. (KvK 87654321)', -26], ['Henk de Vries', -6]],
		'Machtigingen: requested on 12 September and 2 October',
	)
	const schema = schemas.portalAccessRequest
	for (const request of requests) {
		for (const key of schema.required) {
			assert.ok(key in request.data, `${request.key}: ${key} is required`)
		}
		for (const [key, value] of Object.entries(request.data)) {
			assert.ok(schema.properties[key], `${request.key}: ${key} is a property`)
			assert.equal(typeof value, schema.properties[key].type)
		}
		assert.match(request.data.requestedAt, /^\{\{datetime:-\d+ \d\d:\d\d\}\}$/)
	}
})

test('zuiddrecht.json puts the residents in the group the portal names', () => {
	const zuiddrecht = fragments.find(({ file }) => file === 'zuiddrecht.json').fragment
	assert.deepEqual(zuiddrecht.groups, [
		{ group: 'inwoners', displayName: 'Inwoners', members: ['sanne', 'henk', 'linda', 'fatima'] },
		{ group: 'ondernemers', displayName: 'Ondernemers', members: ['janwillem', 'mark', 'karin'] },
	])
	const change = zuiddrecht.changes.find((entry) => entry.key === 'inloggen-met-account')
	assert.equal(change.set['authentication.registration.residentGroup'], 'inwoners')
	assert.equal(change.set['authentication.modeLabels.nextcloud'].title, 'Met een account')
	const registration = schemas.portal.properties.authentication.properties.registration.properties
	assert.equal(registration.residentGroup.type, 'string', 'the schema declares the resident group')
	assert.equal(registration.residentAudience.type, 'string', 'the schema declares the resident audience')
})

test('zuiddrecht.json puts the business contacts in the business group the portal names, never in inwoners', () => {
	const zuiddrecht = fragments.find(({ file }) => file === 'zuiddrecht.json').fragment
	const members = Object.fromEntries(zuiddrecht.groups.map((group) => [group.group, group.members]))
	const businessContacts = ['janwillem', 'mark', 'karin']
	assert.deepEqual(members.ondernemers, businessContacts)
	for (const contact of businessContacts) {
		assert.ok(!members.inwoners.includes(contact), `${contact} is not a resident`)
	}
	const change = zuiddrecht.changes.find((entry) => entry.key === 'ondernemers-op-het-portaal')
	assert.deepEqual(change.where, { slug: 'zuiddrecht' })
	assert.deepEqual(change.set, { 'authentication.registration.businessGroup': 'ondernemers' })
	const registration = schemas.portal.properties.authentication.properties.registration.properties
	assert.equal(registration.businessGroup.type, 'string', 'the schema declares the business group')
	assert.equal(registration.businessAudience.type, 'string', 'the schema declares the business audience')
})

test('zuiddrecht.json keeps the six cases of the Woo journey and the resident boards', () => {
	const zuiddrecht = fragments.find(({ file }) => file === 'zuiddrecht.json').fragment
	const cases = zuiddrecht.objects.filter((object) => object.schema === 'case').map((object) => object.data.title)
	assert.deepEqual(cases, [
		'Verlichting fietspad Lindelaan',
		'Dakkapel Lindelaan 14',
		'Losse stoeptegel bij de bushalte Lindelaan',
		'Kapotte lantaarnpaal Kerkplein',
		'Subsidie buurtfeest Lindelaan',
		'Parkeervergunning Lindelaan',
	])
	assert.deepEqual(zuiddrecht.after, ['dossiq'])
	assert.equal(zuiddrecht.exampleResident[0].cast, 'sanne')
})

test('Sanne has an open portal task on her parking permit: a copy of her ID, at most two files', () => {
	const zuiddrecht = fragments.find(({ file }) => file === 'zuiddrecht.json').fragment
	const task = zuiddrecht.tasks.find((entry) => entry.key === 'parkeervergunning-sanne-id-kopie').data
	assert.equal(task.title, 'Stuur een kopie van uw ID-bewijs')
	assert.equal(task.performerType, 'external')
	assert.equal(task.assignee, 'party:{{cast:sanne.uid}}')
	assert.equal(task.objectUuid, '{{object:parkeervergunning-sanne.id}}')
	assert.deepEqual(task.uploadRules, { required: true, maxFiles: 2 })
	assert.match(task.description, /Lindelaan 14/)
})

// The Samenwerken, Contacten and Gesprek boards (zuiddrecht, rowMijn): what
// Sanne's own area needs to draw them, from the gathered set (main file and
// its zuiddrecht.d parts). The boards were drawn on Thursday 8 October 2026,
// so a board date is a day count from the install day.
const zuiddrechtSet = () => fragments.find(({ file }) => file === 'zuiddrecht.json').fragment
const SANNE = '{{cast:sanne.uid}}'
const objectsOf = (schema) => zuiddrechtSet().objects.filter((object) => object.schema === schema)
const days = (value) => Number(String(value).match(/\{\{date(?:time)?:([+-]?\d+)/)?.[1])
const accountNamed = (ref) => objectsOf('portalAccount').find((object) => object.data.subjectRef === ref)?.data.displayName

test('Plannen: the portal switches on plans, contacts and actions, and Sanne has two running plans and one done', () => {
	const change = zuiddrechtSet().changes.find((entry) => entry.schema === 'portal' && entry.set?.plansEnabled !== undefined)
	assert.deepEqual(change.set, { contactsEnabled: true, plansEnabled: true, actionsEnabled: true })
	const plans = objectsOf('portalPlan').filter((plan) => plan.data.owner === SANNE || plan.data.participants?.includes(SANNE))
	const running = plans.filter((plan) => plan.data.status === 'running')
	assert.deepEqual(running.map((plan) => plan.data.title).sort(), ['Schuldhulp op orde', 'Terug naar werk'])
	assert.equal(running.filter((plan) => plan.data.owner === SANNE).length, 1, 'U maakte er 1 zelf, 1 is met u gedeeld')
	const shared = running.find((plan) => plan.data.owner !== SANNE)
	assert.equal(accountNamed(shared.data.owner), 'Linda Smit')
	assert.equal(shared.data.goal, 'Voor 1 maart 2027 werk ik minstens 16 uur per week.')
	assert.equal(plans.filter((plan) => plan.data.status === 'done').length, 1)
	const actionsOf = (plan) => objectsOf('portalAction').filter((action) => action.data.plan === `{{object:${plan.key}.id}}`)
	assert.deepEqual(
		[actionsOf(shared).length, actionsOf(shared).filter((action) => action.data.status === 'done').length],
		[4, 1],
		'1 van 4 acties klaar',
	)
})

test('Plannen: the municipality offers three plan templates', () => {
	const templates = objectsOf('portalPlanTemplate').filter((template) => template.data.portal === 'zuiddrecht')
	assert.deepEqual(
		templates.map((template) => [template.data.title, template.data.actions.length, template.data.published]),
		[['Schuldhulp op orde', 5, true], ['Terug naar werk', 4, true], ['Zelfstandig wonen', 6, true]],
	)
	for (const template of templates) {
		assert.match(template.data.summary, new RegExp(`zet ${template.data.actions.length} acties klaar`))
		for (const action of template.data.actions) {
			assert.ok(['once', 'recurring'].includes(action.kind))
			assert.ok(Number.isInteger(action.offsetDays) && action.offsetDays <= template.data.durationDays)
		}
	}
})

test('Plan and ActieBewerken: Schuldhulp op orde with Mark Jansen, five actions, two done, and his note', () => {
	const plan = objectsOf('portalPlan').find((entry) => entry.data.title === 'Schuldhulp op orde')
	assert.equal(days(plan.data.endDate), 12, 'loopt over 12 dagen af')
	assert.deepEqual(plan.data.participants.map(accountNamed), ['Mark Jansen'])
	assert.equal(accountNamed(plan.data.note.editedBy), 'Mark Jansen')
	assert.match(plan.data.note.text, /^De energierekening loopt via automatische incasso/)
	const actions = objectsOf('portalAction').filter((action) => action.data.plan === `{{object:${plan.key}.id}}`)
	assert.deepEqual(
		actions.map((action) => [action.data.title, action.data.status, days(action.data.endDate), accountNamed(action.data.assignee)]),
		[
			['Overzicht maken van al mijn schulden', 'done', -23, '{{cast:sanne.displayName}}'],
			['Afspraak met de budgetcoach plannen', 'done', -16, 'Mark Jansen'],
			['Bankafschriften van drie maanden uploaden', 'doing', 6, '{{cast:sanne.displayName}}'],
			['Aanvraag schuldhulpverlening indienen', 'todo', 12, 'Mark Jansen'],
			['Vaste lasten bijwerken in het budgetplan', 'todo', 23, '{{cast:sanne.displayName}}'],
		],
	)
})

test('Contacten and ContactUitnodigen: four contacts, a request from Ahmed Bakker and an open invitation', () => {
	const mine = objectsOf('portalContact').filter((contact) => contact.data.owner === SANNE)
	const approved = mine.filter((contact) => contact.data.state === 'approved')
	assert.deepEqual(
		approved.map((contact) => [contact.data.displayName, contact.data.role, contact.data.line]),
		[
			['Mark Jansen', 'begeleider', 'Gemeente Zuiddrecht, schuldhulpverlening'],
			['Henk de Vries', 'contact', 'Uw vader'],
			['Linda Smit', 'begeleider', 'Werkplein Zuiddrecht'],
			['Stichting Thuis in Zuiddrecht', 'organisatie', 'Woonbegeleiding, Kerkstraat 8'],
		],
	)
	const requested = mine.filter((contact) => contact.data.state === 'requested')
	assert.deepEqual(requested.map((contact) => [contact.data.displayName, contact.data.line, days(contact.data.sentAt)]), [
		['Ahmed Bakker', 'Begeleider, Stichting Thuis in Zuiddrecht', -1],
	])
	const invited = mine.filter((contact) => contact.data.state === 'invited')
	assert.deepEqual(invited.map((contact) => [contact.data.email, days(contact.data.sentAt)]), [['j.devries@example.nl', -3]])
})

test('Gesprek: conversations with Mark Jansen, Linda Smit and Henk de Vries; two new messages from Mark today', () => {
	const contacts = new Map(
		objectsOf('portalContact')
			.filter((contact) => contact.data.owner === SANNE && contact.data.state === 'approved')
			.map((contact) => [contact.data.contactRef, contact.data.displayName]),
	)
	const threads = objectsOf('messageThread').filter((thread) => thread.data.participantRefs?.includes(SANNE))
	const other = (thread) => thread.data.participantRefs.find((ref) => ref !== SANNE)
	assert.deepEqual(threads.map((thread) => contacts.get(other(thread))), ['Mark Jansen', 'Linda Smit', 'Henk de Vries'])
	for (const thread of threads) {
		assert.equal(thread.data.kind, 'direct')
		assert.equal(thread.data.participantRefs.length, 2)
	}
	const mark = threads[0]
	assert.equal(mark.data.recordLabel, 'Schuldhulp op orde')
	const messages = objectsOf('guardianMessage').filter((message) => message.data.threadRef === `{{object:${mark.key}.id}}`)
	assert.equal(messages.length, 4)
	const unread = messages.filter((message) => message.data.senderRef !== SANNE && !message.data.readBy.includes(SANNE))
	assert.deepEqual(unread.map((message) => days(message.data.sentAt)), [0, 0], '2 nieuwe berichten, vandaag')
	assert.ok(unread.every((message) => message.data.senderRef === other(mark)))
})

// The portal boards' own records (zuiddrecht.d/30-portal-boards.json): the
// admin boards were drawn on Wednesday 7 October 2026, the Mijn boards on
// Thursday 8 October, so a board date is a day count from the install day.
const portalBoardsPart = () => JSON.parse(read('lib/Settings/example-sets/zuiddrecht.d/30-portal-boards.json'))
const minutes = (value) => {
	const [, day, hour, minute] = String(value).match(/\{\{datetime:([+-]?\d+) (\d\d):(\d\d)\}\}/) || []
	return Number(day) * 1440 + Number(hour) * 60 + Number(minute)
}
const fitsSchema = (object) => {
	const schema = schemas[object.schema]
	assert.ok(schema, `${object.key}: portaliq ships the ${object.schema} schema`)
	for (const key of schema.required || []) {
		assert.ok(key in object.data, `${object.key}: ${key} is required`)
	}
	for (const [key, value] of Object.entries(object.data)) {
		const property = schema.properties[key]
		assert.ok(property, `${object.key}: ${key} is a property of ${object.schema}`)
		assert.equal(Array.isArray(value) ? 'array' : typeof value, property.type, `${object.key}: ${key} is a ${property.type}`)
		if (property.enum) {
			assert.ok(property.enum.includes(value), `${object.key}: ${key} ${value} is one of ${property.enum}`)
		}
	}
}

test('portal boards part: every placeholder is a form the loader fills, and no key is declared twice in the set', () => {
	const part = portalBoardsPart()
	for (const [path, text] of strings(part)) {
		for (const [, inner] of text.matchAll(PLACEHOLDER)) {
			assert.ok(FORMS.some((form) => form.test(inner)), `${path}: {{${inner}}} is a form the loader fills`)
		}
		assert.ok(!text.includes('—'), `${path}: no em-dash`)
	}
	const set = zuiddrechtSet()
	for (const list of ['objects', 'tasks', 'changes']) {
		const keys = set[list].map((entry) => entry.key)
		assert.deepEqual(keys.filter((key, index) => keys.indexOf(key) !== index), [], `${list}: every key once`)
	}
})

test('Editor and EditorMenu: the zuiddrecht portal has the page "Werk in uitvoering" with the three blocks the boards edit', () => {
	const page = portalBoardsPart().objects.find((object) => object.key === 'pagina-werk-in-uitvoering')
	assert.ok(page, 'the page is in the set, so every refresh keeps it')
	fitsSchema(page)
	assert.equal(page.data.portal, 'zuiddrecht')
	assert.equal(page.data.route, '/werk-in-uitvoering')
	assert.equal(page.data.title, 'Werk in uitvoering')
	assert.equal(page.data.status, 'published')
	const site = JSON.parse(read('lib/Settings/sites/zuiddrecht.json'))
	assert.ok(!site.pages.some((entry) => entry.route === page.data.route), 'the example site does not declare the route a second time')
	assert.deepEqual(
		page.data.body.widgets.map((widget) => [widget.id, widget.widgetKey, widget.gridX, widget.gridWidth]),
		[
			['zd-werk-01-markdown', 'markdown', 0, 12],
			['zd-werk-02-nllocationmap', 'nlLocationMap', 0, 7],
			['zd-werk-03-markdown', 'markdown', 7, 5],
		],
		'a text block over the full width, the map in columns 1 to 7, the selected text block from column 8, width 5',
	)
	const [intro, map, selected] = page.data.body.widgets
	assert.equal(intro.props.markdown, '# Werk in uitvoering\n\nHier leest u over werkzaamheden in uw buurt.')
	assert.equal(map.props.locations[0].name, 'Fietspad Lindelaan')
	assert.equal(
		selected.props.markdown,
		'## Fietspad Lindelaan\nVanaf maart wordt het fietspad aangelegd. Er komt verlichting langs het hele pad.',
	)
})

test('PtMededelingenActies: the zuiddrecht portal has 14 notices, 3 coming, 1 concept, and none showing today', () => {
	const notices = portalBoardsPart().objects.filter((object) => object.schema === 'portalNotice')
	assert.equal(notices.length, 14, '6 van 14')
	notices.forEach(fitsSchema)
	for (const notice of notices) {
		assert.equal(notice.data.portal, 'zuiddrecht')
		assert.ok(minutes(notice.data.endsAt) > minutes(notice.data.startsAt), `${notice.key}: ends after it starts`)
		assert.ok(notice.data.message.length <= 280, `${notice.key}: fits the message field`)
	}
	const now = 12 * 60
	const published = notices.filter((notice) => notice.data.status === 'published')
	assert.equal(notices.length - published.length, 1, 'Concept 1')
	assert.equal(published.filter((notice) => minutes(notice.data.startsAt) > now).length, 3, 'Komend 3')
	assert.deepEqual(
		published.filter((notice) => minutes(notice.data.startsAt) <= now && minutes(notice.data.endsAt) > now).map((notice) => notice.key),
		[],
		'no notice runs on the install day, so no Mijn board gets a banner the boards do not draw',
	)
	assert.deepEqual(
		notices.slice(0, 6).map((notice) => notice.data.message.split(' ').slice(0, 3).join(' ')),
		['Inloggen met DigiD', 'Het Stadskantoor is', 'Door Kerst en', 'De afvalkalender voor', 'Parkeervergunningen verlengen kan', 'Stemmen voor de'],
		'the six rows the board draws',
	)
})

test('PtUitnodigingen: 24 invitations for Mijn Zuiddrecht, 6 open, 14 accepted, 3 expired, 1 withdrawn, each sent by a named person', () => {
	const invitations = portalBoardsPart().objects.filter((object) => object.schema === 'portalInvitation')
	assert.equal(invitations.length, 24)
	invitations.forEach(fitsSchema)
	const count = (states) => invitations.filter((object) => states.includes(object.data.state)).length
	assert.deepEqual(
		[count(['sent', 'opened']), count(['accepted']), count(['expired']), count(['revoked'])],
		[6, 14, 3, 1],
	)
	for (const invitation of invitations) {
		assert.match(invitation.data.invitedBy, /^\{\{cast:[a-z]+\.uid\}\}$/, `${invitation.key}: sent by a cast member, never a raw id`)
		assert.match(invitation.data.tokenHash, /^[0-9a-f]{64}$/)
		assert.ok(minutes(invitation.data.expiresAt) > minutes(invitation.data.sentAt), `${invitation.key}: expires after it is sent`)
	}
	const hashes = invitations.map((object) => object.data.tokenHash)
	assert.equal(new Set(hashes).size, hashes.length, 'every invitation has its own token')
	const drawn = invitations.find((object) => object.data.email === 'k.bos@stichtingoost.nl').data
	assert.deepEqual([drawn.state, drawn.mandate.label], ['opened', 'Stichting Buurthuis Oost'])
	const tom = invitations.find((object) => object.data.email === 't.visser@example.nl').data
	assert.deepEqual(
		[tom.state, tom.invitedBy, tom.onBehalfOf, tom.mandate.label],
		['sent', SANNE, `subject:${SANNE}`, 'Parkeervergunning'],
		'Machtigingen: Sanne waits for Tom Visser to answer',
	)
})

test('PtToegangsverzoeken: the eight drawn requests, three open, decided by Pieter Jansen, Anouk Bakker or Lars de Koning', () => {
	const requests = portalBoardsPart().objects.filter((object) => object.schema === 'portalAccessRequest')
	requests.forEach(fitsSchema)
	assert.deepEqual(
		requests.map((object) => [object.data.displayName, object.data.onBehalfOf, object.data.state]),
		[
			['R. Mulder', 'Bakkerij De Basislijn BV', 'pending'],
			['L. Hendriks', 'J. Hendriks', 'pending'],
			['S. Bos', 'Stichting Buurthuis Oost', 'pending'],
			['A. Kaya', 'Kaya Installatietechniek', 'granted'],
			['M. Visser', 'E. Visser-de Boer', 'granted'],
			['T. de Groot', 'Vereniging Kerkplein', 'refused'],
			['N. Jansen', 'Jansen Hoveniers VOF', 'granted'],
			['F. Oudshoorn', 'Oudshoorn Vastgoed BV', 'refused'],
		],
	)
	assert.equal(dayCount(requests[0].data.requestedAt), -6, 'R. Mulder: 1 okt, 6 dagen open')
	for (const request of requests.filter((object) => object.data.state !== 'pending')) {
		assert.match(request.data.decidedBy, /^\{\{cast:(pieter|anouk|lars)\.uid\}\}$/)
		assert.ok(minutes(request.data.decidedAt) >= minutes(request.data.requestedAt))
	}
})

test('MijnTaken: Sanne has three open tasks, one on her Woo case, so the menu counts 3 and the case has a task in its current step', () => {
	const open = zuiddrechtSet().tasks.filter(
		(task) => task.data.assignee === `party:${SANNE}` && task.data.state === 'active' && task.data.performerType === 'external',
	)
	assert.deepEqual(
		open.map((task) => [task.data.title, task.data.objectUuid, dayCount(task.data.dueAt)]),
		[
			['Stuur een kopie van uw ID-bewijs', '{{object:parkeervergunning-sanne.id}}', 10],
			['Lees en onderteken de verklaring bij uw bewonersvergunning', '{{object:parkeervergunning-sanne.id}}', 12],
			['Kies of u het besluit per post wilt ontvangen', '{{object:woo-fietspad.id}}', 3],
		],
		'Voor 18 oktober, Voor 20 oktober, Nog 3 dagen',
	)
})

test('Mijn menu: the portal lays out the groups every Mijn board draws, Thema’s with Parkeren and Samenwerken among them', () => {
	const change = portalBoardsPart().changes.find((entry) => entry.key === 'mijn-menu-als-getekend')
	assert.deepEqual(change.where, { slug: 'zuiddrecht' })
	assert.deepEqual(
		change.set['residentMenu.groups'].map((group) => group.title),
		['Mijn Zuiddrecht', 'Thema’s', 'Zaken en taken', 'Samenwerken', 'Vragen en meldingen', 'Uw gegevens'],
	)
	assert.deepEqual(change.set['residentMenu.groups'][1].items, ['__theme__:parkeren'], 'a theme item is named by its nav key')
	const site = JSON.parse(read('lib/Settings/sites/zuiddrecht.json'))
	assert.deepEqual(change.set['residentMenu.leaveOut'], site.portal.residentMenu.leaveOut, 'the same items left out as a fresh example site')
	const menu = schemas.portal.properties.residentMenu.properties
	assert.equal(menu.groups.type, 'array')
	assert.equal(menu.leaveOut.type, 'array')
})
