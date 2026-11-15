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
import { answersInPlace, eventsBaseFrom, fetchOwnEvent, seatChoices, sendAnswer } from '../src/site/lib/eventAnswer.js'
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

function render (item, props = {}, data = {}) {
  return renderComponent(inState(NlNewsArticle, { item, state: 'ready', ...data }), {
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

const OWN = { id: 'ev1', mayAnswerSelf: true, selfRef: 'pupil-noor', askSeats: true, maxSeatsPerAnswer: 4, ownAnswer: null }

/**
 * A recording fetch that answers each call from the list in turn.
 *
 * @param {Array<{status: number, body?: object}>} answers The answers.
 * @return {Function} The fetch, with `calls`.
 */
function fakeFetch (answers) {
	const calls = []
	const fn = async (url, init = {}) => {
		calls.push({ url, init })
		const next = answers.shift() || { status: 500 }
		return { ok: next.status >= 200 && next.status < 300, status: next.status, json: async () => next.body }
	}
	fn.calls = calls
	return fn
}

test('the event routes sit beside the content API', () => {
	assert.equal(eventsBaseFrom('/apps/portaliq/api/content'), '/apps/portaliq/api/events')
	assert.equal(eventsBaseFrom('/api/content/'), '/api/events')
})

test('the card answers in place only when her feed lets her answer for herself', () => {
	assert.equal(answersInPlace(OWN), true)
	assert.equal(answersInPlace({ ...OWN, mayAnswerSelf: false }), false)
	assert.equal(answersInPlace({ ...OWN, selfRef: '' }), false, 'without her reference there is nothing to answer for')
	assert.equal(answersInPlace(null), false)
})

test('she chooses 1 to the maximum seats, 4 when the event names none', () => {
	assert.deepEqual(seatChoices({ askSeats: true, maxSeatsPerAnswer: 2 }), [1, 2])
	assert.deepEqual(seatChoices({ askSeats: true }), [1, 2, 3, 4])
	assert.deepEqual(seatChoices({ askSeats: false, maxSeatsPerAnswer: 2 }), [])
})

test('her event is read from her own feed with her bearer', async () => {
	const fetch = fakeFetch([{ status: 200, body: [{ id: 'other' }, OWN] }])
	const own = await fetchOwnEvent({ base: '/apps/portaliq/api/events', token: 'tok', id: 'ev1', fetch })
	assert.equal(own.selfRef, 'pupil-noor')
	assert.equal(fetch.calls[0].url, '/apps/portaliq/api/events/feed')
	assert.equal(fetch.calls[0].init.headers.Authorization, 'Bearer tok')
	assert.equal(await fetchOwnEvent({ base: '/b', token: '', id: 'ev1', fetch: fakeFetch([{ status: 401 }]) }), null)
	assert.equal(await fetchOwnEvent({ base: '/b', token: '', id: 'gone', fetch: fakeFetch([{ status: 200, body: [OWN] }]) }), null)
})

test('her answer is sent for herself, with the seats only when she comes', async () => {
	const fetch = fakeFetch([{ status: 204 }, { status: 204 }])
	assert.equal(await sendAnswer({ base: '/b', token: 'tok', id: 'ev1', selfRef: 'pupil-noor', response: 'yes', seats: 2, fetch }), 'answerSaved')
	assert.equal(fetch.calls[0].url, '/b/ev1/rsvp')
	assert.equal(fetch.calls[0].init.method, 'POST')
	assert.deepEqual(JSON.parse(fetch.calls[0].init.body), { childRef: 'pupil-noor', response: 'yes', seats: 2 })
	await sendAnswer({ base: '/b', token: 'tok', id: 'ev1', selfRef: 'pupil-noor', response: 'no', seats: 2, fetch })
	assert.deepEqual(JSON.parse(fetch.calls[1].init.body), { childRef: 'pupil-noor', response: 'no' })
})

test('a refusal comes back as the words the card says', async () => {
	const send = (answer) => sendAnswer({ base: '/b', token: '', id: 'ev1', selfRef: 'p', response: 'yes', seats: 1, fetch: fakeFetch([answer]) })
	assert.equal(await send({ status: 422, body: { error: 'event-full' } }), 'answerFull')
	assert.equal(await send({ status: 422, body: { error: 'signup-closed' } }), 'answerClosed')
	assert.equal(await send({ status: 422, body: { error: 'seats-invalid' } }), 'answerSeats')
	assert.equal(await send({ status: 422, body: { error: 'other' } }), 'answerFailed')
	assert.equal(await send({ status: 404 }), 'answerFailed')
})

test('signed in and allowed, the card takes her answer with the seats', async () => {
	const html = await render(ITEM, { signedIn: true }, { own: OWN })
	assert.match(html, /data-testid="nl-news-article-event-answer"/)
	assert.match(html, /<label for="nl-news-article-seats-ev1">Aantal stoelen<\/label>/)
	assert.equal((html.match(/<option /g) || []).length, 4)
	assert.match(html, /<button[^>]*type="submit"[^>]*>\s*Aanmelden\s*<\/button>/)
	assert.doesNotMatch(html, /nl-news-article-event-cancel/, 'nothing to cancel before she answered')
	assert.doesNotMatch(html, /href="[^"]*\/mijn/, 'no detour to the resident area')
})

test('once answered, the card says the seats and offers to cancel', async () => {
	const answered = { ...OWN, ownAnswer: { response: 'yes', seats: 3 } }
	const html = await render(ITEM, { signedIn: true }, { own: answered, chosenSeats: 3 })
	assert.match(html, /Je bent aangemeld met 3 stoelen\./)
	assert.match(html, /data-testid="nl-news-article-event-cancel"[^>]*>\s*Afmelden/)
	const one = await render(ITEM, { signedIn: true }, { own: { ...OWN, ownAnswer: { response: 'yes', seats: 1 } } })
	assert.match(one, /Je bent aangemeld met 1 stoel\./)
})

test('a refused answer is said in words', async () => {
	const html = await render(ITEM, { signedIn: true }, { own: OWN, outcome: 'answerFull' })
	assert.match(html, /role="status"[^>]*>\s*Er zijn niet genoeg stoelen meer vrij voor dit aantal\./)
})

test('a guardian whose feed does not let her answer for herself keeps the button', async () => {
	const html = await render(ITEM, { signedIn: true }, { own: { ...OWN, mayAnswerSelf: false } })
	assert.doesNotMatch(html, /nl-news-article-event-answer/)
	assert.match(html, /data-testid="nl-news-article-event-button"[^>]*>\s*Aanmelden/)
})
