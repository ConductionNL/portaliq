// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * The signed-in resident's actions on the public site: keep a publication in
 * a dossier, save a search (woo-journey-entry-points, contract C7).
 *
 * Pure where it can be, so a plain node test asserts it; the one network call
 * takes its `fetch` as a parameter for the same reason.
 *
 * The actions themselves are opencatalogi's. The site calls them through the
 * portal's own forward, `POST /portal/api/actions/{appId}/{actionId}`, with the
 * resident's bearer; the portal forwards only an action the resident's own
 * manifest offers. So this file never decides what a resident may do: it only
 * hides a button whose action the manifest does not offer.
 *
 * @spec openspec/changes/woo-journey-entry-points/specs/portal-federated-search/spec.md#requirement-the-save-actions-must-show-only-to-a-signed-in-resident-who-is-offered-them-req-wje-001
 */

/**
 * The defaults each block can override by prop. The opencatalogi lane names
 * the real action ids; these follow hydra `woo-citizen-journey` design C7.
 */
export const RESIDENT_ACTION_DEFAULTS = Object.freeze({
	app: 'opencatalogi',
	addAction: 'addToCollection',
	saveSearchAction: 'saveSearch',
	dossierSchema: 'collection',
})

/**
 * Hand the signed-in state to a block AFTER its authored props, so page
 * configuration can never switch the save actions on.
 *
 * @param {object}  props    The authored props.
 * @param {boolean} signedIn Whether the shell holds a portal session.
 * @return {object} The props with `signedIn` set by the host.
 *
 * @spec openspec/changes/woo-journey-entry-points/specs/portal-federated-search/spec.md#requirement-the-save-actions-must-show-only-to-a-signed-in-resident-who-is-offered-them-req-wje-001
 */
export function withSignedIn(props, signedIn) {
	return { ...props, signedIn: signedIn === true }
}

/**
 * The action ids one app offers in the resident's manifest.
 *
 * @param {object} manifest The answer of `GET /portal/api/contributions`.
 * @param {string} appId    The contributing app.
 * @return {Set<string>} The offered action ids.
 *
 * @spec openspec/changes/woo-journey-entry-points/specs/portal-federated-search/spec.md#requirement-the-save-actions-must-show-only-to-a-signed-in-resident-who-is-offered-them-req-wje-001
 */
export function offeredActions(manifest, appId) {
	const ids = new Set()
	for (const contribution of (manifest && manifest.contributions) || []) {
		if (!contribution || contribution.app !== appId) {
			continue
		}
		for (const action of contribution.actions || []) {
			if (action && typeof action.id === 'string') {
				ids.add(action.id)
			}
		}
	}

	return ids
}

/**
 * Whether a save button shows.
 *
 * @param {boolean}     signedIn Whether the shell holds a portal session.
 * @param {Set<string>} offered  The offered action ids.
 * @param {string}      actionId The button's action.
 * @return {boolean} True only when both hold.
 *
 * @spec openspec/changes/woo-journey-entry-points/specs/portal-federated-search/spec.md#requirement-the-save-actions-must-show-only-to-a-signed-in-resident-who-is-offered-them-req-wje-001
 */
export function saveVisible(signedIn, offered, actionId) {
	return signedIn === true && offered instanceof Set && offered.has(actionId)
}

/**
 * The resident's dossier collection as the manifest declares it.
 *
 * @param {object} manifest The manifest.
 * @param {string} appId    The contributing app.
 * @param {string} schema   The dossier schema.
 * @return {object|null} `{id, register, schema}`, or null.
 *
 * @spec openspec/changes/woo-journey-entry-points/specs/portal-federated-search/spec.md#requirement-a-resident-must-be-able-to-keep-a-publication-or-one-document-in-a-dossier-req-wje-002
 */
export function dossierCollectionOf(manifest, appId, schema) {
	for (const contribution of (manifest && manifest.contributions) || []) {
		if (!contribution || contribution.app !== appId) {
			continue
		}
		for (const collection of contribution.collections || []) {
			if (collection && collection.schema === schema) {
				return {
					id: String(collection.id || ''),
					register: String(collection.register || ''),
					schema: String(collection.schema),
				}
			}
		}
	}

	return null
}

/**
 * The body that keeps a publication, or one of its documents, in a dossier:
 * an existing dossier by id, or a new one by title.
 *
 * @param {object} input `{collection?, title?, publication, attachment?}`.
 * @return {object} The action body.
 *
 * @spec openspec/changes/woo-journey-entry-points/specs/portal-federated-search/spec.md#requirement-a-resident-must-be-able-to-keep-a-publication-or-one-document-in-a-dossier-req-wje-002
 */
export function addToCollectionBody(input) {
	const attachment =
		input.attachment === null
		|| input.attachment === undefined
		|| input.attachment === ''
			? null
			: String(input.attachment)
	const target = input.collection
		? { collection: String(input.collection) }
		: { title: String(input.title || '').trim() }

	return { ...target, publication: String(input.publication || ''), attachment }
}

/**
 * The body that saves a search. Frequency is one of the three C2 values and
 * defaults to daily.
 *
 * @param {object} input `{title, frequency?, query}`.
 * @return {object} The action body.
 *
 * @spec openspec/changes/woo-journey-entry-points/specs/portal-federated-search/spec.md#requirement-a-resident-must-be-able-to-save-the-current-search-req-wje-003
 */
export function saveSearchBody(input) {
	const frequency = ['immediate', 'daily', 'weekly'].includes(input.frequency)
		? input.frequency
		: 'daily'

	return { title: String(input.title || '').trim(), frequency, query: input.query }
}

/**
 * The request headers for the resident's bearer.
 *
 * @param {string} token The portal bearer.
 * @return {object} The headers.
 */
function headersFor(token) {
	const headers = {
		Accept: 'application/json',
		'Content-Type': 'application/json',
	}
	if (token) {
		headers.Authorization = `Bearer ${token}`
	}

	return headers
}

/**
 * Run one of the resident's actions through the portal's forward.
 *
 * Never throws: a network failure is `{ok: false, status: 0}`, so the block
 * says it did not work instead of breaking.
 *
 * @param {string}   authBase  The portal API base (`.../portal/api`).
 * @param {string}   appId     The contributing app.
 * @param {string}   actionId  The action.
 * @param {object}   body      The body.
 * @param {string}   token     The portal bearer.
 * @param {Function} fetchImpl `fetch`, replaceable in tests.
 * @return {Promise<object>} `{ok, status, body}`.
 *
 * @spec openspec/changes/woo-journey-entry-points/specs/portal-federated-search/spec.md#requirement-a-resident-must-be-able-to-keep-a-publication-or-one-document-in-a-dossier-req-wje-002
 */
export async function postAction(
	authBase,
	appId,
	actionId,
	body,
	token,
	fetchImpl = fetch,
) {
	try {
		const response = await fetchImpl(
			`${authBase}/actions/${encodeURIComponent(appId)}/${encodeURIComponent(actionId)}`,
			{
				method: 'POST',
				headers: headersFor(token),
				body: JSON.stringify(body || {}),
			},
		)
		const answer = await response.json().catch(() => ({}))
		return {
			ok: response.ok === true,
			status: response.status,
			body: answer || {},
		}
	} catch {
		return { ok: false, status: 0, body: {} }
	}
}

/**
 * Read one of the resident's portal endpoints, or null on any failure.
 *
 * @param {string}   url       The URL.
 * @param {string}   token     The portal bearer.
 * @param {Function} fetchImpl `fetch`, replaceable in tests.
 * @return {Promise<object|null>} The answer.
 *
 * @spec openspec/changes/woo-journey-entry-points/specs/portal-federated-search/spec.md#requirement-the-save-actions-must-show-only-to-a-signed-in-resident-who-is-offered-them-req-wje-001
 */
export async function getJson(url, token, fetchImpl = fetch) {
	try {
		const response = await fetchImpl(url, { headers: headersFor(token) })
		return response.ok === true ? await response.json() : null
	} catch {
		return null
	}
}
