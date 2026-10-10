// SPDX-License-Identifier: EUPL-1.2
//
// Endpoint row actions in the portal (contribution-pay-screen). Pure functions,
// no React and no fetch, so the decisions are tested on their own
// (tests/row-action.spec.mjs):
//
// - which action a row offers (the action's `rowWhen`, on an endpoint row
//   action and on a `type: update` transition alike),
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
 * Whether a row offers an action. A row action without `rowWhen` applies to
 * every row; with `rowWhen`, only a row whose field holds one of the listed
 * values. That holds for an endpoint row action and, since
 * update-row-action-condition, for a `type: update` transition too. A
 * malformed `rowWhen` applies to no row.
 *
 * For an update action this only decides the button: the leaf app's
 * lifecycle still refuses a transition the row does not allow.
 *
 * @param {object} action A resolved row action.
 * @param {object} row The row.
 * @return {boolean}
 * @spec openspec/changes/update-row-action-condition/specs/portal-contribution-contract/spec.md#requirement-an-update-row-action-must-be-shown-only-on-the-rows-its-rowwhen-names-req-urc-001
 */
export function offersRowAction(action, row) {
	if (!action) {
		return true
	}
	// `availableWhen` (case-actions-row-inputs-and-conditions): the button is
	// absent on a row where the named field does not equal the value. The
	// server refuses the forward on that row too.
	if (action.availableWhen && !isAvailable(action, row)) {
		return false
	}
	if (!action.rowWhen) {
		return true
	}
	const { field, in: allowed } = action.rowWhen
	if (typeof field !== 'string' || !Array.isArray(allowed) || !row) {
		return false
	}
	return allowed.includes(row[field])
}

/**
 * Whether the row satisfies the action's `availableWhen`. An action without
 * one is available on every row; a malformed one on none.
 *
 * @param {object} action A resolved row action.
 * @param {object} row The row.
 * @return {boolean}
 * @spec openspec/changes/case-actions-row-inputs-and-conditions/specs/portal-row-action-inputs/spec.md#requirement-a-row-action-can-be-offered-on-some-rows-only-req-rai-004
 */
export function isAvailable(action, row) {
	const when = action?.availableWhen
	if (!when) {
		return true
	}
	if (typeof when.field !== 'string' || !row) {
		return false
	}
	return row[when.field] === when.equals
}

/**
 * Why the action is not offered on this row: the text of its
 * `unavailableReasonField`, shown where the button would be. '' when the
 * action is available, or the row says nothing.
 *
 * @param {object} action A resolved row action.
 * @param {object} row The row.
 * @return {string} The reason.
 * @spec openspec/changes/case-actions-row-inputs-and-conditions/specs/portal-row-action-inputs/spec.md#requirement-a-row-action-can-be-offered-on-some-rows-only-req-rai-004
 */
export function unavailableReason(action, row) {
	if (!action?.availableWhen || isAvailable(action, row)) {
		return ''
	}
	const reason = row?.[action.unavailableReasonField]
	return typeof reason === 'string' ? reason.trim() : ''
}

/**
 * The inputs the selected row declares for the action, as the server accepts
 * them: a name, a label, whether it is required and its type.
 *
 * @param {object} action A resolved row action.
 * @param {object} row The row.
 * @return {Array<{name: string, label: string, required: boolean, type: string}>}
 * @spec openspec/changes/case-actions-row-inputs-and-conditions/specs/portal-row-action-inputs/spec.md#requirement-a-row-action-collects-the-inputs-its-row-declares-req-rai-002
 */
export function rowInputsOf(action, row) {
	const from = action?.rowInputs?.from
	const list =
		typeof from === 'string' && Array.isArray(row?.[from]) ? row[from] : []
	const seen = new Set()
	const out = []
	for (const entry of list) {
		const name = entry && typeof entry.name === 'string' ? entry.name : ''
		if (!/^[a-zA-Z][a-zA-Z0-9_]*$/.test(name) || seen.has(name)) {
			continue
		}
		seen.add(name)
		out.push({
			name,
			label:
				typeof entry.label === 'string' && entry.label ? entry.label : name,
			required: entry.required === true,
			type: entry.type === 'date' ? 'date' : 'text',
		})
	}
	return out
}

/**
 * What a forward's answer says to show: the target's own sentence on any
 * answer, and its messages for named inputs on a refusal.
 *
 * @param {object} result `{ok, status, body}` from a forward.
 * @return {{message: string, errors: Object<string, string>}}
 * @spec openspec/changes/case-actions-row-inputs-and-conditions/specs/portal-row-action-inputs/spec.md#requirement-the-resident-sees-what-happened-req-rai-003
 */
export function answerWords(result) {
	const body =
		result && typeof result.body === 'object' && result.body ? result.body : {}
	const errors = {}
	if (
		body.errors
		&& typeof body.errors === 'object'
		&& !Array.isArray(body.errors)
	) {
		for (const [name, value] of Object.entries(body.errors)) {
			if (typeof value === 'string' && value !== '') {
				errors[name] = value
			}
		}
	}
	return {
		message: typeof body.message === 'string' ? body.message.trim() : '',
		errors,
	}
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
 * The origin of the page the resident is on, or '' outside a browser.
 *
 * @return {string} For example `https://gemeente.example`.
 */
function pageOrigin() {
	const location = globalThis.location
	return location && typeof location.origin === 'string' ? location.origin : ''
}

/**
 * The link an action answered with (my-dossiers REQ-MYD-004), for example a
 * dossier's share link, else ''.
 *
 * The resident is asked to copy and pass this link on, so it must lead
 * somewhere safe: an https link, a path on this instance, or an absolute link
 * on the page's own origin. The last one is how opencatalogi answers a share
 * link (`http://localhost:8080/index.php/apps/portaliq/site?route=…` on a dev
 * or intranet instance), and it is instance-local just like a path. A
 * `javascript:` link, a foreign http link and anything malformed stay refused.
 *
 * @param {object} result `{ok, body}` from a forward.
 * @param {string} [origin] The page's own origin; defaults to the current page.
 * @return {string} The link, or ''.
 * @spec openspec/changes/my-dossiers/specs/portal-contribution-contract/spec.md#requirement-a-link-in-an-actions-answer-must-be-shown-to-the-resident-req-myd-004
 */
export function answerLink(result, origin = pageOrigin()) {
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
	if (origin !== '' && /^http:\/\/\S+$/i.test(link)) {
		let url
		try {
			url = new URL(link)
		} catch {
			return ''
		}
		return url.origin === origin ? link : ''
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
 * @param answers
 * @return {Promise<{redirect: string|null, messageKey: string, link: string}>}
 */
export async function runRowAction(api, collection, row, action, answers = {}) {
	const rowId = row && (row.id || row['@self']?.id)
	if (!rowId || !api) {
		return {
			redirect: null,
			messageKey: outcomeKey({ ok: false, status: 0, body: {} }),
		}
	}
	const result = await api.forwardRowAction(collection, rowId, action.id, answers)
	const redirect = redirectTarget(result)
	const link = answerLink(result)
	const words = answerWords(result)
	// `link`, `message` and `errors` only when the answer carried them, so
	// every other outcome keeps its shape.
	return {
		redirect,
		messageKey: redirect ? '' : outcomeKey(result),
		...(link ? { link } : {}),
		...(words.message ? { message: words.message } : {}),
		...(Object.keys(words.errors).length > 0 ? { errors: words.errors } : {}),
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
