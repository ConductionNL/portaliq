/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A collection's rows grouped by its declared `groupByField`
 * (collection-group-by-field), shared by the React portal (PageView.jsx) and
 * the site (ContributionPage.vue).
 *
 * A contribution may declare `groupByField` on a collection: the row field
 * whose value the rows are grouped by. learniq declares `groupByField:
 * 'learnerRef'` on a guardian's grades, attendance, absence reports and
 * report cards, so a parent with two children sees one table per child
 * instead of one mixed table.
 *
 * The group heading is the name of the row the value points at, read from
 * the contribution's `guardianAudience.children` collection (the guardian's
 * own children, scoped by the server like every collection). A value with no
 * such row is shown as it is, and rows without a value go last under a
 * heading the caller names ("Other").
 *
 * Grouping only shows when it tells the resident something: with one group,
 * or none, the table renders as before.
 *
 * Pure functions, no imports, so `tests/collection-groups.spec.mjs` runs
 * them as a plain node script.
 *
 * @spec openspec/changes/collection-group-by-field/tasks.md#T1
 */

/**
 * The collection whose rows name the group values: the contribution's
 * `guardianAudience.children`, when it declares one. Null otherwise.
 *
 * @param {object|null} contribution The contribution.
 * @return {object|null}
 * @spec openspec/changes/collection-group-by-field/tasks.md#T1
 */
export function groupLabelCollection(contribution) {
	const id = contribution?.guardianAudience?.children
	if (typeof id !== 'string' || id === '') {
		return null
	}
	return (contribution.collections || []).find((collection) => collection?.id === id) || null
}

/**
 * The field a collection groups its rows by, or '' when it does not.
 *
 * @param {object|null} collection The collection.
 * @return {string}
 * @spec openspec/changes/collection-group-by-field/tasks.md#T1
 */
export function groupFieldOf(collection) {
	const field = collection?.groupByField
	return typeof field === 'string' ? field : ''
}

/**
 * Whether any collection of a set groups its rows.
 *
 * @param {Array<object>} collections The collections.
 * @return {boolean}
 * @spec openspec/changes/collection-group-by-field/tasks.md#T1
 */
export function anyGrouped(collections) {
	return (collections || []).some((collection) => groupFieldOf(collection) !== '')
}

/**
 * A row's id, wherever the register put it.
 *
 * @param {object} row The row.
 * @return {string}
 */
function idOf(row) {
	return String(row?.id || row?.uuid || row?.['@self']?.id || '')
}

/**
 * A readable name for a row: given and family name, else a display name,
 * name or title. '' when it has none.
 *
 * @param {object} row The row.
 * @return {string}
 * @spec openspec/changes/collection-group-by-field/tasks.md#T1
 */
export function nameOf(row) {
	const person = [row?.givenName, row?.familyName]
		.filter((part) => typeof part === 'string' && part.trim() !== '')
		.join(' ')
		.trim()
	if (person !== '') {
		return person
	}
	for (const key of ['displayName', 'name', 'title']) {
		if (typeof row?.[key] === 'string' && row[key].trim() !== '') {
			return row[key].trim()
		}
	}
	return ''
}

/**
 * The value a row is grouped under, as a string ('' for none).
 *
 * @param {object} row The row.
 * @param {string} field The group field.
 * @return {string}
 */
function valueOf(row, field) {
	const value = row?.[field]
	if (typeof value === 'string') {
		return value
	}
	if (typeof value === 'number') {
		return String(value)
	}
	return ''
}

/**
 * The rows in groups, `[{value, label, rows}]`, or [] when the table should
 * render ungrouped: no group field, or fewer than two groups.
 *
 * Groups are ordered by their heading, rows keep their order inside a group,
 * and the rows without a value come last with an empty label.
 *
 * @param {Array<object>} rows The collection's rows.
 * @param {string} field The group field.
 * @param {Array<object>} labelRows The rows that name the values (the children).
 * @return {Array<{value: string, label: string, rows: Array<object>}>}
 * @spec openspec/changes/collection-group-by-field/tasks.md#T1
 */
export function groupRows(rows, field, labelRows = []) {
	if (!field || !Array.isArray(rows) || rows.length === 0) {
		return []
	}
	const names = new Map()
	for (const row of labelRows || []) {
		const id = idOf(row)
		const name = nameOf(row)
		if (id !== '' && name !== '') {
			names.set(id, name)
		}
	}

	const groups = new Map()
	for (const row of rows) {
		const value = valueOf(row, field)
		if (!groups.has(value)) {
			groups.set(value, { value, label: value === '' ? '' : names.get(value) || value, rows: [] })
		}
		groups.get(value).rows.push(row)
	}
	if (groups.size < 2) {
		return []
	}

	const named = [...groups.values()].filter((group) => group.value !== '')
	named.sort((a, b) => a.label.localeCompare(b.label, undefined, { numeric: true, sensitivity: 'base' }))
	const rest = groups.get('')
	return rest ? [...named, rest] : named
}
