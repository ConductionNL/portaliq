// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * The admin settings' "Actions" section: which groups may do which action.
 * Pure around an http client so a plain node test can drive it.
 *
 * @spec openspec/changes/operate-roles-for-content-and-actions/specs/portal-admin-roles/spec.md#requirement-an-administrator-grants-an-action-to-a-group-on-screen-req-ora-001
 */

/**
 * Turn the server's answer into rows for the picker: each action with its
 * groups as the picker's own `{id, label}` options. A group that no longer
 * exists stays visible as a bare id.
 *
 * @param {{actions?: Array<object>, availableGroups?: Array<object>}} data The server's answer.
 * @return {{rows: Array<object>, groupOptions: Array<object>}} The rows and the options.
 *
 * @spec openspec/changes/operate-roles-for-content-and-actions/specs/portal-admin-roles/spec.md#requirement-an-administrator-grants-an-action-to-a-group-on-screen-req-ora-001
 */
export function grantRows(data) {
	const groupOptions = Array.isArray(data?.availableGroups)
		? data.availableGroups
		: []
	const rows = (Array.isArray(data?.actions) ? data.actions : []).map((entry) => ({
		action: entry.action,
		label: entry.label || entry.action,
		description: entry.description || '',
		groups: (entry.groups || []).map(
			(id) =>
				groupOptions.find((option) => option.id === id) || { id, label: id },
		),
	}))

	return { rows, groupOptions }
}

/**
 * The body of a save: action to group ids.
 *
 * @param {Array<{action: string, groups: Array<{id: string}>}>} rows The rows.
 * @return {{grants: Record<string, string[]>}} The body.
 *
 * @spec openspec/changes/operate-roles-for-content-and-actions/specs/portal-admin-roles/spec.md#requirement-an-administrator-grants-an-action-to-a-group-on-screen-req-ora-001
 */
export function grantsBody(rows) {
	const grants = {}
	for (const row of rows) {
		grants[row.action] = row.groups.map((group) => group.id)
	}

	return { grants }
}

/**
 * Load the grants.
 *
 * @param {{get: Function}} http The http client (axios).
 * @param {string} url The route.
 * @return {Promise<{rows: Array<object>, groupOptions: Array<object>}>} The rows.
 *
 * @spec openspec/changes/operate-roles-for-content-and-actions/specs/portal-admin-roles/spec.md#requirement-an-administrator-grants-an-action-to-a-group-on-screen-req-ora-001
 */
export async function loadGrants(http, url) {
	const { data } = await http.get(url)
	return grantRows(data)
}

/**
 * Save the grants.
 *
 * @param {{put: Function}} http The http client (axios).
 * @param {string} url The route.
 * @param {Array<object>} rows The rows.
 * @return {Promise<{rows: Array<object>, groupOptions: Array<object>}>} The rows as stored.
 *
 * @spec openspec/changes/operate-roles-for-content-and-actions/specs/portal-admin-roles/spec.md#requirement-an-administrator-grants-an-action-to-a-group-on-screen-req-ora-001
 */
export async function saveGrants(http, url, rows) {
	const { data } = await http.put(url, grantsBody(rows))
	return grantRows(data)
}
