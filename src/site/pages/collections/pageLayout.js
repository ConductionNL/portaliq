// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// WHERE EACH BLOCK OF A CONTRIBUTION PAGE STANDS (mijn-overview-follows-the-boards).
//
// The school overviews put blocks side by side: the timetable on the left,
// homework and grades on the right; the news beside "Deze maand". A block
// declares `column: "main"` or `column: "side"`; a block without it spans
// the page. Consecutive blocks with a column form one band of two columns,
// each stacking its own blocks from the band's first row, the shorter column's
// last block reaching to the band's end. The places are CSS grid lines handed
// over as custom properties, so a phone (one column) simply keeps the order
// of the document, which is the order the page declares.
//
// Imports nothing, so tests/site-look/mijn-overview-follows-the-boards.spec.mjs
// runs it in node.
//
// @spec openspec/changes/mijn-overview-follows-the-boards/specs/portal-contribution-contract/spec.md#requirement-a-block-may-stand-in-a-column-and-in-a-frame

/** The columns a block may declare. */
export const COLUMNS = ['main', 'side']

/**
 * Each block's place in the page grid, by the block's index.
 *
 * @param {Array<{index: number, block: object}>} items The visible blocks, in order.
 * @return {{columns: boolean, places: Record<number, {column: string, row: string}>}}
 *   Whether any block stands in a column, and each block's grid column and row.
 * @spec openspec/changes/mijn-overview-follows-the-boards/specs/portal-contribution-contract/spec.md#requirement-a-block-may-stand-in-a-column-and-in-a-frame
 */
export function blockPlaces(items) {
	const list = Array.isArray(items) ? items : []
	const places = {}
	let row = 1
	let band = { main: [], side: [] }
	const columns = list.some((item) => COLUMNS.includes(item?.block?.column))

	const closeBand = () => {
		const height = Math.max(band.main.length, band.side.length)
		if (height === 0) {
			return
		}
		for (const [name, column] of [
			['main', '1'],
			['side', '2'],
		]) {
			band[name].forEach((index, position) => {
				const start = row + position
				const last = position === band[name].length - 1
				places[index] = {
					column,
					row: last ? `${start} / ${row + height}` : String(start),
				}
			})
		}
		row += height
		band = { main: [], side: [] }
	}

	for (const item of list) {
		const column = item?.block?.column
		if (COLUMNS.includes(column)) {
			band[column].push(item.index)
			continue
		}
		closeBand()
		places[item.index] = { column: '1 / -1', row: String(row) }
		row += 1
	}
	closeBand()
	return { columns, places }
}

/**
 * The inline style that hands a block its place to the page grid, or none
 * when the page has no columns.
 *
 * @param {{columns: boolean, places: object}} layout From blockPlaces().
 * @param {number} index The block's index.
 * @return {Record<string, string>|undefined} The custom properties.
 * @spec openspec/changes/mijn-overview-follows-the-boards/specs/portal-contribution-contract/spec.md#requirement-a-block-may-stand-in-a-column-and-in-a-frame
 */
export function placeStyle(layout, index) {
	const place = layout?.columns ? layout.places[index] : null
	return place
		? { '--pq-block-column': place.column, '--pq-block-row': place.row }
		: undefined
}

/**
 * The classes of a block's wrapper: its frame and whether it carries a
 * link in its heading row.
 *
 * @param {object} block The block.
 * @return {Array<string>} The classes.
 * @spec openspec/changes/mijn-overview-follows-the-boards/specs/portal-contribution-contract/spec.md#requirement-a-block-may-stand-in-a-column-and-in-a-frame
 */
export function blockClasses(block) {
	const classes = ['pq-block']
	if (block?.frame === 'line' || block?.frame === 'tinted') {
		classes.push('pq-block--framed', `pq-block--${block.frame}`)
	}
	if (block?.more && block.more.placement !== 'end') {
		classes.push('pq-block--more-heading')
	}
	return classes
}
