// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// A news article may carry the sign-up of its event: the facts, a card that
// asks a visitor to sign in first, and the words after the deadline
// (event-sign-up-by-a-pupil-with-seats).
//
// @spec openspec/changes/event-sign-up-by-a-pupil-with-seats/specs/portaliq-cms/spec.md#requirement-a-news-item-may-carry-the-sign-up-of-its-event

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { areaName, eventCardState } from '../src/site/widgets/nlNewsArticle/article.js'
import { inState } from './support/page-instance.mjs'
import { loadSfc, renderComponent } from './support/render-sfc.mjs'

const NlNewsArticle = await loadSfc('src/site/widgets/nlNewsArticle/NlNewsArticle.vue', {
	'@conduction/nextcloud-vue': "export const cnRenderMarkdown = (source) => source\n",
})

const EVENT = {
	id: 'ev1',
	title: 'Informatieavond profielkeuze',
	start: '2026-11-03',
	location: 'De aula',
	signupDeadline: '2026-10-30',
	closed: false,
	askSeats: true,
	maxSeatsPerAnswer: 4,
}

const ITEM = {
	id: 'n1',
	title: 'Informatieavond profielkeuze op dinsdag 3 november',
	body: 'Kom langs.',
	publishedAt: '2026-10-08T09:00:00+00:00',
	audienceLabel: 'Leerlingen van 3 havo en 3 vwo en hun ouders',
	event: EVENT,
}

const WAYS = [{ id: 'oidc', label: 'Inloggen', href: '/index.php/apps/portaliq/auth/session/oidc/start?provider=generic&portal=vaartveld&returnTo=%2Fnieuws%2Fn1' }]

function render (item, props = {}) {
  return renderComponent(inState(NlNewsArticle, { item, state: 'ready' }), {
		portal: 'vaartveld',
		routeParam: 'n1',
		ways: WAYS,
		...props,
	})
}

test('the card state follows the event and the visitor', () => {
	assert.equal(eventCardState(null, false), 'none')
	assert.equal(eventCardState(EVENT, false), 'signin')
	assert.equal(eventCardState(EVENT, true), 'open')
	assert.equal(eventCardState({ ...EVENT, closed: true }, true), 'closed', 'a closed event never invites an answer')
})

test('the area is named after the portal unless the page names it', () => {
	assert.equal(areaName('', 'vaartveld'), 'Mijn Vaartveld')
	assert.equal(areaName('Mijn school', 'vaartveld'), 'Mijn school')
	assert.equal(areaName('', ''), 'Mijn omgeving')
})

test('signed out, the article shows the facts and asks the visitor to sign in first', async () => {
	const html = await render(ITEM)
	assert.match(html, /data-testid="nl-news-article-event"/)
	assert.match(html, /<dt>Waar<\/dt><dd>De aula<\/dd>/)
	assert.match(html, /Leerlingen van 3 havo en 3 vwo en hun ouders/)
	assert.match(html, /Tot en met 30 oktober 2026|Tot en met vrijdag 30 oktober 2026/)
	assert.match(html, /Je logt eerst in bij Mijn Vaartveld\./)
	assert.match(html, /tot 4 stoelen/)
	assert.match(html, /href="[^"]*oidc\/start[^"]*returnTo=%2Fnieuws%2Fn1[^"]*"[^>]*>\s*Inloggen/, 'the sign-in returns to the article')
})

test('signed in, the card is a sign-up button and not a sign-in line', async () => {
	const html = await render(ITEM, { signedIn: true })
	assert.doesNotMatch(html, /Je logt eerst in/)
	assert.match(html, /data-testid="nl-news-article-event-button"[^>]*>\s*Aanmelden/)
})

test('after the deadline the card says so in words and offers no button', async () => {
	const html = await render({ ...ITEM, event: { ...EVENT, closed: true } }, { signedIn: true })
	assert.match(html, /Aanmelden kon tot en met .*30 oktober 2026\./)
	assert.doesNotMatch(html, /nl-news-article-event-button/)
})

test('an article without an event shows no card', async () => {
	const { event, ...plain } = ITEM
	assert.doesNotMatch(await render(plain), /nl-news-article-event/)
})
