// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// A signed-in pupil answers an event from its news article
// (event-sign-up-by-a-pupil-with-seats). The guardian-facing event routes,
// `GET /api/events/feed` and `POST /api/events/{id}/rsvp`, take the
// resident's bearer; the server decides who may answer, this file only
// carries the answer and says the outcome in words. Every network call
// takes its `fetch` as a parameter, so a plain node test asserts it.
//
// @spec openspec/changes/event-sign-up-by-a-pupil-with-seats/specs/portaliq-cms/spec.md#requirement-a-pupil-may-answer-an-event-for-herself-with-the-number-of-seats

/**
 * The event routes' base from the content API base: `/api/content` and
 * `/api/events` are siblings under the app root.
 *
 * @param {string} apiBase The content API base, e.g. `/apps/portaliq/api/content`.
 * @return {string} The events base, e.g. `/apps/portaliq/api/events`.
 */
export function eventsBaseFrom(apiBase) {
	return String(apiBase || '').replace(/\/api\/content\/?$/, '/api/events')
}

/**
 * The request headers, with the bearer when there is one.
 *
 * @param {string} token The resident's bearer.
 * @param {boolean} json Whether the body is JSON.
 * @return {object} The headers.
 */
function headersFor(token, json) {
	const headers = { Accept: 'application/json' }
	if (json) {
		headers['Content-Type'] = 'application/json'
	}
	if (token) {
		headers.Authorization = `Bearer ${token}`
	}
	return headers
}

/**
 * The event as the signed-in person's own feed has it, or null when it is
 * not in her feed or the feed cannot be read.
 *
 * @param {object} options The call.
 * @param {string} options.base The events base.
 * @param {string} options.token The resident's bearer.
 * @param {string} options.id The event id.
 * @param {typeof globalThis.fetch} options.fetch The fetch to use.
 * @return {Promise<object|null>} The event, with `mayAnswerSelf`, `selfRef` and `ownAnswer`.
 */
export async function fetchOwnEvent({ base, token, id, fetch }) {
	if (!id || typeof fetch !== 'function') {
		return null
	}
	try {
		const response = await fetch(`${base}/feed`, {
			headers: headersFor(token, false),
			credentials: 'same-origin',
		})
		if (!response.ok) {
			return null
		}
		const feed = await response.json()
		return (
			(Array.isArray(feed) ? feed : []).find(
				(event) => event && String(event.id ?? '') === String(id),
			) || null
		)
	} catch {
		return null
	}
}

/**
 * Whether the article may take the answer itself: the event lets her answer
 * for herself and names her reference.
 *
 * @param {object|null} own The event from her feed.
 * @return {boolean} True when the card can answer in place.
 */
export function answersInPlace(own) {
	return Boolean(
		own
		&& own.mayAnswerSelf === true
		&& typeof own.selfRef === 'string'
		&& own.selfRef !== '',
	)
}

/**
 * The seats she may choose: 1 to the event's maximum per answer (default 4).
 *
 * @param {object} event The event.
 * @return {Array<number>} The choices; empty when the event asks no seats.
 */
export function seatChoices(event) {
	if (!event || event.askSeats !== true) {
		return []
	}
	const max = Number.parseInt(event.maxSeatsPerAnswer, 10)
	const top = Number.isInteger(max) && max > 0 ? max : 4
	return Array.from({ length: top }, (_, index) => index + 1)
}

/** The server's refusals, to the key of the words the card says. */
const REFUSALS = Object.freeze({
	'event-full': 'answerFull',
	'signup-closed': 'answerClosed',
	'seats-invalid': 'answerSeats',
})

/**
 * Send her answer. Resolves to the key of the words the card says: `answerSaved`,
 * or the refusal's key, or `answerFailed` for anything else.
 *
 * @param {object} options The call.
 * @param {string} options.base The events base.
 * @param {string} options.token The resident's bearer.
 * @param {string} options.id The event id.
 * @param {string} options.selfRef Her own reference, from the feed.
 * @param {('yes'|'no')} options.response The answer.
 * @param {number|null} options.seats The seats, when the event asks for them.
 * @param {typeof globalThis.fetch} options.fetch The fetch to use.
 * @return {Promise<string>} The outcome's key.
 */
export async function sendAnswer({
	base,
	token,
	id,
	selfRef,
	response,
	seats,
	fetch,
}) {
	const body = { childRef: selfRef, response }
	if (response === 'yes' && Number.isInteger(seats)) {
		body.seats = seats
	}
	try {
		const answer = await fetch(`${base}/${encodeURIComponent(id)}/rsvp`, {
			method: 'POST',
			headers: headersFor(token, true),
			credentials: 'same-origin',
			body: JSON.stringify(body),
		})
		if (answer.ok) {
			return 'answerSaved'
		}
		if (answer.status === 422) {
			const reason = await answer.json().catch(() => ({}))
			return REFUSALS[reason?.error] || 'answerFailed'
		}
		return 'answerFailed'
	} catch {
		return 'answerFailed'
	}
}
