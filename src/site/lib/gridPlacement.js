/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * Where a page's widgets go: the two pure functions behind `WidgetGrid`.
 *
 * They live outside the SFC because the placement is the part that has been
 * wrong twice and the part a component test cannot reach cheaply — this app
 * runs its JS specs as plain node scripts, so logic inside a `.vue` file needs
 * a compiler to test and therefore never got tested. A hole in the La Franken
 * landing page shipped as a result.
 *
 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-page-body-must-be-either-a-widget-grid-or-markdown
 */

/**
 * Split a widget list into alternating full-bleed BANDS and RUNS of cells.
 *
 * A band is emitted on its own so it can paint edge to edge; the widgets
 * between bands are grouped into one grid inside one container. Grouping
 * matters: a container per cell would put every widget in its own grid and
 * destroy the 12-column placement they were authored against.
 *
 * Order is preserved exactly as authored — a band does not float to the top,
 * it splits the page where the author put it.
 *
 * Each run carries a `rowOffset`, and WITHOUT IT SPLITTING LEAVES HOLES:
 * `gridY` is absolute over the whole page, so a run whose first widget sits at
 * row 4 opens a grid with four empty rows above it, reserved for a band that is
 * not in that grid at all.
 *
 * @param {Array}                     widgets The authored placements, in order.
 * @param {(key: string) => boolean}  isBand  Answers whether a widget key is a full-bleed band.
 * @return {Array} Alternating `{band: true, widget}` / `{band: false, widgets, rowOffset}`.
 */
export function runsFor(widgets, isBand) {
	const out = []

	for (const widget of widgets || []) {
		if (isBand(widget.widgetKey) === true) {
			out.push({ band: true, widget })
			continue
		}

		const last = out[out.length - 1]
		if (last && last.band === false) {
			last.widgets.push(widget)
		} else {
			out.push({ band: false, widgets: [widget], rowOffset: 0 })
		}
	}

	for (const run of out) {
		if (run.band === true) {
			continue
		}

		run.rowOffset = Math.min(
			...run.widgets.map((w) => Math.max(0, Number(w.gridY) || 0)),
		)
	}

	return out
}

/**
 * Place one widget on the 12-column grid.
 *
 * The geometry is the manifest's, not a portal variant: 12 columns, `gridX`
 * and `gridY` zero-based, `gridWidth`/`gridHeight` spans. A page authored in
 * OpenBuild's Page Designer therefore lands in the same cells here.
 *
 * `gridX + gridWidth > 12` is CLAMPED rather than thrown on. The manifest
 * validator already rejects it at author time with the canonical message; at
 * render time on a public page, clamping shows the content and a throw shows
 * nothing.
 *
 * `rowOffset` re-bases the row onto the run this widget renders in. It defaults
 * to 0, so a caller that does not split runs still gets absolute geometry.
 *
 * @param {object} widget    The placement.
 * @param {number} rowOffset The run's first authored row.
 * @return {object} The style bindings.
 */
export function cellStyle(widget, rowOffset = 0) {
	const x = Math.max(0, Math.min(11, Number(widget.gridX) || 0))
	const width = Math.max(1, Math.min(12 - x, Number(widget.gridWidth) || 12))
	const height = Math.max(1, Number(widget.gridHeight) || 1)
	const row = Math.max(0, (Number(widget.gridY) || 0) - (Number(rowOffset) || 0))

	return {
		gridColumn: `${x + 1} / span ${width}`,
		gridRow: `${row + 1} / span ${height}`,
	}
}
