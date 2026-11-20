// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// Whether a case row is closed, on its own so the resident menu's case counts
// in the site entry do not carry the rest of cases.js and casePage.js, which
// the case pages load on demand (public-bundle-budget).

/**
 * Whether a collection row is a closed case: its `_closed` mark when the
 * server stamped one, else a value in the collection's `closedField`.
 *
 * @param {object} row The case row.
 * @param {object} collection The `cases` collection.
 * @return {boolean}
 * @spec openspec/specs/site-mijn-omgeving/spec.md#requirement-a-case-card-must-show-what-a-resident-needs-to-decide-whether-to-open-it-req-smo-002
 */
export function isClosedCase(row, collection) {
	if (typeof row?._closed === 'boolean') {
		return row._closed
	}
	const field = collection?.closedField
	if (!field) {
		return false
	}
	return isFilledMark(row?.[field])
}

/**
 * Whether a closed field holds a mark: anything but nothing, '' or false.
 *
 * @param {unknown} value The field's value.
 * @return {boolean}
 */
function isFilledMark(value) {
	return value !== undefined && value !== null && value !== '' && value !== false
}
