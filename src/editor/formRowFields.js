/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The field list of the `form` row a `form` widget is bound to, read and
 * written from the page designer's inspector (site-nlds-widget-palette T11b,
 * decision of 24 Nov 2026: option b).
 *
 * WHY THE ROW. The anonymous submit accepts only the field ids on the bound
 * `form` row (`FormSubmitActions::fieldIdsOf()`), never the widget's own list.
 * So an editor who adds a field must add it to the row, and only someone
 * OpenRegister lets write that row can widen what an anonymous form writes:
 * the write goes straight to OpenRegister (ADR-022) and a refusal there is
 * shown as such, never worked around here.
 *
 * The HTTP calls are handed in (`@nextcloud/axios` in the app, a fake in
 * tests/form-row-fields.spec.mjs), so this module runs anywhere.
 *
 * @spec openspec/changes/site-nlds-widget-palette/specs/portal-page-designer/spec.md#requirement-form-fields-must-be-added-inside-a-form-widget-req-snw-003
 */

import { fieldList } from '../lib/formFieldTypes.js'
import { createPageSaver, isConflict } from './pageSaver.js'

/**
 * The id of the `form` row a widget is bound to.
 *
 * @param {object|null|undefined} props The widget's props.
 * @return {string} The trimmed `formId`, or '' for an unbound widget.
 *
 * @spec openspec/changes/site-nlds-widget-palette/specs/portal-page-designer/spec.md#requirement-form-fields-must-be-added-inside-a-form-widget-req-snw-003
 */
export function boundFormId(props) {
	const formId = props?.formId
	return typeof formId === 'string' ? formId.trim() : ''
}

/**
 * Why a read or a write of the row did not go through.
 *
 * @param {*} error The failure.
 * @return {string} `conflict` (someone else saved the row), `forbidden`
 *   (OpenRegister refused this user), `missing` (no such row) or `failed`.
 *
 * @spec openspec/changes/site-nlds-widget-palette/specs/portal-page-designer/spec.md#requirement-form-fields-must-be-added-inside-a-form-widget-req-snw-003
 */
export function refusalOf(error) {
	if (isConflict(error)) {
		return 'conflict'
	}
	const status = error?.response?.status
	if (status === 401 || status === 403) {
		return 'forbidden'
	}
	if (status === 404) {
		return 'missing'
	}
	return 'failed'
}

/**
 * Whether the inspector may edit the bound row's fields.
 *
 * @param {object|null} formRow A state from `load()` or `save()`, or null for an unbound widget.
 * @return {boolean} True for an unbound widget or a row that was read and not refused.
 *
 * @spec openspec/changes/site-nlds-widget-palette/specs/portal-page-designer/spec.md#requirement-form-fields-must-be-added-inside-a-form-widget-req-snw-003
 */
export function formRowEditable(formRow) {
	return formRow === null || formRow?.state === 'ready'
}

/**
 * A client for a bound form row's fields over injected HTTP calls.
 *
 * Every state it returns has the shape
 * `{ state, formId, row, version, fields }`, where `state` is `ready` or one
 * of the refusals of `refusalOf()`. A refused save keeps the fields that were
 * stored, so the inspector never shows a list the submit does not accept.
 *
 * @param {object} deps The collaborators.
 * @param {Function} deps.get (url) => Promise<{data}>
 * @param {Function} deps.put (url, payload, config) => Promise
 * @param {Function} deps.url (formId) => the `form` object URL
 * @return {{load: Function, save: Function}} The client.
 *
 * @spec openspec/changes/site-nlds-widget-palette/specs/portal-page-designer/spec.md#requirement-form-fields-must-be-added-inside-a-form-widget-req-snw-003
 */
export function createFormRowFields({ get, put, url }) {
	const saver = createPageSaver({ get, put, url })

	/**
	 * Read the row and its version marker.
	 *
	 * @param {string} formId The row's id.
	 * @return {Promise<object>} The state.
	 */
	async function load(formId) {
		try {
			const { page, version } = await saver.load(formId)
			return { state: 'ready', formId, row: page, version, fields: fieldList(page.fields) }
		} catch (error) {
			return { state: refusalOf(error), formId, row: null, version: '', fields: [] }
		}
	}

	/**
	 * Store FIELDS on the row read in CURRENT, refusing when the row changed
	 * since, then read it back so the next save checks the new version.
	 *
	 * @param {object} current A `ready` state from `load()` or `save()`.
	 * @param {Array<object>} fields The new field list.
	 * @return {Promise<object>} The new state; on a refusal the stored fields with the reason.
	 */
	async function save(current, fields) {
		if (current?.state !== 'ready' || !current.row) {
			return current
		}
		try {
			await saver.save(current.formId, { ...current.row, fields: fieldList(fields) }, current.version)
		} catch (error) {
			return { ...current, state: refusalOf(error) }
		}
		return load(current.formId)
	}

	return { load, save }
}
