// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// What each block of a contribution page renders as, decided once and without
// Vue, so the decision is tested in node (tests/site-collections.spec.mjs).
//
// The server normaliser already dropped blocks whose reference does not
// resolve; one that still does not resolve here renders nothing.
//
// The same rules as the React portal's PageView.jsx:
//   - a `collection` block is a table, or a timed task when its collection is
//     one; only `type: update` and endpoint row actions reach the row buttons,
//     never the item list's remove action, and viewing a document belongs to
//     the sign dialog rather than to a button of its own;
//   - a `detail` block is the detail card of the row selected in that
//     collection's table, with the collection's propose-change action;
//   - `citizenCase`, `action` and `cta` blocks belong to other slices and are
//     handed to their slot;
//   - `kpi`, `calendar` and `news` blocks are the record page's figure cards,
//     calendar and news (contribution-record-page);
//   - `tasks` and `inbox` blocks are action rows of what the resident still
//     has to do and of their newest messages, `cases` blocks case cards, and
//     a `steps` block where the open case stands, and `documents` and
//     `timeline` blocks its file items and history (site-mijn-omgeving-components).
//
// @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-contribution-page-must-render-its-blocks-req-srp-014

import { withoutRemoveAction } from '../../../shared/itemList.js'
import { isEndpointRowAction } from '../../../shared/rowAction.js'
import { tableRowActions } from '../../../shared/signing.js'

/** The block types that read a collection. */
export const COLLECTION_BLOCKS = ['collection', 'detail', 'citizenCase']

/**
 * A collection of the contribution, by id.
 *
 * @param {object} contribution The contribution.
 * @param {string} id The collection id.
 * @return {object|null}
 */
export function findCollection(contribution, id) {
	return (contribution?.collections || []).find((c) => c && c.id === id) || null
}

/**
 * An action of the contribution, by id.
 *
 * @param {object} contribution The contribution.
 * @param {string} id The action id.
 * @return {object|null}
 */
export function findAction(contribution, id) {
	return (contribution?.actions || []).find((a) => a && a.id === id) || null
}

/**
 * The resolved row actions a collection names.
 *
 * @param {object} contribution The contribution.
 * @param {object} collection The collection.
 * @return {Array<object>}
 */
export function rowActionsOf(contribution, collection) {
	return (collection?.rowActions || [])
		.map((id) => findAction(contribution, id))
		.filter(Boolean)
}

/**
 * How each block of a page renders.
 *
 * Each entry is `{index, block, kind, collection?, action?, rowActions?,
 * tableActions?, proposeAction?, viewAction?}`, where `kind` is one of
 * `richText`, `table`, `timedTask`, `detail`, `citizenCase`, `action`, `cta`,
 * `kpi`, `calendar`, `news`, `tasks`, `inbox`, `cases`, `steps`,
 * `documents`, `timeline`,
 * or `none` for a block that renders nothing.
 *
 * @param {object} page The contribution page.
 * @param {object} contribution The contribution it belongs to.
 * @return {Array<object>}
 */
export function resolveBlocks(page, contribution) {
	return quietCaseUnderDetail(resolveEachBlock(page, contribution))
}

/**
 * A case screen that shares its collection with a detail card on the same
 * page stays quiet until a case is chosen: the card already says "Select an
 * item.", and a second line saying the same thing is noise
 * (citizen-case-shows-only-its-fields).
 *
 * @param {Array<object>} items The resolved blocks.
 * @return {Array<object>} The same blocks, a quiet case screen marked `quietWhenEmpty`.
 *
 * @spec openspec/changes/citizen-case-shows-only-its-fields/specs/citizen-case-withdraw-screen/spec.md
 */
function quietCaseUnderDetail(items) {
	const detailed = new Set(
		items
			.filter((item) => item.kind === 'detail')
			.map((item) => item.collection?.id),
	)
	return items.map((item) =>
		item.kind === 'citizenCase' && detailed.has(item.collection?.id)
			? { ...item, quietWhenEmpty: true }
			: item,
	)
}

/**
 * Resolve each block on its own, see resolveBlocks().
 *
 * @param {object} page The contribution page.
 * @param {object} contribution The contribution it belongs to.
 * @return {Array<object>}
 */
function resolveEachBlock(page, contribution) {
	return (page?.blocks || []).map((block, index) => {
		const type = block?.type
		if (type === 'richText') {
			return { index, block, kind: 'richText' }
		}
		if (COLLECTION_BLOCKS.includes(type)) {
			const collection = findCollection(contribution, block.collection)
			if (!collection) {
				return { index, block, kind: 'none' }
			}
			const rowActions = rowActionsOf(contribution, collection)
			if (type === 'detail') {
				return {
					index,
					block,
					kind: 'detail',
					collection,
					proposeAction:
						rowActions.find((a) => a.type === 'propose-change') || null,
				}
			}
			if (type === 'citizenCase') {
				return { index, block, kind: 'citizenCase', collection }
			}
			if (collection.kind === 'timedTask' && collection.timedTask) {
				return { index, block, kind: 'timedTask', collection }
			}
			return {
				index,
				block,
				kind: 'table',
				collection,
				rowActions,
				tableActions: tableRowActions(
					withoutRemoveAction(
						collection,
						rowActions.filter(
							(a) => a.type === 'update' || isEndpointRowAction(a),
						),
					),
				),
				viewAction:
					rowActions.find(
						(a) => a.id === 'viewDocument' && isEndpointRowAction(a),
					) || null,
			}
		}
		if (type === 'kpi') {
			// contribution-record-page: figure cards from one row.
			const collection = findCollection(contribution, block.collection)
			return collection
				? { index, block, kind: 'kpi', collection }
				: { index, block, kind: 'none' }
		}
		if (type === 'calendar') {
			const sources = (block.sources || []).filter((source) =>
				findCollection(contribution, source?.collection),
			)
			return sources.length > 0
				? { index, block: { ...block, sources }, kind: 'calendar' }
				: { index, block, kind: 'none' }
		}
		if (type === 'news') {
			return { index, block, kind: 'news' }
		}
		if (type === 'tasks') {
			// site-mijn-omgeving-components REQ-SMO-004: what is still to do.
			const collection = findCollection(contribution, block.collection)
			return collection
				? { index, block, kind: 'tasks', collection }
				: { index, block, kind: 'none' }
		}
		if (type === 'inbox') {
			return { index, block, kind: 'inbox' }
		}
		if (['cases', 'steps', 'documents', 'timeline'].includes(type)) {
			// site-mijn-omgeving-components REQ-SMO-002, REQ-SMO-003, REQ-SMO-005.
			const collection = findCollection(contribution, block.collection)
			return collection
				? { index, block, kind: type, collection }
				: { index, block, kind: 'none' }
		}
		if (type === 'action' || type === 'cta') {
			const action = findAction(contribution, block.action)
			return action
				? { index, block, kind: type, action }
				: { index, block, kind: 'none' }
		}
		return { index, block, kind: 'none' }
	})
}
