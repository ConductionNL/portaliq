// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * A record's item list (my-dossiers): a dossier's publications, as the
 * owning app lists them through `itemList.provider`, and removing one.
 *
 * @spec openspec/changes/my-dossiers/specs/portal-contribution-contract/spec.md#requirement-an-item-that-is-no-longer-public-must-say-so-req-myd-002
 */

/**
 * The rows to render from the items answer.
 *
 * @param {object} answer `{label, items, removeAction}` from the items route.
 * @return {Array<object>} `{id, title, href, note, notPublic}` rows.
 */
export function itemRows(answer) {
	const items = answer && Array.isArray(answer.items) ? answer.items : []
	return items
		.filter((item) => item && typeof item === 'object')
		.map((item) => ({
			id: String(item.id || ''),
			title: String(item.title || ''),
			href: typeof item.url === 'string' ? item.url : '',
			note: String(item.note || ''),
			notPublic: item.public === false,
		}))
}

/**
 * The row actions a collection's table shows, without the item list's remove
 * action: that one needs an item and lives on each item instead.
 *
 * @param {object} collection The collection.
 * @param {Array<object>} actions The resolved row actions.
 * @return {Array<object>} The actions for the table.
 */
export function withoutRemoveAction(collection, actions) {
	const remove =
		collection && collection.itemList ? collection.itemList.removeAction : ''
	return (actions || []).filter((action) => !remove || action.id !== remove)
}

/**
 * Remove one item from the record through the declared remove action.
 *
 * @param {object} api        The portal api.
 * @param {object} collection The collection.
 * @param {object} row        The record.
 * @param {string} itemId     The item.
 * @return {Promise<object>} `{ok, status}`.
 * @spec openspec/changes/my-dossiers/specs/portal-contribution-contract/spec.md#requirement-a-resident-must-be-able-to-remove-one-item-req-myd-003
 */
export async function removeItem(api, collection, row, itemId) {
	const action =
		collection && collection.itemList ? collection.itemList.removeAction : ''
	const rowId = row && (row.id || row['@self']?.id)
	if (!action || !rowId || !itemId || !api) {
		return { ok: false, status: 0 }
	}
	const result = await api.forwardRowAction(collection, rowId, action, { itemId })
	return { ok: result.ok === true, status: result.status }
}
