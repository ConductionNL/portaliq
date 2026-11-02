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

/**
 * What the article's sign-up card does for this visitor: `closed` when the
 * event no longer takes answers, `signin` for a visitor without a session,
 * else `open`. A closed event wins, so the card never invites an answer the
 * server would refuse.
 *
 * @param {object|null} event The event facts from the content API.
 * @param {boolean} signedIn Whether the visitor holds a session.
 * @return {('none'|'closed'|'signin'|'open')} The card's state.
 * @spec openspec/changes/event-sign-up-by-a-pupil-with-seats/specs/portaliq-cms/spec.md#requirement-a-news-item-may-carry-the-sign-up-of-its-event
 */
export function eventCardState(event, signedIn) {
	if (!event || typeof event !== 'object') {
		return 'none'
	}
	if (event.closed === true) {
		return 'closed'
	}
	return signedIn === true ? 'open' : 'signin'
}

/**
 * The name of the resident area for the sign-in line ("Mijn Vaartveld"): the
 * authored name, else the portal's slug with a capital.
 *
 * @param {string} authored The authored name.
 * @param {string} portal The portal slug.
 * @return {string} "Mijn Vaartveld", or "Mijn omgeving" without a slug.
 * @spec openspec/changes/event-sign-up-by-a-pupil-with-seats/specs/portaliq-cms/spec.md#requirement-a-news-item-may-carry-the-sign-up-of-its-event
 */
export function areaName(authored, portal) {
	const name = String(authored ?? '').trim()
	if (name !== '') {
		return name
	}
	const slug = String(portal ?? '').trim()
	return slug === ''
		? 'Mijn omgeving'
		: `Mijn ${slug.charAt(0).toUpperCase()}${slug.slice(1)}`
}

/** A list item that is a fact: `- **Wanneer:** woensdag 14 oktober`. */
const FACT = /^[-*+]\s+\*\*([^*]+?):?\*\*:?\s+(.+)$/

/**
 * The body in parts: markdown, and the fact lists in it. A list whose every
 * item opens with a bold label ("**Wanneer:** ...") is a set of facts, which
 * the boards draw as a grey block of labels and values rather than as
 * bullets. Any other list, and every other block, stays markdown.
 *
 * @param {string} body The markdown after the lead.
 * @return {Array<{kind: 'markdown', source: string}|{kind: 'facts', items: Array<{term: string, value: string}>}>} The parts, in order.
 * @spec openspec/changes/site-article-page-follows-the-board/specs/site-look/spec.md#requirement-a-news-article-reads-like-the-article-board
 */
export function articleParts(body) {
	const parts = []
	const push = (block) => {
		const last = parts[parts.length - 1]
		if (last && last.kind === 'markdown') {
			last.source = `${last.source}\n\n${block}`
		} else {
			parts.push({ kind: 'markdown', source: block })
		}
	}
	for (const raw of String(body ?? '').split(/\n\s*\n/)) {
		const block = raw.trim()
		if (block === '') {
			continue
		}
		const lines = block.split('\n').map((line) => line.trim())
		const facts = lines.map((line) => FACT.exec(line))
		if (facts.every(Boolean)) {
			parts.push({
				kind: 'facts',
				items: facts.map((match) => ({
					term: match[1].trim(),
					value: match[2].trim(),
				})),
			})
		} else {
			push(block)
		}
	}
	return parts
}
