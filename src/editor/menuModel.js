/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A portal menu's top-level items, edited from the portal.
 *
 * Pure functions that return new arrays. `order` is renumbered 0..n after
 * every change, so the stored order always equals what the editor sees. A
 * sub-menu (`items` of an item) travels with its item unchanged.
 *
 * @spec openspec/changes/portal-in-place-editing/specs/portal-in-place-editing/spec.md#requirement-an-editor-must-be-able-to-edit-the-portals-menu-from-the-portal-req-pie-011
 */

import { withoutEnvelope } from './pageBody.js'

/**
 * Copy and renumber.
 *
 * @param {Array<object>} items The items.
 * @return {Array<object>} The renumbered copy.
 */
function renumber(items) {
	return JSON.parse(JSON.stringify(items)).map((item, order) => ({ ...item, order }))
}

/**
 * The items in their stored order.
 *
 * @param {Array<object>} items The items as stored.
 * @return {Array<object>} The items sorted by `order`.
 * @spec openspec/changes/portal-in-place-editing/specs/portal-in-place-editing/spec.md#requirement-an-editor-must-be-able-to-edit-the-portals-menu-from-the-portal-req-pie-011
 */
export function sortedMenuItems(items) {
	return renumber(
		[...(items || [])].sort((a, b) => (Number(a.order) || 0) - (Number(b.order) || 0)),
	)
}

/**
 * Add an item at the end.
 *
 * @param {Array<object>} items The items.
 * @param {{name: string, link: string}} item The new item.
 * @return {Array<object>} The items.
 * @throws {Error} When the name is empty.
 * @spec openspec/changes/portal-in-place-editing/specs/portal-in-place-editing/spec.md#requirement-an-editor-must-be-able-to-edit-the-portals-menu-from-the-portal-req-pie-011
 */
export function addMenuItem(items, { name, link }) {
	const label = String(name || '').trim()
	if (!label) {
		throw new Error('A menu item needs a name.')
	}
	return renumber([...(items || []), { name: label, link: String(link || '').trim() }])
}

/**
 * Rename one item.
 *
 * @param {Array<object>} items The items.
 * @param {number} index The item.
 * @param {string} name The new name.
 * @return {Array<object>} The items.
 * @spec openspec/changes/portal-in-place-editing/specs/portal-in-place-editing/spec.md#requirement-an-editor-must-be-able-to-edit-the-portals-menu-from-the-portal-req-pie-011
 */
export function renameMenuItem(items, index, name) {
	const label = String(name || '').trim()
	if (!label) {
		throw new Error('A menu item needs a name.')
	}
	const next = renumber(items)
	next[index] = { ...next[index], name: label }
	return next
}

/**
 * Move one item up (-1) or down (+1); at an end it stays.
 *
 * @param {Array<object>} items The items.
 * @param {number} index The item.
 * @param {number} delta The direction.
 * @return {Array<object>} The items.
 * @spec openspec/changes/portal-in-place-editing/specs/portal-in-place-editing/spec.md#requirement-an-editor-must-be-able-to-edit-the-portals-menu-from-the-portal-req-pie-011
 */
export function moveMenuItem(items, index, delta) {
	const target = index + delta
	const next = [...items]
	if (target < 0 || target >= next.length) {
		return renumber(next)
	}
	const [item] = next.splice(index, 1)
	next.splice(target, 0, item)
	return renumber(next)
}

/**
 * Remove one item, with its sub-menu.
 *
 * @param {Array<object>} items The items.
 * @param {number} index The item.
 * @return {Array<object>} The items.
 * @spec openspec/changes/portal-in-place-editing/specs/portal-in-place-editing/spec.md#requirement-an-editor-must-be-able-to-edit-the-portals-menu-from-the-portal-req-pie-011
 */
export function removeMenuItem(items, index) {
	return renumber(items.filter((_item, i) => i !== index))
}

/**
 * The menu object to store with these items.
 *
 * @param {object} menu The menu as read.
 * @param {Array<object>} items The items.
 * @return {object} The menu to store.
 * @spec openspec/changes/portal-in-place-editing/specs/portal-in-place-editing/spec.md#requirement-an-editor-must-be-able-to-edit-the-portals-menu-from-the-portal-req-pie-011
 */
export function menuPayload(menu, items) {
	const payload = withoutEnvelope(menu)
	delete payload.id
	delete payload.version
	return { ...payload, items: renumber(items) }
}
