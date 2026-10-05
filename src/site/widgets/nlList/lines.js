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
