// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The plain rules of a theme page (life-domain-theme-pages): what a theme
// gathers from the contributions, the validity of a product a resident holds,
// the running cases of the theme, and when an action is offered. No Vue, so
// the node specs run it as a plain script.
//
// @spec openspec/changes/life-domain-theme-pages/specs/life-domain-themes/spec.md

import { caseStatus, caseTitle } from '../../../shared/myCases.js'
import { isClosedCase } from '../../components/mijn/cases.js'

/** The most products the theme page shows before it links to the full list. */
export const PRODUCT_LIMIT = 3

/** Where a tagged collection goes on the theme page, by its kind; any other kind lists as tasks. */
const BUCKETS = { products: 'products', cases: 'cases' }

/**
 * What a theme gathers: the tasks, products, cases and actions of every
 * contribution that tagged themselves with its slug. The server already
 * dropped tags the portal does not declare.
 *
 * @param {string} slug The theme.
 * @param {Array<object>} contributions The aggregate's contributions.
 * @return {{tasks: Array<object>, products: Array<object>, cases: Array<object>, actions: Array<object>}} Each entry carries its `app`.
 *
 * @spec openspec/changes/life-domain-theme-pages/tasks.md#t03
 * @spec openspec/changes/life-domain-theme-pages/tasks.md#t10
 */
export function gather(slug, contributions) {
	const out = { tasks: [], products: [], cases: [], actions: [] }
	for (const contribution of Array.isArray(contributions) ? contributions : []) {
		const app = String(contribution?.app || '')
		for (const collection of contribution?.collections || []) {
			if (collection?.theme !== slug) {
				continue
			}
			out[BUCKETS[collection.kind] || 'tasks'].push({ app, collection })
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
 * The label of a meta field: its configured label, else its column's.
 *
 * @param {object} collection The products collection.
 * @param {string} field The meta field.
 * @return {string} The label, or ''.
 */
function metaLabel(collection, field) {
	return (
		collection.fieldConfigs?.[field]?.label
		|| (collection.columns || []).find((column) => column.field === field)?.label
		|| ''
	)
}

/**
 * The meta parts of a product row: each meta field with a value, after its
 * label.
 *
 * @param {object} row The product row.
 * @param {object} collection The products collection.
 * @return {Array<string>} The parts.
 */
function metaParts(row, collection) {
	return (collection?.metaFields || [])
		.map((field) => {
			const value = String(row?.[field] ?? '').trim()
			return value === '' ? '' : `${metaLabel(collection, field)} ${value}`.trim()
		})
		.filter(Boolean)
}

/**
 * A product as the board draws it: title, tag, meta line and last day.
 *
 * @param {object} row The product row.
 * @param {{collection: object, today: string, format: (date: string) => string, t: (key: string, vars?: object) => string}} reading The products collection, today as `YYYY-MM-DD`, how a date is written for the reader, and the translator.
 * @return {{id: string, title: string, state: string, tag: string, meta: string, validUntil: string}} The view.
 *
 * @spec openspec/changes/life-domain-theme-pages/tasks.md#t05
 */
export function productView(row, reading) {
	const { collection, today } = reading
	const state = productState(row, collection, today)
	const from = day(row?.[collection?.validFromField])
	const until = day(row?.[collection?.validUntilField])
	const meta = productMeta(row, { ...reading, state, from })
	const tag = productTag(state, { ...reading, from })
	return {
		...productIdentity(row, collection),
		state,
		tag,
		meta,
		validUntil: lastDayLine(until, reading),
	}
}

/**
 * @param {object} row The product row.
 * @param {object} collection The products collection.
 * @return {{id: string, title: string}} The product's id and title.
 */
function productIdentity(row, collection) {
	return {
		id: String(row?.id ?? row?.uuid ?? ''),
		title: String(row?.[collection?.titleField] ?? row?.title ?? row?.name ?? ''),
	}
}

/**
 * The meta line: the meta fields, and since when the product is in effect
 * once it is.
 *
 * @param {object} row The product row.
 * @param {{collection: object, format: (date: string) => string, t: (key: string, vars?: object) => string, state: string, from: string}} at The collection, the date format, the translator, the product's state and its first day.
 * @return {string} The line.
 */
function productMeta(row, at) {
	const parts = metaParts(row, at.collection)
	if (at.from !== '' && at.state !== 'upcoming') {
		parts.push(at.t('in effect since {date}', { date: at.format(at.from) }))
	}
	return parts.join(' · ')
}

/**
 * @param {string} state The product's state.
 * @param {{format: (date: string) => string, t: (key: string, vars?: object) => string, from: string}} at The date format, the translator and the product's first day.
 * @return {string} The tag the board draws for the state.
 */
function productTag(state, at) {
	const tags = {
		valid: at.t('Valid'),
		expired: at.t('Expired'),
		upcoming: at.t('Starts on {date}', { date: at.format(at.from) }),
	}
	return tags[state]
}

/**
 * @param {string} until The product's last day, or ''.
 * @param {{format: (date: string) => string, t: (key: string, vars?: object) => string}} reading The date format and the translator.
 * @return {string} The last-day line, or '' without a last day.
 */
function lastDayLine(until, reading) {
	return until === ''
		? ''
		: reading.t('Valid until {date}', { date: reading.format(until) })
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
		.map((row, index) => ({
			row,
			index,
			rank: rank[productState(row, collection, today)],
		}))
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
	if (
		label
		&& typeof label.singular === 'string'
		&& typeof label.plural === 'string'
	) {
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
		return (
			Array.isArray(when.value)
			&& when.value.map(String).includes(String(value))
		)
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
		.filter(
			(theme) =>
				theme
				&& typeof theme.slug === 'string'
				&& typeof theme.title === 'string',
		)
		.map((theme) => ({ slug: theme.slug, title: theme.title }))
}

/**
 * The running cases of a cases collection: every row but a closed one, as the
 * theme page lists them.
 *
 * @param {Array<object>} rows The collection's rows.
 * @param {object} collection The `cases` collection.
 * @return {Array<{id: string, title: string, status: string}>} The running cases.
 *
 * @spec openspec/changes/life-domain-theme-pages/tasks.md#t10
 */
export function runningCases(rows, collection) {
	return (Array.isArray(rows) ? rows : [])
		.filter((row) => row && !isClosedCase(row, collection))
		.map((row) => ({
			id: String(row.id ?? row.uuid ?? ''),
			title: caseTitle(row),
			status: caseStatus(row),
		}))
}

/**
 * Every cases collection a contribution tagged with a theme, keyed as the
 * shell keeps known rows (`<app>:<collection>`).
 *
 * @param {Array<object>} contributions The aggregate's contributions.
 * @return {Array<{key: string, slug: string, collection: object}>} The collections.
 *
 * @spec openspec/changes/life-domain-theme-pages/tasks.md#t10
 */
export function themeCaseCollections(contributions) {
	const out = []
	for (const contribution of Array.isArray(contributions) ? contributions : []) {
		for (const collection of contribution?.collections || []) {
			if (
				collection?.kind === 'cases'
				&& typeof collection.theme === 'string'
				&& collection.theme !== ''
				&& collection.id
			) {
				out.push({
					key: `${contribution.app || ''}:${collection.id}`,
					slug: collection.theme,
					collection,
				})
			}
		}
	}
	return out
}

/**
 * How many running cases each theme holds, for the menu: only themes whose
 * cases collections were read and hold a running case are counted.
 *
 * @param {Array<object>} contributions The aggregate's contributions.
 * @param {Record<string, Array<object>>} rows The known rows, by `<app>:<collection>`.
 * @return {Record<string, number>} Theme slug => running cases.
 *
 * @spec openspec/changes/life-domain-theme-pages/tasks.md#t10
 */
export function themeCaseCounts(contributions, rows) {
	const counts = {}
	for (const { key, slug, collection } of themeCaseCollections(contributions)) {
		const running = runningCases(rows?.[key], collection).length
		if (running > 0) {
			counts[slug] = (counts[slug] || 0) + running
		}
	}
	return counts
}
