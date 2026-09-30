// SPDX-License-Identifier: EUPL-1.2
//
// Endpoint row actions in the portal (contribution-pay-screen). Pure functions,
// no React and no fetch, so the decisions are tested on their own
// (tests/row-action.spec.mjs):
//
// - which action a row offers (the resolved kind and the action's `rowWhen`),
// - where the browser may go after a forward (an absolute https URL only),
// - which message a forward's answer gets.
//
// The server decides the same things again: it resolves the row actions, reads
// the row under the subject's scope and refuses a row outside `rowWhen`. These
// helpers keep the screen honest, they are not the authority.

/**
 * Whether a resolved row action is an endpoint row action (forwarded to its
 * leaf app) rather than a `type: update` transition (a PATCH).
 *
 * @param {object} action A resolved action from the contribution.
 * @return {boolean}
 */
export function isEndpointRowAction(action) {
	return (
		Boolean(action)
		&& action.type !== 'update'
		&& typeof action.endpoint === 'string'
		&& typeof action.rowField === 'string'
	)
}

/**
 * Whether a row offers an action. An update action and an endpoint action
 * without `rowWhen` apply to every row; with `rowWhen`, only a row whose field
 * holds one of the listed values.
 *
 * @param {object} action A resolved row action.
 * @param {object} row The row.
 * @return {boolean}
 */
export function offersRowAction(action, row) {
	if (!isEndpointRowAction(action) || !action.rowWhen) {
		return true
	}
	const { field, in: allowed } = action.rowWhen
	if (typeof field !== 'string' || !Array.isArray(allowed) || !row) {
		return false
	}
	return allowed.includes(row[field])
}

/**
 * The notice a row carries under the collection's `noticeField`, or ''.
 *
 * @param {object} collection The collection.
 * @param {object} row The row.
 * @return {string}
 */
export function rowNotice(collection, row) {
	const field = collection && collection.noticeField
	if (typeof field !== 'string' || !row) {
		return ''
	}
	const value = row[field]
	return typeof value === 'string' ? value.trim() : ''
}

/**
 * Where the browser goes after a forward: the answer's `redirectUrl` or
 * `checkoutUrl`, only from a 2xx answer and only when it is an absolute
 * `https:` URL. Anything else (`javascript:`, `http:`, a relative path, a
 * malformed value) is refused with null.
 *
 * @param {{ok: boolean, status: number, body: object}} result The forward's result envelope.
 * @return {string|null}
 */
export function redirectTarget(result) {
	if (!result || !result.ok || !result.body) {
		return null
	}
	const candidate = result.body.redirectUrl ?? result.body.checkoutUrl
	if (typeof candidate !== 'string' || candidate === '') {
		return null
	}
	let url
	try {
		url = new URL(candidate)
	} catch {
		return null
	}
	return url.protocol === 'https:' ? url.href : null
}

/**
 * Whether a 2xx answer tried to send the browser somewhere it may not go.
 *
 * @param {{ok: boolean, body: object}} result The forward's result envelope.
 * @return {boolean}
 */
function refusedRedirect(result) {
	const body = result.body || {}
	return (
		(body.redirectUrl !== undefined || body.checkoutUrl !== undefined)
		&& redirectTarget(result) === null
	)
}

/**
 * The English source string (the i18n key) for a forward's answer.
 *
 * @param {{ok: boolean, status: number, body: object}} result The forward's result envelope.
 * @return {string}
 */
export function outcomeKey(result) {
	if (result && result.ok) {
		return refusedRedirect(result)
			? 'The next page could not be opened.'
			: 'Done.'
	}
	const status = result ? result.status : 0
	if (status === 403 || status === 404 || status === 409) {
		return 'This can no longer be done for this item.'
	}
	return 'This is not available right now. Try again later.'
}

/**
 * The link an action answered with (my-dossiers REQ-MYD-004), for example a
 * dossier's share link: https or a path on this instance, else ''.
 *
 * @param {object} result `{ok, body}` from a forward.
 * @return {string} The link, or ''.
 * @spec openspec/changes/my-dossiers/specs/portal-contribution-contract/spec.md#requirement-a-link-in-an-actions-answer-must-be-shown-to-the-resident-req-myd-004
 */
export function answerLink(result) {
	if (
		!result
		|| !result.ok
		|| !result.body
		|| typeof result.body.link !== 'string'
	) {
		return ''
	}
	const link = result.body.link.trim()
	if (/^https:\/\/\S+$/i.test(link) || /^\/(?!\/)\S*$/.test(link)) {
		return link
	}
	return ''
}

/**
 * Run one endpoint row action for one row and decide what the screen does:
 * go to `redirect`, or show the message `messageKey`, and any `link` the
 * action answered with. Only the row's id and the action's id are sent; the
 * server stamps the rest.
 *
 * @param {object} api The portal api (forwardRowAction).
 * @param {object} collection The collection the row belongs to.
 * @param {object} row The row.
 * @param {object} action The endpoint row action.
 * @return {Promise<{redirect: string|null, messageKey: string, link: string}>}
 */
export async function runRowAction(api, collection, row, action) {
	const rowId = row && (row.id || row['@self']?.id)
	if (!rowId || !api) {
		return {
			redirect: null,
			messageKey: outcomeKey({ ok: false, status: 0, body: {} }),
		}
	}
	const result = await api.forwardRowAction(collection, rowId, action.id)
	const redirect = redirectTarget(result)
	return {
		redirect,
		messageKey: redirect ? '' : outcomeKey(result),
		link: answerLink(result),
	}
}

/**
 * Run one page-level endpoint action and decide what the screen does, the
 * same way a row action does: go to `redirect`, or show `messageKey`. The
 * portal forwards it by app and action id and signs the assertion; the leaf
 * app's answer is relayed, never discarded (#804, case-actions T09).
 *
 * @param {object} api The portal api (forwardAction).
 * @param {string} app The contributing app.
 * @param {object} action The endpoint action.
 * @return {Promise<{redirect: string|null, messageKey: string}>}
 */
export async function runAction(api, app, action) {
	if (!api || !app || !action || !action.id) {
		return {
			redirect: null,
			messageKey: outcomeKey({ ok: false, status: 0, body: {} }),
		}
	}
	const result = await api.forwardAction(app, action.id, {})
	const redirect = redirectTarget(result)
	return { redirect, messageKey: redirect ? '' : outcomeKey(result) }
}
