// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The "My cases" list (cases-my-cases-page): every case from every
// contribution collection declared `kind: cases`, merged by the server. The
// server marks each row `_closed` from the field its collection declares
// (`closedField`), so the portal never guesses what closed means, and tags it
// with `_source` so a row can open on its own app's page.
//
// Imports nothing, so tests/my-cases-page.spec.mjs runs it as a plain node script.
//
// @spec openspec/changes/cases-my-cases-page/specs/portal-my-cases/spec.md

/**
 * The cases split into open and closed, each keeping the server's order.
 *
 * @param {Array<object>|null} cases The merged rows.
 * @return {{open: Array<object>, closed: Array<object>}} The two lists.
 *
 * @spec openspec/changes/cases-my-cases-page/specs/portal-my-cases/spec.md#requirement-open-and-closed-cases-are-told-apart-by-a-declared-field-req-cmc-002
 */
export function splitCases(cases) {
	const rows = Array.isArray(cases) ? cases : []
	return {
		open: rows.filter((row) => row?._closed !== true),
		closed: rows.filter((row) => row?._closed === true),
	}
}

/**
 * Where a case opens: its app, its collection and its id, or null when the
 * row does not say where it came from.
 *
 * @param {object} row The case row.
 * @return {{app: string, collection: string, id: string}|null} The target.
 *
 * @spec openspec/changes/cases-my-cases-page/specs/portal-my-cases/spec.md#requirement-a-case-opens-where-it-lives-req-cmc-005
 */
export function caseTarget(row) {
	const source = row?._source || {}
	const id = row?.id || row?.uuid || row?.['@self']?.id || ''
	if (!source.appId || !source.collection || !id) {
		return null
	}
	return {
		app: String(source.appId),
		collection: String(source.collection),
		id: String(id),
	}
}

/**
 * What a case is called in the list: its title, else its reference, else its id.
 *
 * @param {object} row The case row.
 * @return {string} The name.
 *
 * @spec openspec/changes/cases-my-cases-page/specs/portal-my-cases/spec.md#requirement-your-cases-from-every-app-in-one-list-req-cmc-001
 */
export function caseTitle(row) {
	for (const field of ['title', 'name', 'reference', 'identifier']) {
		const value = row?.[field]
		if (typeof value === 'string' && value.trim() !== '') {
			return value
		}
	}
	return String(row?.id || row?.uuid || row?.['@self']?.id || '')
}
