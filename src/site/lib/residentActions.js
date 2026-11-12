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
 * @spec openspec/specs/portal-federated-search/spec.md#requirement-the-save-actions-must-show-only-to-a-signed-in-resident-who-is-offered-them-req-wje-001
 */
import { submissionHeaders } from '../../shared/submissionKey.js'

/**
 * The defaults each block can override by prop. The opencatalogi lane names
 * the real action ids; these follow hydra `woo-citizen-journey` design C7.
 */
export const RESIDENT_ACTION_DEFAULTS = Object.freeze({
	app: 'opencatalogi',
	addAction: 'addToDossier',
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
 * @spec openspec/specs/portal-federated-search/spec.md#requirement-the-save-actions-must-show-only-to-a-signed-in-resident-who-is-offered-them-req-wje-001
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
 * @spec openspec/specs/portal-federated-search/spec.md#requirement-the-save-actions-must-show-only-to-a-signed-in-resident-who-is-offered-them-req-wje-001
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
 * @spec openspec/specs/portal-federated-search/spec.md#requirement-the-save-actions-must-show-only-to-a-signed-in-resident-who-is-offered-them-req-wje-001
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
 * @spec openspec/specs/portal-federated-search/spec.md#requirement-a-resident-must-be-able-to-keep-a-publication-or-one-document-in-a-dossier-req-wje-002
 */
export function dossierCollectionOf(manifest, appId, schema) {
	for (const contribution of contributionsOf(manifest, appId)) {
		const collection = collectionWithSchema(contribution, schema)
		if (collection !== null) {
			return {
				id: String(collection.id || ''),
				register: String(collection.register || ''),
				schema: String(collection.schema),
			}
		}
	}

	return null
}

/**
 * @param {object} contribution One contribution.
 * @param {string} schema The schema.
 * @return {object|null} Its first collection of that schema, or null.
 */
function collectionWithSchema(contribution, schema) {
	for (const collection of contribution.collections || []) {
		if (collection && collection.schema === schema) {
			return collection
		}
	}
	return null
}

/**
 * The contributions of one app in the manifest, in manifest order.
 *
 * @param {object|null} manifest The manifest.
 * @param {string} appId The contributing app.
 * @yields {object} Each of its contributions.
 */
function* contributionsOf(manifest, appId) {
	for (const contribution of (manifest && manifest.contributions) || []) {
		if (contribution && contribution.app === appId) {
			yield contribution
		}
	}
}

/**
 * The body that keeps a publication, or one of its documents, in a dossier:
 * an existing dossier by id, or a new one by title.
 *
 * @param {object} input `{collection?, title?, publication, attachment?}`.
 * @return {object} The action body.
 *
 * @spec openspec/specs/portal-federated-search/spec.md#requirement-a-resident-must-be-able-to-keep-a-publication-or-one-document-in-a-dossier-req-wje-002
 */
export function addToCollectionBody(input) {
	const target = input.collection
		? { collection: String(input.collection) }
		: { title: String(input.title || '').trim() }

	return {
		...target,
		publication: String(input.publication || ''),
		attachment: attachmentOf(input.attachment),
	}
}

/**
 * @param {unknown} attachment The document to keep, if one.
 * @return {string|null} Its id, or null to keep the whole publication.
 */
function attachmentOf(attachment) {
	return attachment === null || attachment === undefined || attachment === ''
		? null
		: String(attachment)
}

/**
 * The body that saves a search. Frequency is one of the three C2 values and
 * defaults to daily.
 *
 * @param {object} input `{title, frequency?, query}`.
 * @return {object} The action body.
 *
 * @spec openspec/specs/portal-federated-search/spec.md#requirement-a-resident-must-be-able-to-save-the-current-search-req-wje-003
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
 * @param {object}   request          The action to run.
 * @param {string}   request.authBase The portal API base (`.../portal/api`).
 * @param {string}   request.appId    The contributing app.
 * @param {string}   request.actionId The action.
 * @param {object}   request.body     The body.
 * @param {string}   request.token    The portal bearer.
 * @param {{submissionKey?: string}} [request.options] The form's submission key (REQ-AFS-001).
 * @param {typeof fetch} fetchImpl    `fetch`, replaceable in tests.
 * @return {Promise<object>} `{ok, status, body}`.
 *
 * @spec openspec/specs/portal-federated-search/spec.md#requirement-a-resident-must-be-able-to-keep-a-publication-or-one-document-in-a-dossier-req-wje-002
 */
export async function postAction(request, fetchImpl = fetch) {
	const { authBase, appId, actionId } = request
	try {
		const response = await fetchImpl(
			`${authBase}/actions/${encodeURIComponent(appId)}/${encodeURIComponent(actionId)}`,
			actionInit(request),
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
 * The POST of one resident action: the bearer, the form's submission key and
 * the body as JSON.
 *
 * @param {{body: object, token: string, options?: {submissionKey?: string}}} request The action to run.
 * @return {object} The request options.
 */
function actionInit(request) {
	const { body, token, options = {} } = request
	return {
		method: 'POST',
		headers: { ...headersFor(token), ...submissionHeaders(options) },
		body: JSON.stringify(body || {}),
	}
}

/**
 * The `forwardAction` half of the portal api, on top of `postAction`, so an
 * endpoint or cta action on a site page forwards the same way the save
 * buttons do (`rowAction.runAction` takes it as its `api`).
 *
 * @param {string}   authBase  The portal API base (`.../portal/api`).
 * @param {() => string} token Returns the current portal bearer.
 * @param {typeof fetch} fetchImpl `fetch`, replaceable in tests.
 * @return {{forwardAction: (appId: string, actionId: string, body: object, options?: object) => Promise<object>}} The adapter.
 *
 * @spec openspec/specs/site-portal-parity/spec.md#requirement-an-endpoint-action-must-show-its-answer-or-follow-its-redirect-req-srp-027
 */
export function forwardApi(authBase, token, fetchImpl = fetch) {
	return {
		forwardAction(appId, actionId, body, options = {}) {
			return postAction(
				{ authBase, appId, actionId, body, token: token(), options },
				fetchImpl,
			)
		},
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
 * @spec openspec/specs/portal-federated-search/spec.md#requirement-the-save-actions-must-show-only-to-a-signed-in-resident-who-is-offered-them-req-wje-001
 */
export async function getJson(url, token, fetchImpl = fetch) {
	try {
		const response = await fetchImpl(url, { headers: headersFor(token) })
		return response.ok === true ? await response.json() : null
	} catch {
		return null
	}
}
