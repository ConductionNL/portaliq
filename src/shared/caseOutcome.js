// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The outcome an ended case shows (case-outcome-on-the-case-page). The
// collection names which projected field holds the public result label and
// which its explanation; the server sends that declaration with the case. The
// page shows the label only once the case has ended, and never a value that is
// a reference rather than words: a uuid, a URL or a `$ref` object.
//
// Imports nothing, so tests/case-outcome.spec.mjs reads it as node.
//
// @spec openspec/changes/case-outcome-on-the-case-page/specs/citizen-writes-on-their-own-case/spec.md#requirement-an-ended-case-shows-its-outcome-with-the-organisations-explanation-req-coc-001

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i
const URL_LIKE = /^(?:[a-z][a-z0-9+.-]*:\/\/|www\.)/i

/**
 * A value as words a resident can read, or '' when it is empty or a reference.
 *
 * @param {unknown} value A field value from the case.
 * @return {string} The trimmed text, or ''.
 */
export function outcomeText(value) {
	if (typeof value !== 'string') {
		return ''
	}
	const text = value.trim()
	if (text === '' || UUID.test(text) || URL_LIKE.test(text)) {
		return ''
	}
	return text
}

/**
 * The outcome to show, or null.
 *
 * @param {object|undefined} caseRow The case as the screen received it.
 * @param {object|null|undefined} declaration The server's `outcome`: `{labelField, descriptionField}`.
 * @param {boolean} ended Whether the case has ended.
 * @return {{label: string, description: string}|null} The label with its explanation ('' when none), or null.
 */
export function caseOutcome(caseRow, declaration, ended) {
	if (ended !== true || !caseRow || !declaration || typeof declaration.labelField !== 'string') {
		return null
	}
	const label = outcomeText(caseRow[declaration.labelField])
	if (label === '') {
		return null
	}
	const description = typeof declaration.descriptionField === 'string'
		? outcomeText(caseRow[declaration.descriptionField])
		: ''
	return { label, description }
}
