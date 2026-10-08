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
 * @spec openspec/changes/portal-traffic-path-explorer/specs/portal-traffic-path-explorer/spec.md#requirement-each-step-must-show-its-busiest-pages-the-rest-as-one-node-and-where-visits-ended
 * @param {object} result The endpoint's answer: `mode`, `columns`, `links`.
 * @param {object} [measures] Overrides of LAYOUT.
 * @return {{width: number, height: number, scale: number, columns: Array<object>, links: Array<object>}} The drawing.
 */
export function layoutPaths(result, measures = {}) {
	const m = { ...LAYOUT, ...measures }
	const columns = (result && Array.isArray(result.columns) && result.columns) || []
	const backward = Boolean(result && result.mode === 'end')
	const busiest = Math.max(1, ...columns.map((c) => Number(c.sessions) || 0))
	const scale = m.maxBandHeight / busiest
	const last = columns.length - 1

	const laid = columns.map((column, index) => {
		const position = backward ? last - index : index
		const x = position * (m.columnWidth + m.gap)
		let y = m.header
		const nodes = (column.nodes || []).map((node, n) => {
			const height = Math.max(m.minNode, node.sessions * scale)
			const box = {
				key: column.step + ':' + n,
				index: n,
				step: column.step,
				x,
				y,
				width: m.columnWidth,
				height,
				dropHeight: node.dropOffs * scale,
				node,
			}
			y += height + m.nodeGap
			return box
		})
		return { step: column.step, x, column, nodes }
	})

	const links = []
	const outUsed = {}
	const inUsed = {}
	const ordered = [...((result && result.links) || [])].sort(
		(a, b) => a.step - b.step || a.source - b.source || a.target - b.target,
	)
	ordered.forEach((link) => {
		const from = laid[link.step] && laid[link.step].nodes[link.source]
		const to = laid[link.step + 1] && laid[link.step + 1].nodes[link.target]
		if (!from || !to) {
			return
		}
		const thickness = link.sessions * scale
		const y0 = from.y + (outUsed[from.key] || 0)
		const y1 = to.y + (inUsed[to.key] || 0)
		outUsed[from.key] = (outUsed[from.key] || 0) + thickness
		inUsed[to.key] = (inUsed[to.key] || 0) + thickness
		const x0 = backward ? from.x : from.x + from.width
		const x1 = backward ? to.x + to.width : to.x
		links.push({
			key: link.step + ':' + link.source + '>' + link.target,
			step: link.step,
			source: link.source,
			target: link.target,
			sessions: link.sessions,
			x0,
			y0,
			x1,
			y1,
			thickness,
			d: band(x0, y0, x1, y1, thickness),
		})
	})

	// The drop-off stub sits on the side the visitors would have left by,
	// below the bands that did leave, so the two never overlap.
	laid.forEach((column) => {
		column.nodes.forEach((box) => {
			box.dropX = backward ? box.x - m.dropWidth : box.x + box.width
			box.dropY = box.y + (outUsed[box.key] || 0)
			box.dropWidth = m.dropWidth
		})
	})

	const height = Math.max(
		m.header + m.minNode,
		...laid.map((c) =>
			c.nodes.length === 0
				? 0
				: c.nodes[c.nodes.length - 1].y + c.nodes[c.nodes.length - 1].height,
		),
	)
	const width =
		columns.length === 0
			? 0
			: columns.length * m.columnWidth + (columns.length - 1) * m.gap

	return { width, height, scale, columns: laid, links }
}

/**
 * The outline of one band: a top curve out, a straight edge down, a
 * bottom curve back, closed.
 *
 * @spec openspec/changes/portal-traffic-path-explorer/specs/portal-traffic-path-explorer/spec.md#requirement-each-step-must-show-its-busiest-pages-the-rest-as-one-node-and-where-visits-ended
 * @param {number} x0 Where it leaves, x.
 * @param {number} y0 Where it leaves, top y.
 * @param {number} x1 Where it arrives, x.
 * @param {number} y1 Where it arrives, top y.
 * @param {number} thickness The band's thickness.
 * @return {string} An SVG path.
 */
export function band(x0, y0, x1, y1, thickness) {
	const mid = (x0 + x1) / 2
	const r = (n) => Math.round(n * 100) / 100
	return [
		'M',
		r(x0),
		r(y0),
		'C',
		r(mid),
		r(y0),
		r(mid),
		r(y1),
		r(x1),
		r(y1),
		'L',
		r(x1),
		r(y1 + thickness),
		'C',
		r(mid),
		r(y1 + thickness),
		r(mid),
		r(y0 + thickness),
		r(x0),
		r(y0 + thickness),
		'Z',
	].join(' ')
}

/**
 * Every node of every step as a row, for the table beside the drawing.
 *
 * @spec openspec/changes/portal-traffic-path-explorer/specs/portal-traffic-path-explorer/spec.md#requirement-the-explorer-must-be-operable-by-keyboard-and-readable-without-the-diagram
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
 * @spec openspec/changes/portal-traffic-path-explorer/specs/portal-traffic-path-explorer/spec.md#requirement-the-reader-must-be-able-to-expand-from-a-node-and-change-the-number-of-steps
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
