// SPDX-License-Identifier: EUPL-1.2
//
// The documents on a resident's case, grouped the way the case screen shows
// them (cases-documents-on-the-case): the decision first, then the other
// documents the organisation published, then what the resident sent. The
// server already put decisions first and newest first; this only splits.
//
// @spec openspec/changes/cases-documents-on-the-case/specs/citizen-case-documents/spec.md#requirement-the-decision-is-shown-first-req-cdc-003

/**
 * @param {Array<object>|undefined} listed The server's `documents`.
 * @return {{decisions: Array<object>, documents: Array<object>, yours: Array<object>, empty: boolean}}
 */
export function groupDocuments(listed) {
	const entries = Array.isArray(listed) ? listed : []
	return {
		decisions: entries.filter((e) => e.kind === 'decision'),
		documents: entries.filter((e) => e.kind === 'document'),
		yours: entries.filter((e) => e.kind === 'yours'),
		empty: entries.length === 0,
	}
}
