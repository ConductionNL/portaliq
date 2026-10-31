// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// The list widget's lines (site-school-blocks). Imports nothing, so node
// tests it.

/**
 * The lines that have text, as `{title, text}`: a string is a title alone.
 *
 * @param {Array<string|object>} items The authored lines.
 * @return {Array<{title: string, text: string}>} The lines.
 * @spec openspec/changes/site-school-blocks/specs/portaliq-cms/spec.md#requirement-a-list-may-show-numbered-steps-with-a-title-and-a-line
 */
export function listLines(items) {
	return (Array.isArray(items) ? items : [])
		.map((item) =>
			item !== null && typeof item === 'object'
				? {
						title: String(item.title ?? '').trim(),
						text: String(item.text ?? '').trim(),
					}
				: { title: String(item ?? '').trim(), text: '' },
		)
		.filter((item) => item.title !== '')
}

/**
 * A line as a numbered step's lead and rest (board Contentpagina of
 * Esdoornveen, `display: numbered`): a `{title, text}` line is its title and
 * its text; a plain line is split after its first comma, question mark,
 * colon or full stop, so only "Meld je ziek op de eerste dag," is bold.
 *
 * @param {{title: string, text: string}} line One line from `listLines()`.
 * @return {{lead: string, rest: string}}
 * @spec openspec/changes/site-callouts-steps-and-tables-follow-the-boards/specs/site-look/spec.md#requirement-numbered-steps-may-be-compact-with-the-lead-in-bold
 */
export function stepLead(line) {
	if (line.text) {
		return { lead: line.title, rest: line.text }
	}
	const match = /^(.+?[,.?:!])\s+(\S[\s\S]*)$/.exec(line.title)
	return match
		? { lead: match[1], rest: match[2] }
		: { lead: line.title, rest: '' }
}
