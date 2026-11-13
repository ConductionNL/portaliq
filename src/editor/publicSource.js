/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The page editor's picker for a block that reads an app's public index
 * (editor-blocks-read-public-app-data, task 4). An editor chooses the app and
 * kind, then only the categories, filters and columns that kind declares
 * (`GET /api/content/catalogue/kinds`): portaliq keeps no list of its own.
 * Free of Vue so the node tests drive it directly.
 *
 * Every function returns a new value; the caller stores it as the block's prop.
 */

/**
 * The filter value the server resolves from a signed-in visitor's own record.
 */
export const VISITOR = 'visitor'

/**
 * @param {unknown} value A value that should be a list.
 * @return {Array} The value, or an empty list.
 */
function listOf(value) {
	return Array.isArray(value) ? value : []
}

/**
 * @param {unknown} value A value that should be a plain object.
 * @return {object} The value, or an empty object.
 */
function objectOf(value) {
	return value !== null && typeof value === 'object' && !Array.isArray(value) ? value : {}
}

/**
 * The declared kinds, kept to the shape the picker reads.
 *
 * @param {unknown} answer The kinds endpoint's `kinds`, or its whole body.
 * @return {Array<{app: string, type: string, label: string, categories: Array<string>, filters: object, columns: Array<{key: string, label: string}>}>}
 * @spec openspec/changes/editor-blocks-read-public-app-data/tasks.md#task-4
 */
export function kindsFrom(answer) {
	const kinds = Array.isArray(answer) ? answer : listOf(answer?.kinds)
	return kinds
		.filter((kind) => typeof kind?.app === 'string' && kind.app !== '' && typeof kind?.type === 'string' && kind.type !== '')
		.map((kind) => ({
			app: kind.app,
			type: kind.type,
			label: String(kind.label || kind.type),
			categories: listOf(kind.categories).map(String),
			filters: Object.fromEntries(
				Object.entries(objectOf(kind.filters)).map(([label, values]) => [label, listOf(values).map(String)]),
			),
			columns: listOf(kind.columns).filter((column) => typeof column?.key === 'string' && column.key !== ''),
		}))
}

/**
 * The value of the app-and-kind select for a source.
 *
 * @param {object|undefined} source The block's source.
 * @return {string} `app/kind`, or '' when the source names no app.
 * @spec openspec/changes/editor-blocks-read-public-app-data/tasks.md#task-4
 */
export function kindValue(source) {
	const { app, kind } = objectOf(source)
	return app && kind ? `${app}/${kind}` : ''
}

/**
 * The declared kind a source reads, or null.
 *
 * @param {Array<object>} kinds The declared kinds.
 * @param {object|undefined} source The block's source.
 * @return {object|null} The kind.
 * @spec openspec/changes/editor-blocks-read-public-app-data/tasks.md#task-4
 */
export function kindFor(kinds, source) {
	const value = kindValue(source)
	return listOf(kinds).find((kind) => `${kind.app}/${kind.type}` === value) || null
}

/**
 * The source after choosing an app and kind. What the new kind does not
 * declare is dropped; a limit or range stays. Choosing none drops an app
 * source and leaves any other source as it was.
 *
 * @param {object|undefined} source The block's source.
 * @param {Array<object>} kinds The declared kinds.
 * @param {string} value `app/kind`, or ''.
 * @return {object|undefined} The new source.
 * @spec openspec/changes/editor-blocks-read-public-app-data/tasks.md#task-4
 */
export function withKind(source, kinds, value) {
	const current = objectOf(source)
	const kind = listOf(kinds).find((entry) => `${entry.app}/${entry.type}` === value)
	if (!kind) {
		return current.app ? undefined : source
	}
	const next = { app: kind.app, kind: kind.type }
	for (const key of ['limit', 'range']) {
		if (current[key] !== undefined) {
			next[key] = current[key]
		}
	}
	const categories = listOf(current.categories).filter((category) => kind.categories.includes(category))
	if (categories.length > 0) {
		next.categories = categories
	}
	const filters = {}
	for (const [label, values] of Object.entries(objectOf(current.filters))) {
		const kept = listOf(values).filter((entry) => entry === VISITOR || listOf(kind.filters[label]).includes(entry))
		if (Object.hasOwn(kind.filters, label) && kept.length > 0) {
			filters[label] = kept
		}
	}
	if (Object.keys(filters).length > 0) {
		next.filters = filters
	}
	return next
}

/**
 * The source with one category switched on or off, in the kind's order.
 *
 * @param {object|undefined} source The block's source.
 * @param {object} kind The declared kind it reads.
 * @param {string} category The category.
 * @param {boolean} on Whether it is chosen.
 * @return {object} The new source.
 * @spec openspec/changes/editor-blocks-read-public-app-data/tasks.md#task-4
 */
export function withCategory(source, kind, category, on) {
	const chosen = new Set(listOf(objectOf(source).categories))
	if (on) {
		chosen.add(category)
	} else {
		chosen.delete(category)
	}
	const next = { ...objectOf(source) }
	const categories = kind.categories.filter((entry) => chosen.has(entry))
	if (categories.length > 0) {
		next.categories = categories
	} else {
		delete next.categories
	}
	return next
}

/**
 * The value chosen for one filter, or '' for any.
 *
 * @param {object|undefined} source The block's source.
 * @param {string} label The filter's label.
 * @return {string} The value.
 * @spec openspec/changes/editor-blocks-read-public-app-data/tasks.md#task-4
 */
export function filterValue(source, label) {
	return String(listOf(objectOf(objectOf(source).filters)[label])[0] ?? '')
}

/**
 * The source with one filter set to a declared value, to the visitor's own,
 * or to any ('').
 *
 * @param {object|undefined} source The block's source.
 * @param {object} kind The declared kind it reads.
 * @param {string} label The filter's label.
 * @param {string} value The value, `visitor`, or ''.
 * @return {object} The new source.
 * @spec openspec/changes/editor-blocks-read-public-app-data/tasks.md#task-4
 */
export function withFilter(source, kind, label, value) {
	const next = { ...objectOf(source) }
	const filters = { ...objectOf(next.filters) }
	const declared = listOf(kind.filters[label])
	if (Object.hasOwn(kind.filters, label) && (value === VISITOR || declared.includes(value))) {
		filters[label] = [value]
	} else {
		delete filters[label]
	}
	if (Object.keys(filters).length > 0) {
		next.filters = filters
	} else {
		delete next.filters
	}
	return next
}

/**
 * Whether a column is among the block's columns.
 *
 * @param {Array<object>|undefined} columns The block's columns.
 * @param {string} key The column's key.
 * @return {boolean}
 * @spec openspec/changes/editor-blocks-read-public-app-data/tasks.md#task-4
 */
export function hasColumn(columns, key) {
	return listOf(columns).some((column) => column?.key === key)
}

/**
 * The block's columns with one declared column switched on or off, in the
 * order the kind declares them, each with the kind's label.
 *
 * @param {Array<object>|undefined} columns The block's columns.
 * @param {object} kind The declared kind the block reads.
 * @param {string} key The column's key.
 * @param {boolean} on Whether it is shown.
 * @return {Array<{key: string, label: string}>|undefined} The columns, or undefined for none.
 * @spec openspec/changes/editor-blocks-read-public-app-data/tasks.md#task-4
 */
export function withColumn(columns, kind, key, on) {
	const chosen = new Set(listOf(columns).map((column) => column?.key))
	if (on) {
		chosen.add(key)
	} else {
		chosen.delete(key)
	}
	const next = kind.columns
		.filter((column) => chosen.has(column.key))
		.map((column) => ({ key: column.key, label: column.label }))
	return next.length > 0 ? next : undefined
}
