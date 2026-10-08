/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Where a widget sits on the 12-column page grid: ONE function for the public
 * renderer and the editor.
 *
 * `src/site/components/WidgetGrid.vue` places a cell with this, and the editor
 * (`gridModel.storedWidget()`, `normaliseWidgets()`) stores what this answers.
 * So a widget the editor shows in columns 7 to 12 is a widget the visitor sees
 * in columns 7 to 12: the two cannot read the same numbers differently. This
 * file is imported by the site ENTRY, so it stays a few lines with no imports.
 *
 * @spec openspec/specs/portal-in-place-editing/spec.md#requirement-the-editor-and-the-public-page-must-place-widgets-identically-req-pie-008
 */

/** The number of columns every page grid has. */
export const GRID_COLUMNS = 12

/**
 * The clamped cell of a widget: a column inside the grid, a width that does
 * not run past its right edge, at least one row high.
 *
 * @param {{gridX?: number, gridY?: number, gridWidth?: number, gridHeight?: number}} widget The placement.
 * @return {{gridX: number, gridY: number, gridWidth: number, gridHeight: number}} The cell.
 * @spec openspec/specs/portal-in-place-editing/spec.md#requirement-the-editor-and-the-public-page-must-place-widgets-identically-req-pie-008
 */
export function cellOf(widget) {
	const gridX = Math.max(
		0,
		Math.min(GRID_COLUMNS - 1, Math.trunc(Number(widget?.gridX) || 0)),
	)
	const gridWidth = Math.max(
		1,
		Math.min(
			GRID_COLUMNS - gridX,
			Math.trunc(Number(widget?.gridWidth) || GRID_COLUMNS),
		),
	)
	const gridY = Math.max(0, Math.trunc(Number(widget?.gridY) || 0))
	const gridHeight = Math.max(1, Math.trunc(Number(widget?.gridHeight) || 1))
	return { gridX, gridY, gridWidth, gridHeight }
}

/**
 * The CSS grid placement of a widget, as the public renderer applies it.
 *
 * @param {object} widget The placement.
 * @return {{gridColumn: string, gridRow: string}} The style.
 * @spec openspec/specs/portal-in-place-editing/spec.md#requirement-the-editor-and-the-public-page-must-place-widgets-identically-req-pie-008
 */
export function cellStyleOf(widget) {
	const cell = cellOf(widget)
	return {
		gridColumn: `${cell.gridX + 1} / span ${cell.gridWidth}`,
		gridRow: `${cell.gridY + 1} / span ${cell.gridHeight}`,
	}
}

/**
 * How tall one grid row is drawn, in CSS pixels.
 *
 * The number the fleet's grid lays out with. It is here rather than read off
 * the DOM because a drop has to answer "which row is this" before anything is
 * placed, and a measurement taken from an empty canvas has no row to measure.
 *
 * @type {number}
 */
export const GRID_ROW_HEIGHT = 56

/**
 * The cell a drop at a point on the canvas means.
 *
 * Pure arithmetic over the canvas rectangle, so a drop can be tested without a
 * browser and reads the same as the placement the editor then stores.
 *
 * @param {{x: number, y: number, left: number, top: number, width: number}} drop
 *        The pointer position and the canvas rectangle, as a DragEvent and
 *        getBoundingClientRect() give them.
 * @param {number} [rowHeight] The row height, for a canvas that scales it.
 * @return {{gridX: number, gridY: number}} The cell, clamped into the grid.
 * @spec openspec/changes/site-nlds-widget-palette/specs/portal-page-designer/spec.md#requirement-an-editor-must-be-able-to-drag-a-widget-from-the-palette-onto-the-grid-req-snw-002
 */
export function cellFromDrop(drop, rowHeight = GRID_ROW_HEIGHT) {
	const width = Number(drop?.width)
	if (Number.isFinite(width) === false || width <= 0) {
		return { gridX: 0, gridY: 0 }
	}

	const insideX = Number(drop?.x) - Number(drop?.left || 0)
	const insideY = Number(drop?.y) - Number(drop?.top || 0)
	const column = Math.floor((insideX / width) * GRID_COLUMNS)
	const row = Math.floor(
		insideY / Math.max(Number(rowHeight) || GRID_ROW_HEIGHT, 1),
	)

	return {
		gridX: Math.max(
			0,
			Math.min(GRID_COLUMNS - 1, Number.isFinite(column) ? column : 0),
		),
		gridY: Math.max(0, Number.isFinite(row) ? row : 0),
	}
}
