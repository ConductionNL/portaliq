// SPDX-License-Identifier: EUPL-1.2
//
// Schema-/manifest-driven collection table (Phase 3). Columns come from the
// contribution's `columns` UI-config (contribution-manifest-v3) when declared,
// else from the row keys; `render` per column maps to a lightweight cell
// formatter. Presentation-only — the rows are already subject-scoped and
// field-projected server-side, so a column naming a projected-away field simply
// renders blank (never leaks).

import React from 'react'

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

export default function CollectionTable({ collection, objects, loading, onSelect }) {
	if (loading) {
		return <p className="portaliq-loading">…</p>
	}
	if (!objects || objects.length === 0) {
		return <p className="portaliq-empty"><em>{collection.kind === 'inbox' ? 'Geen berichten.' : 'Geen items.'}</em></p>
	}

	const columns = deriveColumns(collection, objects)

	return (
		<table className="portaliq-table">
			<thead>
				<tr>
					{columns.map((c) => (
						<th key={c.field}>{c.label || c.field}</th>
					))}
				</tr>
			</thead>
			<tbody>
				{objects.map((row, i) => {
					const id = row.id || row['@self']?.id || i
					return (
						<tr
							key={id}
							className={onSelect ? 'portaliq-row-clickable' : undefined}
							onClick={onSelect ? () => onSelect(row) : undefined}
						>
							{columns.map((c) => (
								<td key={c.field}>
									{c.render === 'badge'
										? <span className={`portaliq-badge portaliq-badge-${String(row[c.field] || '').toLowerCase()}`}>{formatCell(row[c.field], 'text')}</span>
										: formatCell(row[c.field], c.render)}
								</td>
							))}
						</tr>
					)
				})}
			</tbody>
		</table>
	)
}
