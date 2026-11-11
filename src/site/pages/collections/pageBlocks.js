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
//     collection's table, with the collection's propose-change action; under
//     that table it says nothing until a row is chosen;
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
 * A calendar source with the words of its `statusField`, from its collection's
 * value labels, so a timetable's pill reads "Ander lokaal", never a code
 * (calendar-timetable-display).
 *
 * @param {object} source The calendar source.
 * @param {object} contribution The contribution.
 * @return {object} The source, with `statusLabels` when there are any.
 * @spec openspec/changes/calendar-timetable-display/specs/portal-contribution-contract/spec.md#requirement-a-calendar-block-may-draw-a-day-as-a-timetable
 */
export function withStatusLabels(source, contribution) {
	if (!source?.statusField) {
		return source
	}
	const collection = findCollection(contribution, source.collection)
	const labels = collection?.fieldConfigs?.[source.statusField]?.valueLabels
	return labels && typeof labels === 'object'
		? { ...source, statusLabels: labels }
		: source
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
	return groupTiles(
		quietDetailUnderTable(
			quietCaseUnderDetail(resolveEachBlock(page, contribution)),
		),
	)
}

/**
 * The blocks of a record page without the writes on the open record: the
 * forms and buttons bound to it (`withRecord`), the documents block's add
 * button and the detail card's propose-change action. Used for a record read
 * under a mandate, where the server accepts no write.
 *
 * @param {Array<object>} items The resolved blocks.
 * @return {Array<object>} The same blocks, read-only.
 * @spec openspec/changes/mandated-case-offers-no-writes/specs/portal-my-cases/spec.md#requirement-a-case-read-under-a-mandate-offers-no-writes
 */
export function withoutRecordWrites(items) {
	return items
		.filter(
			(item) =>
				!(
					(item.kind === 'action' || item.kind === 'cta')
					&& item.block?.withRecord === true
				),
		)
		.map((item) => {
			if (item.kind === 'documents' && item.block?.upload === true) {
				return { ...item, block: { ...item.block, upload: false } }
			}
			if (item.kind === 'detail' && item.proposeAction) {
				return { ...item, proposeAction: null }
			}
			return item
		})
}

/**
 * Consecutive `cta` blocks that open a page or a route become one list of
 * quick tiles (`kind: tiles`, the blocks in `tiles`), as the mockups draw
 * them (site-mijn-omgeving-components REQ-SMO-024).
 *
 * @param {Array<object>} items The resolved blocks.
 * @return {Array<object>} The same blocks, runs of tiles folded into one.
 * @spec openspec/changes/site-mijn-omgeving-components/specs/portal-contribution-contract/spec.md#requirement-a-cta-block-may-open-a-page-or-a-site-route-for-the-open-record-with-the-record-in-its-label-req-smo-024
 */
export function groupTiles(items) {
	const out = []
	for (const item of items) {
		foldTile(out, item)
	}
	return out
}

/**
 * Add one block to the folded list: a tile joins the run of tiles before it
 * or starts one, any other block is added as it is.
 *
 * @param {Array<object>} out The blocks folded so far.
 * @param {object} item The next block.
 */
function foldTile(out, item) {
	const last = out[out.length - 1]
	if (item.kind !== 'tile') {
		out.push(item)
	} else if (last && last.kind === 'tiles') {
		last.tiles.push(item.block)
	} else {
		out.push({
			index: item.index,
			block: item.block,
			kind: 'tiles',
			tiles: [item.block],
		})
	}
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
 * A detail card under the table of its own collection stays quiet until a row
 * is chosen: the table's first column already opens a row, and "Select an
 * item." under a list reads as a stray line
 * (site-tables-read-in-their-declared-order). A card without that table on
 * the page keeps the line, and so does the card a quiet case screen leans on,
 * so a page never goes from two prompts to none.
 *
 * @param {Array<object>} items The resolved blocks.
 * @return {Array<object>} The same blocks, a quiet detail card marked `quietWhenEmpty`.
 *
 * @spec openspec/changes/site-tables-read-in-their-declared-order/specs/portal-contribution-contract/spec.md#requirement-a-detail-card-under-its-own-table-waits-quietly-for-a-row
 */
function quietDetailUnderTable(items) {
	const ids = (kind) =>
		new Set(
			items
				.filter((item) => item.kind === kind)
				.map((item) => item.collection?.id),
		)
	const tabled = ids('table')
	const cased = ids('citizenCase')
	return items.map((item) =>
		item.kind === 'detail'
		&& tabled.has(item.collection?.id)
		&& !cased.has(item.collection?.id)
			? { ...item, quietWhenEmpty: true }
			: item,
	)
}

/**
 * A block that renders nothing.
 *
 * @param {{index: number, block: object}} spot The block and its place.
 * @return {object}
 */
function nothing(spot) {
	return { ...spot, kind: 'none' }
}

/**
 * A text, or a text filled from the open record (REQ-SMO-027).
 *
 * @param {{index: number, block: object}} spot The block and its place.
 * @return {object}
 */
function resolveRichText(spot) {
	return { ...spot, kind: spot.block.template ? 'template' : 'richText' }
}

/**
 * The table's row actions: updates and endpoint actions, without the remove
 * action and the document view.
 *
 * @param {object} collection The collection.
 * @param {Array<object>} rowActions Its resolved row actions.
 * @return {Array<object>}
 */
function tableActionsOf(collection, rowActions) {
	return tableRowActions(
		withoutRemoveAction(
			collection,
			rowActions.filter((a) => a.type === 'update' || isEndpointRowAction(a)),
		),
	)
}

/**
 * A table of a collection, with its row actions and its document view.
 *
 * @param {{index: number, block: object}} spot The block and its place.
 * @param {{collection: object, rowActions: Array<object>}} found The collection and its row actions.
 * @return {object}
 */
function tableOf(spot, found) {
	const { collection, rowActions } = found
	return {
		...spot,
		kind: 'table',
		collection,
		rowActions,
		tableActions: tableActionsOf(collection, rowActions),
		viewAction:
			rowActions.find((a) => a.id === 'viewDocument' && isEndpointRowAction(a))
			|| null,
	}
}

/**
 * A detail card, with the propose-change action of its collection.
 *
 * @param {{index: number, block: object}} spot The block and its place.
 * @param {{collection: object, rowActions: Array<object>}} found The collection and its row actions.
 * @return {object}
 */
function detailOf(spot, found) {
	return {
		...spot,
		kind: 'detail',
		collection: found.collection,
		proposeAction:
			found.rowActions.find((a) => a.type === 'propose-change') || null,
	}
}

/**
 * A collection block: a detail card, a case screen, a timed task or a table.
 *
 * @param {{index: number, block: object}} spot The block and its place.
 * @param {object} contribution The contribution it belongs to.
 * @return {object}
 */
function resolveCollectionBlock(spot, contribution) {
	const collection = findCollection(contribution, spot.block.collection)
	if (!collection) {
		return nothing(spot)
	}
	return collectionBlockOf(spot, {
		collection,
		rowActions: rowActionsOf(contribution, collection),
	})
}

/**
 * What a collection block over an existing collection draws, by its type and
 * the collection's kind.
 *
 * @param {{index: number, block: object}} spot The block and its place.
 * @param {{collection: object, rowActions: Array<object>}} found The collection and its row actions.
 * @return {object}
 */
function collectionBlockOf(spot, found) {
	const type = spot.block.type
	const collection = found.collection
	if (type === 'detail') {
		return detailOf(spot, found)
	}
	if (type === 'citizenCase') {
		return { ...spot, kind: 'citizenCase', collection }
	}
	if (collection.kind === 'timedTask' && collection.timedTask) {
		return { ...spot, kind: 'timedTask', collection }
	}
	return tableOf(spot, found)
}

/**
 * A block drawn from one collection under the block's own type as its kind:
 * figure cards (contribution-record-page), what is still to do (REQ-SMO-004)
 * and the cases, steps, documents and timeline lists (REQ-SMO-002,
 * REQ-SMO-003, REQ-SMO-005).
 *
 * @param {{index: number, block: object}} spot The block and its place.
 * @param {object} contribution The contribution it belongs to.
 * @return {object}
 */
function resolveCollectionView(spot, contribution) {
	const collection = findCollection(contribution, spot.block.collection)
	return collection
		? { ...spot, kind: spot.block.type, collection }
		: nothing(spot)
}

/**
 * A calendar over the sources whose collection exists.
 *
 * @param {{index: number, block: object}} spot The block and its place.
 * @param {object} contribution The contribution it belongs to.
 * @return {object}
 */
function resolveCalendar(spot, contribution) {
	const { index, block } = spot
	const sources = (block.sources || [])
		.filter((source) => findCollection(contribution, source?.collection))
		.map((source) => withStatusLabels(source, contribution))
	return sources.length > 0
		? { index, block: { ...block, sources }, kind: 'calendar' }
		: nothing(spot)
}

/**
 * The overview's opening (site-school-blocks); an action it names is
 * resolved like a cta's.
 *
 * @param {{index: number, block: object}} spot The block and its place.
 * @param {object} contribution The contribution it belongs to.
 * @return {object}
 */
function resolveGreeting(spot, contribution) {
	const action = spot.block.action
	return {
		...spot,
		kind: 'greeting',
		action: action ? findAction(contribution, action) : null,
	}
}

/**
 * An action button, or a cta: a tile to a page or a route (REQ-SMO-024) or a
 * button for its action.
 *
 * @param {{index: number, block: object}} spot The block and its place.
 * @param {object} contribution The contribution it belongs to.
 * @return {object}
 */
function resolveAction(spot, contribution) {
	const block = spot.block
	if (block.type === 'cta' && (block.page || block.route)) {
		return { ...spot, kind: 'tile' }
	}
	const action = findAction(contribution, block.action)
	return action ? { ...spot, kind: block.type, action } : nothing(spot)
}

/**
 * A block that needs nothing resolved: its type is its kind.
 *
 * @param {{index: number, block: object}} spot The block and its place.
 * @return {object}
 */
function asItsType(spot) {
	return { ...spot, kind: spot.block.type }
}

/**
 * How each block type resolves, by type.
 *
 * @type {Map<string, function({index: number, block: object}, object): object>}
 */
const BLOCK_RESOLVERS = new Map([
	['richText', resolveRichText],
	...COLLECTION_BLOCKS.map((type) => [type, resolveCollectionBlock]),
	['kpi', resolveCollectionView],
	['calendar', resolveCalendar],
	['news', asItsType],
	['tasks', resolveCollectionView],
	['inbox', asItsType],
	['cases', resolveCollectionView],
	['steps', resolveCollectionView],
	['documents', resolveCollectionView],
	['timeline', resolveCollectionView],
	['greeting', resolveGreeting],
	['cta', resolveAction],
	['action', resolveAction],
])

/**
 * Resolve each block on its own, see resolveBlocks().
 *
 * @param {object} page The contribution page.
 * @param {object} contribution The contribution it belongs to.
 * @return {Array<object>}
 */
function resolveEachBlock(page, contribution) {
	return (page?.blocks || []).map((block, index) => {
		const resolve = BLOCK_RESOLVERS.get(block?.type) || nothing
		return resolve({ index, block }, contribution)
	})
}
