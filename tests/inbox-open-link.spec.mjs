// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// The inbox does not repeat the "Open" link in the text (woo-inbox-notices
// REQ-NAP-012). Found while filming the Woo journey: the notices of pipelinq,
// procest and opencatalogi end with a raw address, because the same text is
// the e-mail, and the inbox showed it next to the row's own "Openen" button.
// The fixtures are the apps' real Dutch notice texts.
//
// @spec openspec/changes/woo-inbox-notices/specs/portal-notifications-and-preferences/spec.md#requirement-the-inbox-does-not-repeat-the-open-link-in-the-text-req-nap-012

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { bodyParts, bodyWithoutOpenLink } from '../src/site/pages/inbox/inbox.js'
import { instance, inState, t } from './support/page-instance.mjs'
import { loadSfc, renderComponent } from './support/render-sfc.mjs'

const SITE = 'http://localhost:8080/index.php/apps/portaliq/site'

// pipelinq QuestionAnsweredNotice: the link opens the question itself.
const QUESTION = {
	body:
		'Er is een antwoord op uw vraag "Wanneer wordt de brug gerepareerd?".'
		+ '\n\nLees het antwoord hier: '
		+ `${SITE}#open=pipelinq/myQuestions/0b7c2a9e-1f3d-4c55-9a61-3e2f8d1c4b70`,
	recordLink: {
		app: 'pipelinq',
		collection: 'myQuestions',
		id: '0b7c2a9e-1f3d-4c55-9a61-3e2f8d1c4b70',
	},
}

// procest WooDecisionNotice: the link opens the publication.
const DECISION_BODY =
	'Wij hebben het besluit op uw Woo-verzoek "Stukken over de brug" gepubliceerd.'
	+ '\n\nLees het besluit en de openbaar gemaakte documenten hier: '
	+ `${SITE}?route=/publicatie/5d0e9f12-7a4b-4c3e-8f21-9b6a0c3d2e11`

// opencatalogi SavedSearchNoticeWriter: one list line per publication.
const SAVED_SEARCH_BODY =
	'Er is een nieuwe publicatie die past bij uw zoekopdracht "brug".'
	+ '\n\n- Besluit op Woo-verzoek over de brug: '
	+ `${SITE}?route=/publicatie/5d0e9f12-7a4b-4c3e-8f21-9b6a0c3d2e11`

test('an answered question reads without its link and its lead-in', () => {
	assert.equal(
		bodyWithoutOpenLink(QUESTION.body, QUESTION.recordLink, '/mijn/vragen'),
		'Er is een antwoord op uw vraag "Wanneer wordt de brug gerepareerd?".',
	)
})

test('a decision notice whose "Open" leads to the publication drops the link and its lead-in', () => {
	assert.equal(
		bodyWithoutOpenLink(
			DECISION_BODY,
			{ app: 'procest', collection: 'mijnZaken', id: 'c-1' },
			'/publicatie/5d0e9f12-7a4b-4c3e-8f21-9b6a0c3d2e11',
		),
		'Wij hebben het besluit op uw Woo-verzoek "Stukken over de brug" gepubliceerd.',
	)
})

test('a lead-in on the same line as the sentence before it goes alone', () => {
	const flat = DECISION_BODY.replace('\n\n', ' ')
	assert.equal(
		bodyWithoutOpenLink(
			flat,
			{ app: 'procest', collection: 'mijnZaken', id: 'c-1' },
			'/publicatie/5d0e9f12-7a4b-4c3e-8f21-9b6a0c3d2e11/',
		),
		'Wij hebben het besluit op uw Woo-verzoek "Stukken over de brug" gepubliceerd.',
	)
})

test('a saved-search line keeps its title and drops ": <url>" when it leads where "Open" leads', () => {
	assert.equal(
		bodyWithoutOpenLink(
			SAVED_SEARCH_BODY,
			{ app: 'opencatalogi', collection: 'savedSearches', id: 's-1' },
			'/publicatie/5d0e9f12-7a4b-4c3e-8f21-9b6a0c3d2e11',
		),
		'Er is een nieuwe publicatie die past bij uw zoekopdracht "brug".'
			+ '\n\n- Besluit op Woo-verzoek over de brug',
	)
})

test('an address that leads somewhere else than "Open" stays, word for word', () => {
	// procest's real recordLink is the case, the address is the publication.
	assert.equal(
		bodyWithoutOpenLink(
			DECISION_BODY,
			{ app: 'procest', collection: 'mijnZaken', id: 'c-1' },
			'/mijn/procest/mijnZaken/c-1',
		),
		DECISION_BODY,
	)
	// opencatalogi's real recordLink is the saved search, the address a publication.
	assert.equal(
		bodyWithoutOpenLink(
			SAVED_SEARCH_BODY,
			{ app: 'opencatalogi', collection: 'savedSearches', id: 's-1' },
			'/mijn/opencatalogi/savedSearches',
		),
		SAVED_SEARCH_BODY,
	)
	// Another question than the one "Open" opens.
	assert.equal(
		bodyWithoutOpenLink(
			QUESTION.body,
			{ ...QUESTION.recordLink, id: 'another-question' },
			'/mijn/vragen',
		),
		QUESTION.body,
	)
	// The same route on another site is not this site.
	const elsewhere = 'Lees meer: https://example.org/site?route=/publicatie/p-1'
	assert.equal(
		bodyWithoutOpenLink(
			elsewhere,
			{ app: 'a', collection: 'b', id: 'c' },
			'/publicatie/p-1',
		),
		elsewhere,
	)
})

test('only the address that leads where "Open" leads goes; the others stay', () => {
	const body =
		SAVED_SEARCH_BODY
		+ `\n- Een andere publicatie: ${SITE}?route=/publicatie/other-1`
	assert.equal(
		bodyWithoutOpenLink(
			body,
			{ app: 'opencatalogi', collection: 'savedSearches', id: 's-1' },
			'/publicatie/5d0e9f12-7a4b-4c3e-8f21-9b6a0c3d2e11',
		),
		'Er is een nieuwe publicatie die past bij uw zoekopdracht "brug".'
			+ '\n\n- Besluit op Woo-verzoek over de brug'
			+ `\n- Een andere publicatie: ${SITE}?route=/publicatie/other-1`,
	)
})

test('a row without "Open" keeps its body as written', () => {
	assert.equal(
		bodyWithoutOpenLink(QUESTION.body, QUESTION.recordLink, null),
		QUESTION.body,
	)
	assert.equal(
		bodyWithoutOpenLink(QUESTION.body, null, '/mijn/vragen'),
		QUESTION.body,
	)
	assert.equal(bodyWithoutOpenLink(undefined, null, null), '')
	assert.equal(bodyWithoutOpenLink('', QUESTION.recordLink, '/mijn/vragen'), '')
})

test('the inbox shows the answered question without the address, and "Openen" stays', async () => {
	const InboxPage = await loadSfc('src/site/pages/inbox/InboxPage.vue')
	const nav = [
		{
			key: 'pipelinq:myQuestions',
			label: 'Mijn vragen',
			contribution: { app: 'pipelinq' },
			page: {
				id: 'myQuestions',
				blocks: [{ type: 'collection', collection: 'myQuestions' }],
			},
		},
		{ key: '__inbox__', label: 'Inbox', special: 'inbox' },
	]
	const message = {
		id: 'q1',
		subject: 'Uw vraag is beantwoord',
		read: false,
		receivedAt: '2026-10-04T10:00:00Z',
		...QUESTION,
	}
	const html = await renderComponent(
		inState(InboxPage, { loading: false, messages: [message] }),
		{ api: {}, t, locale: 'nl', nav },
	)
	assert.match(html, /Er is een antwoord op uw vraag/)
	assert.doesNotMatch(html, /Lees het antwoord hier/)
	assert.doesNotMatch(html, /#open=pipelinq/)
	assert.match(html, />\s*Openen\s*</)
})

test('a translated row keeps its translation, without the same address', async () => {
	const InboxPage = await loadSfc('src/site/pages/inbox/InboxPage.vue')
	const page = instance(InboxPage, { api: {}, t, nav: [] })
	page.routeOf = () => '/mijn/vragen'
	const translation = {
		targetLanguage: 'en',
		text:
			'There is an answer to your question. Read the answer here: '
			+ `${SITE}#open=pipelinq/myQuestions/0b7c2a9e-1f3d-4c55-9a61-3e2f8d1c4b70`,
		label: 'ai',
	}
	const shown = page.shownTranslation({ ...QUESTION, translation })
	assert.equal(shown.text, 'There is an answer to your question.')
	assert.equal(shown.targetLanguage, 'en')
	assert.equal(shown.label, 'ai')
	assert.equal(page.shownTranslation({ ...QUESTION }), null)
})

// THE OTHER ADDRESSES INTO THIS SITE SHOW AS NAMED LINKS (REQ-NAP-013).

const ORIGIN = 'http://localhost:8080'
const LABELS = {
	publication: 'Bekijk de publicatie',
	link: 'Bekijk de link',
}
const PUBLICATION_HREF = `${SITE}?route=/publicatie/5d0e9f12-7a4b-4c3e-8f21-9b6a0c3d2e11`

test('the decision notice names its publication link in place of the lead-in', () => {
	const parts = bodyParts(DECISION_BODY, ORIGIN, LABELS)
	assert.deepEqual(parts, [
		{
			text: 'Wij hebben het besluit op uw Woo-verzoek "Stukken over de brug" gepubliceerd.\n\n',
		},
		{
			text: 'Bekijk de publicatie',
			href: PUBLICATION_HREF,
			route: '/publicatie/5d0e9f12-7a4b-4c3e-8f21-9b6a0c3d2e11',
		},
	])
	const flat = bodyParts(DECISION_BODY.replace('\n\n', ' '), ORIGIN, LABELS)
	assert.equal(
		flat[0].text,
		'Wij hebben het besluit op uw Woo-verzoek "Stukken over de brug" gepubliceerd. ',
	)
	assert.equal(flat[1].text, 'Bekijk de publicatie')
	assert.ok(
		parts.every((part) => !/https?:/.test(part.text)),
		'no raw address',
	)
})

test('a saved-search line makes its title the link and drops ": <url>"', () => {
	const parts = bodyParts(SAVED_SEARCH_BODY, ORIGIN, LABELS)
	assert.deepEqual(parts, [
		{
			text: 'Er is een nieuwe publicatie die past bij uw zoekopdracht "brug".\n\n- ',
		},
		{
			text: 'Besluit op Woo-verzoek over de brug',
			href: PUBLICATION_HREF,
			route: '/publicatie/5d0e9f12-7a4b-4c3e-8f21-9b6a0c3d2e11',
		},
	])
})

test('another address into the site reads "Bekijk de link" and loads the site', () => {
	const parts = bodyParts(
		`Zie ook ${SITE}#open=pipelinq/myQuestions/other.`,
		ORIGIN,
		LABELS,
	)
	assert.deepEqual(parts, [
		{ text: 'Zie ook ' },
		{
			text: 'Bekijk de link',
			href: `${SITE}#open=pipelinq/myQuestions/other`,
			route: null,
		},
		{ text: '.' },
	])
})

test('an address outside this site stays plain text, never a link', () => {
	for (const body of [
		'Lees meer: https://example.org/index.php/apps/portaliq/site?route=/publicatie/p-1',
		'Lees meer: https://example.org/elders',
		`Lees meer: ${ORIGIN}/index.php/apps/files/?dir=/`,
		'Lees meer: javascript:alert(1)',
	]) {
		assert.deepEqual(bodyParts(body, ORIGIN, LABELS), [{ text: body }])
	}
	// Without a known origin nothing becomes a link.
	assert.deepEqual(bodyParts(DECISION_BODY, '', LABELS), [{ text: DECISION_BODY }])
})

test('the answered question still reads without any address or link', () => {
	const shown = bodyWithoutOpenLink(
		QUESTION.body,
		QUESTION.recordLink,
		'/mijn/vragen',
	)
	assert.deepEqual(bodyParts(shown, ORIGIN, LABELS), [
		{
			text: 'Er is een antwoord op uw vraag "Wanneer wordt de brug gerepareerd?".',
		},
	])
})

test('the inbox renders the decision notice with a named link and no raw address', async () => {
	const InboxPage = await loadSfc('src/site/pages/inbox/InboxPage.vue')
	const message = {
		id: 'd1',
		subject: 'Het besluit op uw Woo-verzoek is gepubliceerd',
		read: false,
		receivedAt: '2026-10-04T10:00:00Z',
		body: DECISION_BODY,
		recordLink: { app: 'procest', collection: 'mijnZaken', id: 'c-1' },
	}
	const html = await renderComponent(
		inState(InboxPage, { loading: false, messages: [message], origin: ORIGIN }),
		{ api: {}, t, locale: 'nl', nav: [] },
	)
	assert.match(html, /gepubliceerd\./)
	assert.doesNotMatch(html, /Lees het besluit/)
	assert.match(
		html,
		/<a class="utrecht-link" href="http:\/\/localhost:8080\/index\.php\/apps\/portaliq\/site\?route=\/publicatie\/5d0e9f12-7a4b-4c3e-8f21-9b6a0c3d2e11" data-testid="inbox-body-link">Bekijk de publicatie<\/a>/,
	)
	assert.doesNotMatch(html.replace(/href="[^"]*"/g, ''), /https?:\/\//)
})

test('a link with a route opens inside the site; with a modifier key the browser follows it', async () => {
	const { default: LinkedText } =
		await import('../src/site/components/inbox/LinkedText.js')
	const page = instance(LinkedText, { parts: [] })
	let prevented = 0
	const click = (extra = {}) => ({ preventDefault: () => prevented++, ...extra })
	page.follow(click(), { route: '/publicatie/p-1' })
	page.follow(click({ ctrlKey: true }), { route: '/publicatie/p-1' })
	page.follow(click(), { route: null })
	assert.deepEqual(page.emitted, [['navigate', '/publicatie/p-1']])
	assert.equal(prevented, 1)
})
