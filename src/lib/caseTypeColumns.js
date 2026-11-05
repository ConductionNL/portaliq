// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The logic behind "Columns in My cases" on a portal's Case types page
// (case-type-columns-in-my-cases): one row per field the case apps show,
// what a save sends, and where it goes.

/** The render kinds an administrator may choose, in the board's order. */
export const COLUMN_RENDERS = ['text', 'link', 'qr', 'date']

/**
 * The admin route for one case type's columns in one portal.
 *
 * @param {{slug: string, typeId: string}} target The portal slug and the case type id.
 * @param {(path: string) => string} generateUrl Nextcloud's URL generator.
 * @return {string} The URL.
 * @spec openspec/changes/case-type-columns-in-my-cases/specs/portal-case-type-visibility/spec.md#requirement-an-administrator-chooses-how-each-column-of-a-case-type-reads
 */
export function columnsUrl(target, generateUrl) {
	const slug = encodeURIComponent(target.slug)
	const typeId = encodeURIComponent(target.typeId)
	return generateUrl(`/apps/portaliq/api/portals/${slug}/case-types/${typeId}/columns`)
}

/**
 * One editable row per field, filled from what the case type has stored;
 * an empty `render` means the field reads as its case app declares.
 *
 * @param {Array<{field: string, label: string}>} fields The fields the case apps show.
 * @param {Array<object>} stored The case type's stored columns.
 * @return {Array<{field: string, label: string, render: string, linkLabel: string}>}
 * @spec openspec/changes/case-type-columns-in-my-cases/specs/portal-case-type-visibility/spec.md#requirement-an-administrator-chooses-how-each-column-of-a-case-type-reads
 */
export function editableColumns(fields, stored) {
	const byField = new Map((stored || []).map((column) => [column.field, column]))
	return (fields || []).map(({ field, label }) => ({
		field,
		label: label || field,
		render: byField.get(field)?.render || '',
		linkLabel: byField.get(field)?.linkLabel || '',
	}))
}

/**
 * Whether a render kind carries words of its own.
 *
 * @param {string} render The render kind.
 * @return {boolean}
 * @spec openspec/changes/case-type-columns-in-my-cases/specs/portal-case-type-visibility/spec.md#requirement-an-administrator-chooses-how-each-column-of-a-case-type-reads
 */
export function hasLinkLabel(render) {
	return render === 'link' || render === 'qr'
}

/**
 * The columns a save sends: the rows with a chosen render, each with its link
 * text when it is a link or QR code.
 *
 * @param {Array<object>} rows The edited rows.
 * @return {Array<{field: string, render: string, linkLabel?: string}>}
 * @spec openspec/changes/case-type-columns-in-my-cases/specs/portal-case-type-visibility/spec.md#requirement-an-administrator-chooses-how-each-column-of-a-case-type-reads
 */
export function columnsToSave(rows) {
	return (rows || [])
		.filter((row) => COLUMN_RENDERS.includes(row.render))
		.map((row) => {
			const label = String(row.linkLabel || '').trim()
			return hasLinkLabel(row.render) && label !== ''
				? { field: row.field, render: row.render, linkLabel: label }
				: { field: row.field, render: row.render }
		})
}
