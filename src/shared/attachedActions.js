// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * Another app's action on a collection's detail (woo-journey-entry-points
 * D3): pipelinq's question and dossiq's Woo request on opencatalogi's dossier.
 *
 * The server lists them on the collection as `attachedActions`; the forward
 * goes through the row-action route with `actionApp`, which proves the row
 * through the collection's own scope before the action's app hears of it.
 *
 * @spec openspec/changes/woo-journey-entry-points/specs/portal-contribution-contract/spec.md#requirement-an-endpoint-action-must-be-able-to-attach-to-another-apps-collection-req-wje-004
 */

import { outcomeKey } from './rowAction.js'

/**
 * Whether an attached action applies to a row: always without `rowWhen`,
 * else only when the row's field holds one of the listed values. A malformed
 * `rowWhen` applies to no row. The server checks the same on the row it read
 * (attach-to-own-collection REQ-ATO-002); this keeps the screen honest.
 *
 * @param {object} entry The attached action.
 * @param {object} row   The row on screen.
 * @return {boolean}
 * @spec openspec/changes/attach-to-own-collection/specs/portal-contribution-contract/spec.md#requirement-an-attached-action-must-carry-its-rowwhen-to-the-renderer-req-ato-002
 */
function appliesTo(entry, row) {
	if (!entry.rowWhen) {
		return true
	}
	const { field, in: allowed } = entry.rowWhen
	if (typeof field !== 'string' || !Array.isArray(allowed)) {
		return false
	}
	return allowed.includes(row[field])
}

/**
 * The well-formed attached actions of a collection, and with a row, only the
 * ones that apply to it.
 *
 * @param {object} collection The manifest collection.
 * @param {object} [row]      The row on screen; without it nothing is left out.
 * @return {Array<object>} Entries with string `app` and `id`.
 * @spec openspec/changes/attach-to-own-collection/specs/portal-contribution-contract/spec.md#requirement-an-attached-action-must-carry-its-rowwhen-to-the-renderer-req-ato-002
 */
export function attachedActionsOf(collection, row) {
	const listed =
		collection && Array.isArray(collection.attachedActions)
			? collection.attachedActions
			: []
	return listed.filter(
		(entry) =>
			entry
			&& typeof entry.app === 'string'
			&& typeof entry.id === 'string'
			&& (!row || appliesTo(entry, row)),
	)
}

/**
 * The forwarded body: the declared fields only, trimmed.
 *
 * @param {object} action The attached action.
 * @param {object} values What the resident typed.
 * @return {object} The body.
 */
export function attachedBody(action, values) {
	const body = {}
	for (const field of Array.isArray(action.fields) ? action.fields : []) {
		if (typeof field === 'string') {
			body[field] = String((values || {})[field] ?? '').trim()
		}
	}
	return body
}

/**
 * The label of one field.
 *
 * @param {object} action The attached action.
 * @param {string} field  The field.
 * @return {string} The label.
 */
export function fieldLabel(action, field) {
	const config = (action.fieldConfigs || {})[field] || {}
	return typeof config.label === 'string' && config.label !== ''
		? config.label
		: field
}

/**
 * Forward the attached action for one row.
 *
 * @param {object} api        The portal api.
 * @param {object} collection The target collection.
 * @param {object} row        The row on screen.
 * @param {object} action     The attached action.
 * @param {object} values     What the resident typed.
 * @return {Promise<object>} `{ok, body, messageKey}`.
 */
export async function runAttachedAction(api, collection, row, action, values) {
	const rowId = row && (row.id || row['@self']?.id)
	if (!rowId || !api) {
		return {
			ok: false,
			body: {},
			messageKey: outcomeKey({ ok: false, status: 0, body: {} }),
		}
	}
	const result = await api.forwardRowAction(
		collection,
		rowId,
		action.id,
		attachedBody(action, values),
		action.app,
	)
	return {
		ok: result.ok === true,
		body: result.body || {},
		messageKey: outcomeKey(result),
	}
}
