// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The plain rules of a theme page (life-domain-theme-pages): what a theme
// gathers from the contributions, the validity of a product a resident holds,
// and when an action is offered. Imports nothing, so the node specs run it as
// a plain script.
//
// @spec openspec/changes/life-domain-theme-pages/specs/portal-themes/spec.md

/** The most products the theme page shows before it links to the full list. */
export const PRODUCT_LIMIT = 3

/**
 * What a theme gathers: the tasks, products and actions of every contribution
 * that tagged themselves with its slug. The server already dropped tags the
 * portal does not declare.
 *
 * @param {string} slug The theme.
 * @param {Array<object>} contributions The aggregate's contributions.
 * @return {{tasks: Array<object>, products: Array<object>, actions: Array<object>}} Each entry carries its `app`.
 *
 * @spec openspec/changes/life-domain-theme-pages/tasks.md#t03
 */
export function gather(slug, contributions) {
	const out = { tasks: [], products: [], actions: [] }
	for (const contribution of Array.isArray(contributions) ? contributions : []) {
		const app = String(contribution?.app || '')
		for (const collection of contribution?.collections || []) {
			if (collection?.theme !== slug) {
				continue
			}
			out[collection.kind === 'products' ? 'products' : 'tasks'].push({ app, collection })
		}
		for (const action of contribution?.actions || []) {
			if (action?.theme === slug) {
				out.actions.push({ app, action })
			}
		}
	}
	return out
}

/**
 * The first ten characters of a date or date-time, as `YYYY-MM-DD`, or ''.
 *
 * @param {*} value The value.
 * @return {string} The day.
 */
function day(value) {
	const text = String(value ?? '').slice(0, 10)
	return /^\d{4}-\d{2}-\d{2}$/.test(text) ? text : ''
}

/**
 * Where a product stands: `upcoming` when it starts after today, `expired`
 * when its last day is before today, else `valid` (also without any dates).
 *
 * @param {object} row The product row.
 * @param {object} collection The products collection.
 * @param {string} today Today as `YYYY-MM-DD`.
 * @return {'valid'|'expired'|'upcoming'} The state.
 *
 * @spec openspec/changes/life-domain-theme-pages/tasks.md#t05
 */
export function productState(row, collection, today) {
	const from = day(row?.[collection?.validFromField])
	const until = day(row?.[collection?.validUntilField])
	if (from !== '' && from > today) {
		return 'upcoming'
	}
	if (until !== '' && until < today) {
		return 'expired'
	}
	return 'valid'
}

/**
 * A product as the board draws it: title, tag, meta line and last day.
 *
 * @param {object} row The product row.
 * @param {object} collection The products collection.
 * @param {string} today Today as `YYYY-MM-DD`.
 * @param {(date: string) => string} format A date as written for the reader.
 * @param {(key: string, vars?: object) => string} t The translator.
 * @return {{id: string, title: string, state: string, tag: string, meta: string, validUntil: string}} The view.
 *
 * @spec openspec/changes/life-domain-theme-pages/tasks.md#t05
 */
export function productView(row, collection, today, format, t) {
	const state = productState(row, collection, today)
	const from = day(row?.[collection?.validFromField])
	const until = day(row?.[collection?.validUntilField])
	const title = String(row?.[collection?.titleField] ?? row?.title ?? row?.name ?? '')
	const meta = (collection?.metaFields || [])
		.map((field) => {
			const value = String(row?.[field] ?? '').trim()
			const label = collection.fieldConfigs?.[field]?.label
				|| (collection.columns || []).find((column) => column.field === field)?.label
				|| ''
			return value === '' ? '' : `${label} ${value}`.trim()
		})
		.filter(Boolean)
	if (from !== '' && state !== 'upcoming') {
		meta.push(t('in effect since {date}', { date: format(from) }))
	}
	const tags = {
		valid: t('Valid'),
		expired: t('Expired'),
		upcoming: t('Starts on {date}', { date: format(from) }),
	}
	return {
		id: String(row?.id ?? row?.uuid ?? ''),
		title,
		state,
		tag: tags[state],
		meta: meta.join(' · '),
		validUntil: until === '' ? '' : t('Valid until {date}', { date: format(until) }),
	}
}

/**
 * The products in the order the page shows them: valid first, then those that
 * have not started, expired last; each group in the order the app gave.
 *
 * @param {Array<object>} rows The product rows.
 * @param {object} collection The products collection.
 * @param {string} today Today as `YYYY-MM-DD`.
 * @return {Array<object>} A sorted copy.
 *
 * @spec openspec/changes/life-domain-theme-pages/tasks.md#t05
 */
export function sortedProducts(rows, collection, today) {
	const rank = { valid: 0, upcoming: 1, expired: 2 }
	return (Array.isArray(rows) ? rows : [])
		.map((row, index) => ({ row, index, rank: rank[productState(row, collection, today)] }))
		.sort((a, b) => a.rank - b.rank || a.index - b.index)
		.map((entry) => entry.row)
}

/**
 * The "2 vergunningen" line.
 *
 * @param {number} count How many products.
 * @param {object} collection The products collection.
 * @param {(key: string, vars?: object) => string} t The translator.
 * @return {string} The line.
 *
 * @spec openspec/changes/life-domain-theme-pages/tasks.md#t05
 */
export function countLine(count, collection, t) {
	const label = collection?.countLabel
	if (label && typeof label.singular === 'string' && typeof label.plural === 'string') {
		return `${count} ${count === 1 ? label.singular : label.plural}`
	}
	return t('{count} in total', { count })
}

/**
 * Whether an action's `when` holds for one row.
 *
 * @param {{field: string, op: string, value: *}|undefined} when The condition.
 * @param {object} row The row.
 * @return {boolean} True without a condition, or when the row satisfies it.
 *
 * @spec openspec/changes/life-domain-theme-pages/tasks.md#t06
 */
export function whenHolds(when, row) {
	if (!when || typeof when !== 'object') {
		return true
	}
	const value = row?.[when.field]
	if (when.op === 'eq') {
		return String(value) === String(when.value)
	}
	if (when.op === 'neq') {
		return String(value) !== String(when.value)
	}
	if (when.op === 'in') {
		return Array.isArray(when.value) && when.value.map(String).includes(String(value))
	}
	return false
}

/**
 * The actions to offer: those without `when`, and those whose `when` holds for
 * at least one product the resident holds in the action's own collection.
 * A condition with no product to test against does not hold.
 *
 * @param {Array<{app: string, action: object}>} actions The theme's actions.
 * @param {Array<{app: string, collection: object, rows: Array<object>}>} products The theme's product collections with their rows.
 * @return {Array<{app: string, action: object}>} The actions to show.
 *
 * @spec openspec/changes/life-domain-theme-pages/tasks.md#t06
 */
export function offeredActions(actions, products) {
	return actions.filter(({ app, action }) => {
		if (!action.when) {
			return true
		}
		return products.some(
			(product) =>
				product.app === app
				&& product.collection.register === action.register
				&& product.collection.schema === action.schema
				&& product.rows.some((row) => whenHolds(action.when, row)),
		)
	})
}

/**
 * The update actions a product row offers: those of the same app, register and
 * schema that are tagged with the theme and whose `when` holds for the row.
 *
 * @param {object} row The product row.
 * @param {{app: string, collection: object}} product The product collection.
 * @param {Array<{app: string, action: object}>} actions The theme's actions.
 * @return {Array<object>} The actions.
 *
 * @spec openspec/changes/life-domain-theme-pages/tasks.md#t05
 */
export function rowActions(row, product, actions) {
	return actions
		.filter(
			({ app, action }) =>
				app === product.app
				&& action.type === 'update'
				&& action.register === product.collection.register
				&& action.schema === product.collection.schema
				&& whenHolds(action.when, row),
		)
		.map(({ action }) => action)
}

/**
 * The page the shell opens for a theme: a menu entry per theme with content.
 *
 * @param {Array<object>} themes The aggregate's `themes`.
 * @return {Array<{slug: string, title: string}>} The themes to list.
 *
 * @spec openspec/changes/life-domain-theme-pages/tasks.md#t04
 */
export function themesToList(themes) {
	return (Array.isArray(themes) ? themes : [])
		.filter((theme) => theme && typeof theme.slug === 'string' && typeof theme.title === 'string')
		.map((theme) => ({ slug: theme.slug, title: theme.title }))
}
