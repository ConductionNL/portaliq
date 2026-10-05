// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// The news article's lead and body (site-school-blocks). Imports nothing, so
// node tests it.

/**
 * The first paragraph as the lead, the rest as the body. A first block that
 * is a heading, a list or an image is not a lead, so the body keeps it.
 *
 * @param {string} body The markdown.
 * @return {{lead: string, rest: string}} The two parts.
 * @spec openspec/changes/site-school-blocks/specs/portaliq-cms/spec.md#requirement-a-news-article-page-shows-one-public-item-chosen-by-the-route
 */
export function splitLead(body) {
	const blocks = String(body ?? '')
		.trim()
		.split(/\n\s*\n/)
	const first = (blocks[0] || '').trim()
	if (first === '' || /^(#|[-*+]\s|\d+\.\s|!\[|>|\||```)/.test(first)) {
		return { lead: '', rest: blocks.join('\n\n').trim() }
	}
	return {
		lead: first.replace(/\s+/g, ' '),
		rest: blocks.slice(1).join('\n\n').trim(),
	}
}
