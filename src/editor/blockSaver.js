/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A shared block seen as a page, so the page designer can edit it
 * (site-shared-page-blocks).
 *
 * A block stores its grid as `widgets` and its unpublished work as
 * `draftWidgets`; the designer's core speaks `body` and `draftBody`. These two
 * functions translate at the edge, so the editor, its undo history, its
 * conflict check and its draft and publish flow stay one implementation. A
 * block with no `draftWidgets` key has no draft, as a page with no
 * `draftBody` has none: publishing removes the key.
 *
 * @spec openspec/changes/site-shared-page-blocks/tasks.md#t05
 */

/**
 * A stored block in the shape the page editor reads.
 *
 * @param {object} block The block as stored, with its envelope.
 * @return {object} The block as a page: `body`, and `draftBody` when there is a draft.
 */
export function blockToPage(block) {
	const { widgets, draftWidgets, ...rest } = block || {}
	const page = {
		...rest,
		route: '',
		portal: '',
		body: { type: 'grid', widgets: Array.isArray(widgets) ? widgets : [] },
	}
	if (Array.isArray(draftWidgets)) {
		page.draftBody = { type: 'grid', widgets: draftWidgets }
	}
	return page
}

/**
 * What the page editor wants stored, as a block.
 *
 * @param {object} payload The page-shaped payload the editor saves.
 * @return {object} The block to store: `widgets`, and `draftWidgets` when there is a draft.
 */
export function pageToBlock(payload) {
	const { body, draftBody, route, portal, ...rest } = payload || {}
	void route
	void portal
	const block = {
		...rest,
		widgets: Array.isArray(body?.widgets) ? body.widgets : [],
	}
	if (draftBody && Array.isArray(draftBody.widgets)) {
		block.draftWidgets = draftBody.widgets
	}
	return block
}
