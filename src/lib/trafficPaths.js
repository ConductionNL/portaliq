// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
//
// The path explorer's drawing, as numbers (portal-traffic-path-explorer).
// Pure: the endpoint's answer in, rectangles and band outlines out, so the
// geometry can be asserted in node without a browser. The widget only
// turns these numbers into SVG elements and theme colours.
//
// One scale for the whole diagram, visits to pixels, so a band and the
// node it leaves are the same thickness and two steps compare by eye. A
// node never shrinks below MIN_NODE, so a page with one visit still has
// room for its label; its bands keep the true scale and simply do not
// fill it.

/**
 * The drawing's fixed measures, in pixels.
 */
export const LAYOUT = {
	columnWidth: 168,
	gap: 96,
	nodeGap: 12,
	minNode: 32,
	header: 40,
	maxBandHeight: 280,
	dropWidth: 8,
}

/**
 * The drawing for one answer of `/api/traffic/paths`.
 *
 * Step 0 is the left column when reading forward, and the RIGHT column
 * when reading backward from an ending point, so the diagram always reads
 * left to right in the direction the visitors walked.
 *
 * @spec openspec/specs/portal-traffic-path-explorer/spec.md#requirement-each-step-must-show-its-busiest-pages-the-rest-as-one-node-and-where-visits-ended
 * @param {object} result The endpoint's answer: `mode`, `columns`, `links`.
 * @param {object} [measures] Overrides of LAYOUT.
 * @return {{width: number, height: number, scale: number, columns: Array<object>, links: Array<object>}} The drawing.
 */
export function layoutPaths(result, measures = {}) {
	const m = { ...LAYOUT, ...measures }
	const columns = (result && Array.isArray(result.columns) && result.columns) || []
	const view = drawingView(result, { m, columns })
	const laid = columns.map((column, index) => layoutColumn(column, { ...view, index }))
	const outUsed = {}
	const links = layoutLinks(result, { laid, view, outUsed })
	placeDropOffs(laid, { view, outUsed })
	return { ...drawingSize(columns, { laid, m }), scale: view.scale, columns: laid, links }
}

/**
 * What every part of the drawing reads: the measures, the direction, the one
 * scale and the last column.
 *
 * @param {object|null} result The endpoint's answer.
 * @param {{m: object, columns: Array<object>}} drawing The measures and the answer's columns.
 * @return {{m: object, backward: boolean, scale: number, last: number}} The view.
 */
function drawingView(result, drawing) {
	const { m, columns } = drawing
	return {
		m,
		backward: Boolean(result && result.mode === 'end'),
		scale: m.maxBandHeight / busiestOf(columns),
		last: columns.length - 1,
	}
}

/**
 * @param {Array<object>} columns The answer's columns.
 * @return {number} The visits of the busiest step, at least one.
 */
function busiestOf(columns) {
	return Math.max(1, ...columns.map((c) => Number(c.sessions) || 0))
}

/**
 * One step's column: where it stands, and its node boxes top to bottom.
 *
 * @param {object} column The answer's column.
 * @param {{m: object, backward: boolean, scale: number, last: number, index: number}} at The measures, the direction, the scale, the last column and this one's place.
 * @return {{step: number, x: number, column: object, nodes: Array<object>}} The column.
 */
function layoutColumn(column, at) {
	const position = at.backward ? at.last - at.index : at.index
	const x = position * (at.m.columnWidth + at.m.gap)
	return { step: column.step, x, column, nodes: nodeBoxes(column, { ...at, x }) }
}

/**
 * The boxes of one column's nodes, stacked from the header down.
 *
 * @param {object} column The answer's column.
 * @param {{m: object, scale: number, x: number}} at The measures, the scale and the column's x.
 * @return {Array<object>} The boxes.
 */
function nodeBoxes(column, at) {
	let y = at.m.header
	return (column.nodes || []).map((node, n) => {
		const box = nodeBox(node, { ...at, step: column.step, n, y })
		y += box.height + at.m.nodeGap
		return box
	})
}

/**
 * One node's box: never lower than the smallest node, its drop-off on the
 * true scale.
 *
 * @param {object} node The answer's node.
 * @param {{m: object, scale: number, x: number, y: number, step: number, n: number}} place The measures, the scale, where it stands and which node it is.
 * @return {object} The box.
 */
function nodeBox(node, place) {
	const { m, scale, x, y, step, n } = place
	return {
		key: step + ':' + n,
		index: n,
		step,
		x,
		y,
		width: m.columnWidth,
		height: Math.max(m.minNode, node.sessions * scale),
		dropHeight: node.dropOffs * scale,
		node,
	}
}

/**
 * The links in drawing order: by step, then source, then target.
 *
 * @param {object|null} result The endpoint's answer.
 * @return {Array<object>} The links.
 */
function sortedLinks(result) {
	return [...((result && result.links) || [])].sort(
		(a, b) => a.step - b.step || a.source - b.source || a.target - b.target,
	)
}

/**
 * Every link that joins two drawn nodes, as a band; each band takes the
 * next free stretch of the node it leaves and the node it reaches.
 *
 * @param {object|null} result The endpoint's answer.
 * @param {{laid: Array<object>, view: object, outUsed: Object<string, number>}} draw The laid columns, the drawing's view and the stretch used on each node's way out.
 * @return {Array<object>} The bands.
 */
function layoutLinks(result, draw) {
	const inUsed = {}
	const links = []
	for (const link of sortedLinks(result)) {
		const drawn = linkBand(link, { ...draw, inUsed })
		if (drawn !== null) {
			links.push(drawn)
		}
	}
	return links
}

/**
 * @param {Array<object>} laid The laid columns.
 * @param {{step: number, index: number}} at A step and a node's index on it.
 * @return {object|undefined} The node's box, if it is drawn.
 */
function nodeAt(laid, at) {
	return laid[at.step] && laid[at.step].nodes[at.index]
}

/**
 * Take the next stretch of a node's side.
 *
 * @param {Object<string, number>} used The stretch used per node.
 * @param {{key: string, thickness: number}} take The node and the band's thickness.
 * @return {number} Where the band starts, from the node's top.
 */
function claim(used, take) {
	const offset = used[take.key] || 0
	used[take.key] = offset + take.thickness
	return offset
}

/**
 * @param {{from: object, to: object}} ends The boxes a band leaves and reaches.
 * @param {boolean} backward Whether the diagram reads backward from an ending.
 * @return {{x0: number, x1: number}} Where it leaves and arrives, x.
 */
function bandXs(ends, backward) {
	const { from, to } = ends
	return backward
		? { x0: from.x, x1: to.x + to.width }
		: { x0: from.x + from.width, x1: to.x }
}

/**
 * One link as a band, or null when a node it joins is not drawn.
 *
 * @param {object} link The answer's link.
 * @param {{laid: Array<object>, view: object, outUsed: Object<string, number>, inUsed: Object<string, number>}} draw The laid columns, the view and the stretches used.
 * @return {object|null} The band.
 */
function linkBand(link, draw) {
	const from = nodeAt(draw.laid, { step: link.step, index: link.source })
	const to = nodeAt(draw.laid, { step: link.step + 1, index: link.target })
	if (!from || !to) {
		return null
	}
	const thickness = link.sessions * draw.view.scale
	const y0 = from.y + claim(draw.outUsed, { key: from.key, thickness })
	const y1 = to.y + claim(draw.inUsed, { key: to.key, thickness })
	const { x0, x1 } = bandXs({ from, to }, draw.view.backward)
	return drawnLink(link, { x0, y0, x1, y1, thickness })
}

/**
 * @param {object} link The answer's link.
 * @param {{x0: number, y0: number, x1: number, y1: number, thickness: number}} outline Where its band leaves and arrives, and how thick it is.
 * @return {object} The link with its band.
 */
function drawnLink(link, outline) {
	const { x0, y0, x1, y1, thickness } = outline
	return { ...linkIdentity(link), x0, y0, x1, y1, thickness, d: band(outline) }
}

/**
 * @param {object} link The answer's link.
 * @return {{key: string, step: number, source: number, target: number, sessions: number}} What names the link and how many visits it carries.
 */
function linkIdentity(link) {
	return {
		key: link.step + ':' + link.source + '>' + link.target,
		step: link.step,
		source: link.source,
		target: link.target,
		sessions: link.sessions,
	}
}

/**
 * The drop-off stub sits on the side the visitors would have left by,
 * below the bands that did leave, so the two never overlap.
 *
 * @param {Array<object>} laid The laid columns.
 * @param {{view: object, outUsed: Object<string, number>}} draw The view and the stretch used on each node's way out.
 * @return {void}
 */
function placeDropOffs(laid, draw) {
	const { m, backward } = draw.view
	laid.forEach((column) => {
		column.nodes.forEach((box) => {
			box.dropX = backward ? box.x - m.dropWidth : box.x + box.width
			box.dropY = box.y + (draw.outUsed[box.key] || 0)
			box.dropWidth = m.dropWidth
		})
	})
}

/**
 * @param {{nodes: Array<object>}} column A laid column.
 * @return {number} Where its last node ends, or 0 without nodes.
 */
function columnBottom(column) {
	const last = column.nodes[column.nodes.length - 1]
	return column.nodes.length === 0 ? 0 : last.y + last.height
}

/**
 * @param {Array<object>} columns The answer's columns.
 * @param {{laid: Array<object>, m: object}} drawing The laid columns and the measures.
 * @return {{width: number, height: number}} The drawing's size.
 */
function drawingSize(columns, drawing) {
	return {
		width: drawingWidth(columns, drawing.m),
		height: drawingHeight(drawing.laid, drawing.m),
	}
}

/**
 * @param {Array<object>} laid The laid columns.
 * @param {object} m The measures.
 * @return {number} The drawing's height.
 */
function drawingHeight(laid, m) {
	return Math.max(m.header + m.minNode, ...laid.map(columnBottom))
}

/**
 * @param {Array<object>} columns The answer's columns.
 * @param {object} m The measures.
 * @return {number} The drawing's width.
 */
function drawingWidth(columns, m) {
	return columns.length === 0
		? 0
		: columns.length * m.columnWidth + (columns.length - 1) * m.gap
}

/**
 * The outline of one band: a top curve out, a straight edge down, a
 * bottom curve back, closed.
 *
 * @spec openspec/specs/portal-traffic-path-explorer/spec.md#requirement-each-step-must-show-its-busiest-pages-the-rest-as-one-node-and-where-visits-ended
 * @param {{x0: number, y0: number, x1: number, y1: number, thickness: number}} outline Where it leaves (x, top y), where it arrives (x, top y), and its thickness.
 * @return {string} An SVG path.
 */
export function band(outline) {
	const { x0, y0, x1, y1, thickness } = outline
	const mid = (x0 + x1) / 2
	return [
		'M',
		rounded(x0),
		rounded(y0),
		...curve(mid, { fromY: y0, toX: x1, toY: y1 }),
		'L',
		rounded(x1),
		rounded(y1 + thickness),
		...curve(mid, { fromY: y1 + thickness, toX: x0, toY: y0 + thickness }),
		'Z',
	].join(' ')
}

/**
 * @param {number} n A coordinate.
 * @return {number} The coordinate to two decimals.
 */
function rounded(n) {
	return Math.round(n * 100) / 100
}

/**
 * A curve from the current point across to another column, bending halfway.
 *
 * @param {number} mid The x halfway between the columns.
 * @param {{fromY: number, toX: number, toY: number}} to The height it leaves at, and where it arrives.
 * @return {Array<string|number>} The curve's part of the path.
 */
function curve(mid, to) {
	return [
		'C',
		rounded(mid),
		rounded(to.fromY),
		rounded(mid),
		rounded(to.toY),
		rounded(to.toX),
		rounded(to.toY),
	]
}

/**
 * Every node of every step as a row, for the table beside the drawing.
 *
 * @spec openspec/specs/portal-traffic-path-explorer/spec.md#requirement-the-explorer-must-be-operable-by-keyboard-and-readable-without-the-diagram
 * @param {object} result The endpoint's answer.
 * @return {Array<{step: number, path: string, more: number, sessions: number, dropOffs: number, selected: boolean}>} The rows, step by step.
 */
export function pathRows(result) {
	const rows = []
	;((result && result.columns) || []).forEach((column) => {
		;(column.nodes || []).forEach((node) => {
			rows.push({
				step: column.step,
				path: node.path,
				more: node.more,
				sessions: node.sessions,
				dropOffs: node.dropOffs,
				selected: node.selected === true,
			})
		})
	})
	return rows
}

/**
 * The trail after the reader chooses a node: the node on its step, the
 * choices before it kept, the choices after it dropped (they were made
 * among visits that no longer count). Choosing the chosen node again
 * undoes it.
 *
 * @spec openspec/specs/portal-traffic-path-explorer/spec.md#requirement-the-reader-must-be-able-to-expand-from-a-node-and-change-the-number-of-steps
 * @param {Array<string|null>} trail The current trail.
 * @param {number} step The step of the node.
 * @param {string} path The node's page.
 * @return {Array<string|null>} The new trail.
 */
export function chooseNode(trail, step, path) {
	const next = []
	for (let i = 0; i < step; i++) {
		next.push(trail[i] || null)
	}
	if (trail[step] !== path) {
		next.push(path)
	}
	while (next.length > 0 && next[next.length - 1] === null) {
		next.pop()
	}
	return next
}
