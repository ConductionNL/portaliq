/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The public site's way to the assistant route
 * (search-assistant-from-public-content).
 *
 * The call is anonymous by construction: it sends no Authorization header and
 * no cookies, whatever session the visitor holds. Kept free of Vue so the node
 * tests drive it directly.
 */

/**
 * The URL of the assistant route, found from the content API base.
 *
 * @param {string} apiBase The content API base, e.g. `/apps/portaliq/api/content`.
 * @return {string} The route's URL.
 * @spec openspec/changes/search-assistant-from-public-content/tasks.md#t05
 */
export function assistantUrl(apiBase) {
	return `${String(apiBase || '').replace(/\/api\/content\/?$/, '')}/api/assistant/ask`
}

/**
 * Ask one question. A non-2xx answer throws, so "the assistant is off" is not
 * shown as "the assistant had nothing to say".
 *
 * @param {string} apiBase The content API base.
 * @param {{portal: string, question: string, locale: string, conversationId: string}} ask What to ask.
 * @param {Function} [fetcher] The fetch to use; `window.fetch` by default.
 * @return {Promise<{status: string, answer: ?string, sources: Array<{title: string, url: string}>, removed: boolean, conversationId: string}>} The reply.
 * @spec openspec/changes/search-assistant-from-public-content/tasks.md#t05
 */
export async function askAssistant(apiBase, ask, fetcher) {
	const doFetch = fetcher || ((...args) => window.fetch(...args))
	const response = await doFetch(assistantUrl(apiBase), {
		method: 'POST',
		// Never the visitor's session: no bearer, no cookies.
		credentials: 'omit',
		headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
		body: JSON.stringify({
			portal: ask.portal || '',
			question: ask.question,
			locale: ask.locale || 'nl',
			conversationId: ask.conversationId || '',
		}),
	})
	if (!response.ok) {
		throw new Error(`assistant ${response.status}`)
	}

	const body = await response.json()
	const sources = Array.isArray(body.sources) ? body.sources : []
	// An answer without a source is never shown, whatever the server sent.
	const answered =
		body.status === 'answered'
		&& typeof body.answer === 'string'
		&& sources.length > 0
	return {
		status: answered ? 'answered' : 'abstained',
		answer: answered ? body.answer : null,
		sources: answered ? sources : [],
		removed: body.removed === true,
		conversationId:
			typeof body.conversationId === 'string' ? body.conversationId : '',
	}
}
