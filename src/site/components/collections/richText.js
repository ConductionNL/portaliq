// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// A `richText` block's markdown as lines of text: `#`, `##` and `###`
// headings, and paragraphs. Nothing in it becomes markup; the same rules as
// src/portal/components/RichText.jsx.
//
// Imports nothing, so tests/rich-text.spec.mjs runs it as node.
//
// @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-rich-text-must-stay-text-only-req-srp-018

/**
 * The lines to render.
 *
 * @param {string} markdown The block's markdown.
 * @return {Array<{index: number, level: number, text: string}>} `level` 1 to 3 for a heading, 0 for a paragraph.
 */
export function richTextLines(markdown) {
	const out = []
	String(markdown || '')
		.split('\n')
		.forEach((line, index) => {
			const trimmed = line.trim()
			if (trimmed === '') {
				return
			}
			const heading = /^(#{1,3}) (.*)$/.exec(trimmed)
			if (heading) {
				out.push({ index, level: heading[1].length, text: heading[2] })
				return
			}
			out.push({ index, level: 0, text: trimmed })
		})
	return out
}
