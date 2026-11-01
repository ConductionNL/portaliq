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
		assert.match(mandate.data.onBehalfOf, /^(subject|kvk):\{\{cast:[a-z]+\.(uid|kvk)\}\}$/, `${mandate.key} names a typed party`)
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
		sanne.contactAddresses.map((entry) => entry.kind),
		['email', 'phone'],
	)
	const email = sanne.contactAddresses.find((entry) => entry.kind === 'email')
	assert.equal(email.confirmed, false, 'the e-mail address waits for confirmation')
	assert.equal(email.preferred, false, 'only a confirmed e-mail address can be preferred')
	assert.ok(!('email' in sanne), 'no confirmed address, so the prompt stays')
	assert.match(
		sanne.contactAddresses.find((entry) => entry.kind === 'phone').value,
		/^\+31\d{9}$/,
		'a phone number in E.164',
	)
	assert.match(email.value, /@[a-z.]*example\.nl$/, 'a reserved example domain')
})

test('zuiddrecht.json lets Sanne act for a company and follow two access requests, as the Machtigingen board draws her', () => {
	const zuiddrecht = fragments.find(({ file }) => file === 'zuiddrecht.json').fragment
	const sanne = '{{cast:sanne.uid}}'
	const held = zuiddrecht.objects.filter(
		(object) => object.schema === 'portalMandate' && object.data.subjectRef === sanne,
	)
	assert.equal(held.length, 1, 'one mandate held, so "Namens wie u werkt" offers a choice')
	assert.match(held[0].data.onBehalfOf, /^kvk:\{\{cast:[a-z]+\.kvk\}\}$/)
	const requests = zuiddrecht.objects.filter(
		(object) => object.schema === 'portalAccessRequest' && object.data.subjectRef === sanne,
	)
	assert.deepEqual(requests.map((object) => object.data.state).sort(), ['granted', 'pending'])
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
