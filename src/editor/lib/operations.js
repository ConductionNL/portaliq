/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * Every change the editor can make to a page, as a pure function.
 *
 * NOTHING HERE MUTATES ITS INPUT. Each operation returns a new regions map,
 * which is what makes undo (see `history.js`) correct by construction rather
 * than by discipline: the history stores what it was given and nothing can
 * reach back and change it.
 *
 * The operations are also the whole of what an author can do, which is
 * deliberate — a page cannot be edited into a state these functions cannot
 * produce, so the invariants they hold (a block is in exactly one region, the
 * grid is the only geometry) hold for every page the editor can make.
 *
 * @spec openspec/changes/portal-page-composition/tasks.md
 */

import { withoutStyling } from '../../site/lib/blockProps.js'

/** The grid the public renderer lays out on. */
const COLUMNS = 12

/**
 * A deep copy, so callers cannot alias into the returned state.
 *
 * A JSON ROUND-TRIP RATHER THAN `structuredClone`, for the reason recorded in
 * `history.js`: `structuredClone` throws `DataCloneError` on a Vue reactive
 * Proxy, which is what every edit from the editor hands this. Regions are JSON
 * by construction, so nothing is lost and the proxy is unwrapped on the way
 * through.
 *
 * @param {*} value The value.
 * @return {*} The copy.
 */
function copy(value) {
	return JSON.parse(JSON.stringify(value))
}

/**
 * Insert a block into a region.
 *
 * PLACED AT THE END OF THE REGION, in its own row. Dropping a new block into
 * the middle of an existing row would resize its neighbours to fit, which is a
 * change the author did not ask for to blocks they were not touching.
 *
 * @param {object} regions The current regions.
 * @param {string} region  The region key.
 * @param {string} key     The block key.
 * @param {number} at      Where to insert; the end by default.
 * @return {object} The next regions.
 */
export function insertBlock(regions, region, key, at = -1) {
	const next = copy(regions)
	const list = next[region] || (next[region] = [])
	const row = list.reduce((max, b) => Math.max(max, (b.gridY || 0) + (b.gridHeight || 4)), 0)

	const block = {
		widgetKey: key,
		props: {},
		gridX: 0,
		gridY: row,
		gridWidth: COLUMNS,
		gridHeight: 4,
	}

	if (at < 0 || at >= list.length) {
		list.push(block)
	} else {
		list.splice(at, 0, block)
	}

	return next
}

/**
 * Remove a block.
 *
 * @param {object} regions The current regions.
 * @param {string} region  The region key.
 * @param {number} index   The block's index.
 * @return {object} The next regions.
 */
export function removeBlock(regions, region, index) {
	const next = copy(regions)
	if (Array.isArray(next[region]) === false) {
		return next
	}

	next[region].splice(index, 1)
	return next
}

/**
 * Move a block within a region, or into another one.
 *
 * A BLOCK IS IN EXACTLY ONE REGION, always. Moving is a remove and an insert in
 * one operation rather than two, so there is no intermediate state where the
 * block is in both or in neither — which matters because every operation here
 * is a potential undo point.
 *
 * @param {object} regions   The current regions.
 * @param {string} from      The source region.
 * @param {number} index     The block's index.
 * @param {string} to        The target region.
 * @param {number} targetAt  Where to place it; the end by default.
 * @return {object} The next regions.
 */
export function moveBlock(regions, from, index, to, targetAt = -1) {
	const next = copy(regions)
	const source = next[from] || []
	if (index < 0 || index >= source.length) {
		return next
	}

	const [block] = source.splice(index, 1)
	const target = next[to] || (next[to] = [])

	if (targetAt < 0 || targetAt >= target.length) {
		target.push(block)
	} else {
		target.splice(targetAt, 0, block)
	}

	// Re-row the target so the moved block does not land on top of another.
	// The grid places by (gridY, gridX), so two blocks sharing a row and a
	// column overlap rather than stack.
	target.forEach((b, i) => {
		b.gridY = i * (b.gridHeight || 4)
	})

	return next
}

/**
 * Resize a block on the grid.
 *
 * CLAMPED TO THE GRID, and that is task 5.3 in force rather than restated: a
 * block cannot be given a width the public renderer would not honour, so a page
 * cannot be edited into a layout only the canvas can show.
 *
 * @param {object} regions The current regions.
 * @param {string} region  The region key.
 * @param {number} index   The block's index.
 * @param {object} size    `{gridX, gridY, gridWidth, gridHeight}`, partial.
 * @return {object} The next regions.
 */
export function resizeBlock(regions, region, index, size) {
	const next = copy(regions)
	const block = (next[region] || [])[index]
	if (!block) {
		return next
	}

	const width = Math.max(1, Math.min(COLUMNS, Number(size.gridWidth ?? block.gridWidth ?? COLUMNS)))
	const x = Math.max(0, Math.min(COLUMNS - width, Number(size.gridX ?? block.gridX ?? 0)))

	block.gridWidth = width
	block.gridX = x
	block.gridHeight = Math.max(1, Number(size.gridHeight ?? block.gridHeight ?? 4))
	block.gridY = Math.max(0, Number(size.gridY ?? block.gridY ?? 0))

	return next
}

/**
 * Set one field on a block.
 *
 * ROUTED THROUGH THE SAME STRIPPER THE RENDERER USES. An author editing a field
 * called `style` must not be able to do from the inspector what page data
 * cannot do from the API (task 5.3) — and the inspector only offers declared
 * fields, so anything else arriving here came from somewhere it should not.
 *
 * @param {object} regions The current regions.
 * @param {string} region  The region key.
 * @param {number} index   The block's index.
 * @param {string} name    The field name.
 * @param {*}      value   The value.
 * @return {object} The next regions.
 */
export function setField(regions, region, index, name, value) {
	const next = copy(regions)
	const block = (next[region] || [])[index]
	if (!block) {
		return next
	}

	block.props = withoutStyling({ ...(block.props || {}), [name]: value })
	return next
}
