// SPDX-License-Identifier: EUPL-1.2
//
// Schema-/manifest-driven collection table (Phase 3). Columns come from the
// contribution's `columns` UI-config (contribution-manifest-v3) when declared,
// else from the row keys; `render` per column maps to a lightweight cell
// formatter. Presentation-only — the rows are already subject-scoped and
// field-projected server-side, so a column naming a projected-away field simply
// renders blank (never leaks).

import React from 'react'
import Loading from './Loading.jsx'

/**
 *
 * @param value
 * @param render
 */
function formatCell(value, render) {
	if (value === null || value === undefined || value === '') {
		return ''
	}
	switch (render) {
	case 'boolean':
		return value ? 'Ja' : 'Nee'
	case 'date':
		try { return new Date(value).toLocaleDateString('nl-NL') } catch (e) { return String(value) }
	case 'datetime':
		try { return new Date(value).toLocaleString('nl-NL') } catch (e) { return String(value) }
	case 'currency':
		try { return new Intl.NumberFormat('nl-NL', { style: 'currency', currency: 'EUR' }).format(Number(value)) } catch (e) { return String(value) }
	default:
		return String(value)
	}
}

// Derive columns: explicit manifest columns, else the union of row keys minus
// the OR envelope / identifiers.
/**
 *
 * @param collection
 * @param objects
 */
function deriveColumns(collection, objects) {
	if (Array.isArray(collection.columns) && collection.columns.length > 0) {
		return collection.columns
	}
	const skip = new Set(['@self', 'id', 'uuid'])
	const fields = []
	for (const row of objects) {
		for (const k of Object.keys(row || {})) {
			if (!skip.has(k) && !fields.includes(k)) {
				fields.push(k)
			}
		}
	}
	return fields.map((f) => ({ field: f, render: 'text' }))
}

// `rowActions` are resolved row actions: `type: update` transitions
// (contribution-manifest-v3), whose button sends no field data so the target
// can never be tampered with client-side, and endpoint row actions
// (contribution-pay-screen), which the caller confirms and forwards. `offers`
// decides per row whether an action applies (an endpoint action's `rowWhen`);
// without it every action shows on every row.
/**
 *
 * @param root0
 * @param root0.collection
 * @param root0.objects
 * @param root0.loading
 * @param root0.onSelect
 * @param root0.rowActions
 * @param root0.onRowAction
 * @param root0.busyRow
 * @param {object} [root0.selectedRow] The row open in the detail, marked with aria-current.
 * @param {(action: object, row: object) => boolean} [root0.offers] `(action, row) => boolean`: whether a row offers an action.
 */
export default function CollectionTable({ collection, objects, loading, onSelect, rowActions, onRowAction, busyRow, selectedRow, offers, t }) {
	if (loading) {
		return <Loading t={t} />
	}
	if (!objects || objects.length === 0) {
		return <p className="portaliq-empty"><em>{collection.kind === 'inbox' ? 'Geen berichten.' : 'Geen items.'}</em></p>
	}

	const columns = deriveColumns(collection, objects)
	const actions = rowActions || []

	return (
		<table className="portaliq-table">
			<thead>
				<tr>
					{columns.map((c) => (
						<th key={c.field}>{c.label || c.field}</th>
					))}
					{actions.length > 0 && <th className="portaliq-rowactions-head">Acties</th>}
				</tr>
			</thead>
			<tbody>
				{objects.map((row, i) => {
					const id = row.id || row['@self']?.id || i
					const selectedId = selectedRow ? (selectedRow.id || selectedRow['@self']?.id) : undefined
					const isSelected = selectedId !== undefined && selectedId === (row.id || row['@self']?.id)
					return (
						// The row click stays as a mouse convenience; the
						// keyboard path is the button in the first cell
						// (WCAG 2.1.1, portaliq#722). Enter and Space on a
						// <button> fire its click, and Tab reaches it.
						<tr
							key={id}
							className={onSelect ? 'portaliq-row-clickable' : undefined}
							onClick={onSelect ? () => onSelect(row) : undefined}
							aria-current={isSelected ? 'true' : undefined}
						>
							{columns.map((c, ci) => {
								const cell = c.render === 'badge'
									? <span className={`portaliq-badge portaliq-badge-${String(row[c.field] || '').toLowerCase()}`}>{formatCell(row[c.field], 'text')}</span>
									: formatCell(row[c.field], c.render)
								return (
									<td key={c.field}>
										{onSelect && ci === 0
											? (
												<button
													type="button"
													className="portaliq-row-select"
													onClick={(e) => { e.stopPropagation(); onSelect(row) }}
												>
													{cell === '' ? 'Openen' : cell}
												</button>
											)
											: cell}
									</td>
								)
							})}
							{actions.length > 0 && (
								<td className="portaliq-rowactions">
									{actions.filter((a) => !offers || offers(a, row)).map((a) => (
										<button
											key={a.id}
											type="button"
											disabled={busyRow === id}
											onClick={(e) => { e.stopPropagation(); onRowAction && onRowAction(a, row) }}
										>
											{a.label || a.id}
										</button>
									))}
								</td>
							)}
						</tr>
					)
				})}
			</tbody>
		</table>
	)
}
