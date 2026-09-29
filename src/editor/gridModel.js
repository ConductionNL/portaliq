/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The page grid as data: placements on the shared 12-column grid.
 *
 * Every function here is pure and returns a NEW array, never a mutated one.
 * That is what makes undo cheap and safe: a snapshot taken before a change can
 * never be edited by the change itself.
 *
 * A placement is the manifest-v2 widget entry a page stores:
 * `{id, widgetKey, slot, gridX, gridY, gridWidth, gridHeight, props}`.
 *
 * @spec openspec/changes/portal-in-place-editing/specs/portal-page-designer/spec.md#requirement-the-admin-designer-and-the-portal-edit-mode-must-share-one-editor-core-req-pie-002
 */

/** The number of columns every page grid has. */
export const GRID_COLUMNS = 12

/**
 * A deep copy of placements, detached from whatever held them.
 *
 * @param {Array<object>} widgets The placements.
 * @return {Array<object>} The copy.
 * @spec openspec/changes/portal-in-place-editing/specs/portal-page-designer/spec.md#requirement-editor-changes-must-be-undoable-and-redoable-req-pie-004
 */
export function cloneWidgets(widgets) {
	return JSON.parse(JSON.stringify(widgets || []))
}

/**
 * Give every stored placement a stable id, a slot, a geometry and a props object.
 *
 * The grid keys its items by id, and a placement without one gets a new key on
 * every render, which tears the DOM node down mid-drag.
 *
 * @param {Array<object>} widgets The stored placements.
 * @return {Array<object>} The normalised placements.
 * @spec openspec/changes/portal-in-place-editing/specs/portal-page-designer/spec.md#requirement-the-admin-designer-and-the-portal-edit-mode-must-share-one-editor-core-req-pie-002
 */
export function normaliseWidgets(widgets) {
	return (Array.isArray(widgets) ? widgets : []).map((widget, index) => ({
		slot: 'body',
		gridX: 0,
		gridY: index,
		gridWidth: GRID_COLUMNS,
		gridHeight: 4,
		...widget,
		id: widget.id || `widget-${index + 1}`,
		props: { ...(widget.props || {}) },
	}))
}

/**
 * The placement as the page stores it, without anything a grid engine added.
 *
 * @param {object} widget A placement.
 * @return {object} The stored shape.
 * @spec openspec/changes/portal-in-place-editing/specs/portal-page-designer/spec.md#requirement-the-admin-designer-and-the-portal-edit-mode-must-share-one-editor-core-req-pie-002
 */
export function storedWidget(widget) {
	return {
		id: widget.id,
		widgetKey: widget.widgetKey,
		slot: widget.slot || 'body',
		gridX: Number(widget.gridX) || 0,
		gridY: Number(widget.gridY) || 0,
		gridWidth: Number(widget.gridWidth) || GRID_COLUMNS,
		gridHeight: Number(widget.gridHeight) || 1,
		props: JSON.parse(JSON.stringify(widget.props || {})),
	}
}

/**
 * A placement id that is not taken yet: `<key>-<n>`.
 *
 * @param {Array<object>} widgets The placements.
 * @param {string} key The widget key.
 * @return {string} The id.
 * @spec openspec/changes/portal-in-place-editing/specs/portal-page-designer/spec.md#requirement-the-admin-designer-and-the-portal-edit-mode-must-share-one-editor-core-req-pie-002
 */
export function nextWidgetId(widgets, key) {
	const taken = new Set((widgets || []).map((w) => w.id))
	let counter = 1
	while (taken.has(`${key}-${counter}`)) {
		counter += 1
	}
	return `${key}-${counter}`
}

/**
 * Place a widget BELOW everything already on the page.
 *
 * Not in the first free cell: an author who adds a widget must be able to find
 * it, and a grid that squeezes it into a gap somewhere in the middle looks like
 * nothing happened.
 *
 * @param {Array<object>} widgets The placements.
 * @param {string} key The widget key.
 * @param {{gridWidth: number, gridHeight: number}} size The first size.
 * @return {{widgets: Array<object>, id: string}} The new placements and the new id.
 * @spec openspec/changes/portal-in-place-editing/specs/portal-page-designer/spec.md#requirement-the-admin-designer-and-the-portal-edit-mode-must-share-one-editor-core-req-pie-002
 */
export function addWidget(widgets, key, size) {
	const bottom = (widgets || []).reduce(
		(max, w) =>
			Math.max(max, (Number(w.gridY) || 0) + (Number(w.gridHeight) || 1)),
		0,
	)
	const id = nextWidgetId(widgets, key)
	return {
		id,
		widgets: [
			...cloneWidgets(widgets),
			{
				id,
				widgetKey: key,
				slot: 'body',
				gridX: 0,
				gridY: bottom,
				gridWidth: Math.min(Number(size?.gridWidth) || 6, GRID_COLUMNS),
				gridHeight: Number(size?.gridHeight) || 4,
				props: {},
			},
		],
	}
}

/**
 * Remove a placement.
 *
 * @param {Array<object>} widgets The placements.
 * @param {string} id The placement id.
 * @return {Array<object>} The remaining placements.
 * @spec openspec/changes/portal-in-place-editing/specs/portal-page-designer/spec.md#requirement-the-admin-designer-and-the-portal-edit-mode-must-share-one-editor-core-req-pie-002
 */
export function removeWidget(widgets, id) {
	return cloneWidgets(widgets).filter((w) => w.id !== id)
}

/**
 * Take a grid engine's new geometry, merged by id.
 *
 * Only the geometry is taken: the engine owns where a widget is and nothing
 * else, and a payload that round-tripped `props` through the layout engine
 * would make the engine the owner of content it never reads.
 *
 * @param {Array<object>} widgets The placements.
 * @param {Array<object>} layout The engine's items.
 * @return {{widgets: Array<object>, changed: boolean}} The placements and whether any moved.
 * @spec openspec/changes/portal-in-place-editing/specs/portal-page-designer/spec.md#requirement-the-admin-designer-and-the-portal-edit-mode-must-share-one-editor-core-req-pie-002
 */
export function applyLayout(widgets, layout) {
	const next = cloneWidgets(widgets)
	let changed = false
	for (const item of layout || []) {
		const target = next.find((w) => w.id === item.id)
		if (!target) {
			continue
		}
		const geometry = {
			gridX: Number(item.gridX) || 0,
			gridY: Number(item.gridY) || 0,
			gridWidth: Number(item.gridWidth) || target.gridWidth,
			gridHeight: Number(item.gridHeight) || target.gridHeight,
		}
		for (const [key, value] of Object.entries(geometry)) {
			if (target[key] !== value) {
				target[key] = value
				changed = true
			}
		}
	}
	return { widgets: next, changed }
}

/**
 * Write one prop on one placement; `undefined` deletes it.
 *
 * @param {Array<object>} widgets The placements.
 * @param {string} id The placement id.
 * @param {string} name The prop name.
 * @param {*} value The value.
 * @return {Array<object>} The placements.
 * @spec openspec/changes/portal-in-place-editing/specs/portal-page-designer/spec.md#requirement-the-admin-designer-and-the-portal-edit-mode-must-share-one-editor-core-req-pie-002
 */
export function setWidgetProp(widgets, id, name, value) {
	const next = cloneWidgets(widgets)
	const target = next.find((w) => w.id === id)
	if (target) {
		target.props = { ...(target.props || {}) }
		if (value === undefined) {
			delete target.props[name]
		} else {
			target.props[name] = value
		}
	}
	return next
}

/**
 * Replace a placement's props wholesale, as a shared form hands them over.
 *
 * @param {Array<object>} widgets The placements.
 * @param {string} id The placement id.
 * @param {object} props The new props.
 * @return {Array<object>} The placements.
 * @spec openspec/changes/portal-in-place-editing/specs/portal-page-designer/spec.md#requirement-a-widget-with-a-shared-configuration-form-must-be-configured-through-it-req-pie-003
 */
export function replaceWidgetProps(widgets, id, props) {
	const next = cloneWidgets(widgets)
	const target = next.find((w) => w.id === id)
	if (target) {
		target.props = JSON.parse(JSON.stringify(props || {}))
	}
	return next
}
