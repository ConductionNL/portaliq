// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// THE VISITOR'S OWN DATA, FOR THE MIJN OMGEVING WIDGETS
// (site-nlds-widget-palette T9, REQ-SNW-012).
//
// A widget in the Mijn omgeving group reads what the signed-in shell already
// holds: the portal api bound to the visitor's bearer and the contributions
// the server answered for that visitor. A placement only NAMES an app and one
// of its collections or pages; the name is looked up in those contributions,
// so a placement cannot point at an endpoint, a register or a subject the
// server did not hand this visitor. Every read goes through the api's own
// subject-scoped routes, the same ones the signed-in pages use.
//
// Without the shell's data (signed out) there is nothing to look up and
// nothing is read: the widget shows a sign-in prompt instead.
//
// Imports only plain modules, so tests/mijn-widgets.spec.mjs runs it in node.

import { authoredLink } from '../components/mijn/links.js'

/**
 * The shell's data for a Mijn omgeving widget, or null when signed out.
 *
 * @param {object|null} mijn What the shell handed down.
 * @return {object|null} The data, when it carries an api.
 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-a-mijn-omgeving-widget-must-show-only-the-visitors-own-data-req-snw-012
 */
export function ownShell(mijn) {
	return mijn && typeof mijn === 'object' && mijn.api ? mijn : null
}

/**
 * One app's contribution, as the server answered it for this visitor.
 *
 * @param {object|null} mijn The shell's data.
 * @param {string} app The app the placement names.
 * @return {object|null} The contribution, or null when the visitor has none from it.
 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-a-mijn-omgeving-widget-must-show-only-the-visitors-own-data-req-snw-012
 */
export function ownContribution(mijn, app) {
	const shell = ownShell(mijn)
	const wanted = String(app || '').trim()
	if (!shell || wanted === '' || !Array.isArray(shell.contributions)) {
		return null
	}
	return shell.contributions.find((entry) => entry?.app === wanted) || null
}

/**
 * A collection the app's contribution declares, by its id.
 *
 * @param {object|null} mijn The shell's data.
 * @param {string} app The app the placement names.
 * @param {string} id The collection the placement names.
 * @return {object|null} The collection, or null when the contribution does not declare it.
 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-a-mijn-omgeving-widget-must-show-only-the-visitors-own-data-req-snw-012
 */
export function ownCollection(mijn, app, id) {
	const contribution = ownContribution(mijn, app)
	const wanted = String(id || '').trim()
	if (!contribution || wanted === '') {
		return null
	}
	return (
		(contribution.collections || []).find(
			(collection) => collection && collection.id === wanted,
		) || null
	)
}

/**
 * The first block of a kind on one page of the app's contribution: the
 * calendar a calendar widget draws, the figures a figures widget draws.
 *
 * @param {object|null} mijn The shell's data.
 * @param {string} app The app the placement names.
 * @param {string} page The page the placement names.
 * @param {string} type The block type.
 * @return {object|null} The block, or null.
 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-a-mijn-omgeving-widget-must-show-only-the-visitors-own-data-req-snw-012
 */
export function ownBlock(mijn, app, page, type) {
	const contribution = ownContribution(mijn, app)
	const wanted = String(page || '').trim()
	if (!contribution || wanted === '') {
		return null
	}
	const found = (contribution.pages || []).find((entry) => entry?.id === wanted)
	return (
		(found?.blocks || []).find((block) => block && block.type === type) || null
	)
}

/**
 * Read one collection's rows through the shell's subject-scoped api. A read
 * that failed answers null, so the widget can say it could not be loaded
 * rather than that there is nothing (REQ-SMO-009).
 *
 * @param {object|null} mijn The shell's data.
 * @param {object|null} collection A collection `ownCollection` found.
 * @return {Promise<Array<object>|null>} The rows, or null.
 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-a-mijn-omgeving-widget-must-show-only-the-visitors-own-data-req-snw-012
 */
export async function ownRows(mijn, collection) {
	const shell = ownShell(mijn)
	if (!shell || !collection || typeof shell.api.fetchCollection !== 'function') {
		return null
	}
	try {
		const rows = await shell.api.fetchCollection(collection, { orNull: true })
		return Array.isArray(rows) ? rows : null
	} catch {
		return null
	}
}

/**
 * Read the rows of several collections into a store keyed by collection id,
 * `{[id]: {objects}}`, the shape `calendarItems()` reads. A collection the
 * contribution does not declare is skipped.
 *
 * @param {object|null} mijn The shell's data.
 * @param {string} app The app.
 * @param {Array<string>} ids The collection ids.
 * @return {Promise<{store: object, failed: boolean}>} The store and whether a read failed.
 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-a-mijn-omgeving-widget-must-show-only-the-visitors-own-data-req-snw-012
 */
export async function ownStore(mijn, app, ids) {
	const store = {}
	let failed = false
	for (const id of [...new Set(ids || [])]) {
		const collection = ownCollection(mijn, app, id)
		if (!collection) {
			continue
		}
		const rows = await ownRows(mijn, collection)
		failed = failed || rows === null
		store[id] = { objects: rows || [] }
	}
	return { store, failed }
}

/**
 * A row's id, as the scoped reads answer it.
 *
 * @param {object|null} row The row.
 * @return {string} Its id, or ''.
 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-a-mijn-omgeving-widget-must-show-only-the-visitors-own-data-req-snw-012
 */
export function rowId(row) {
	return String(row?.id || row?.uuid || row?.['@self']?.id || '')
}

/**
 * The row the route names, among the visitor's own rows only: a page at
 * `/zaak/<id>` cannot open somebody else's record, because it is never in
 * the list the scoped read answered.
 *
 * @param {Array<object>|null} rows The visitor's rows.
 * @param {string} id The id from the route.
 * @return {object|null} The row, or null.
 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-a-mijn-omgeving-widget-must-show-only-the-visitors-own-data-req-snw-012
 */
export function ownRecord(rows, id) {
	const wanted = String(id || '')
	if (wanted === '' || !Array.isArray(rows)) {
		return null
	}
	return rows.find((row) => rowId(row) === wanted) || null
}

/**
 * The ways in a signed-out prompt offers: the portal's own, as the shell
 * derived them, never an address a placement typed.
 *
 * @param {Array<object>} routes The shell's sign-in routes, `{key?, mode, label, href}`.
 * @return {Array<{id: string, label: string, href: string}>} The ways in.
 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-a-mijn-omgeving-widget-must-show-only-the-visitors-own-data-req-snw-012
 */
export function signInWays(routes) {
	return (Array.isArray(routes) ? routes : [])
		.filter(
			(route) =>
				route
				&& typeof route.href === 'string'
				&& route.href !== ''
				&& typeof route.label === 'string'
				&& route.label.trim() !== '',
		)
		.map((route) => ({
			id: String(route.key || route.mode || route.label),
			label: route.label.trim(),
			href: route.href,
		}))
}

/**
 * Authored figures as the one row and the cards `KpiCards` draws: each
 * `{value, label}` with a label becomes a card over its own field.
 *
 * @param {Array<object>} items The authored figures.
 * @return {{cards: Array<{field: string, label: string}>, row: object|null}} The cards and their row.
 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-a-mijn-omgeving-widget-must-show-only-the-visitors-own-data-req-snw-012
 */
export function authoredFigures(items) {
	const cards = []
	const row = {}
	;(Array.isArray(items) ? items : []).forEach((item, index) => {
		const label = String(item?.label ?? '').trim()
		const value = item?.value
		if (
			label === ''
			|| (typeof value !== 'number' && typeof value !== 'string')
		) {
			return
		}
		const field = `figure${index}`
		cards.push({ field, label })
		row[field] = value
	})
	return { cards, row: cards.length > 0 ? row : null }
}

/** The states a step may be in; anything else is still to come. */
const STEP_STATES = ['done', 'current', 'upcoming']

/**
 * Authored steps as `ProcessSteps` draws them: a label each, a state of
 * done, current or upcoming, and the line under it.
 *
 * @param {Array<object>} steps The authored steps, `{label, state?, text?, date?}`.
 * @return {Array<{label: string, state: string, description: string, date: string}>} The steps.
 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-every-nl-design-system-component-must-be-placeable-or-carry-a-reason-req-snw-010
 */
export function authoredSteps(steps) {
	return (Array.isArray(steps) ? steps : [])
		.map((step) => ({
			label: String(step?.label ?? '').trim(),
			state: STEP_STATES.includes(step?.state) ? step.state : 'upcoming',
			description: String(step?.text ?? step?.description ?? '').trim(),
			date: typeof step?.date === 'string' ? step.date : '',
		}))
		.filter((step) => step.label !== '')
}

/**
 * Authored files as links: a label each and an address the site may link
 * to (http, https, mailto, tel or a path in this site), so a file list
 * cannot carry a `javascript:` address.
 *
 * @param {Array<object>} files The authored files, `{label, href, line?}`.
 * @return {Array<{label: string, href: string, line: string}>} The files.
 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-every-nl-design-system-component-must-be-placeable-or-carry-a-reason-req-snw-010
 */
export function authoredFiles(files) {
	return (Array.isArray(files) ? files : [])
		.map((file) => ({
			label: String(file?.label ?? '').trim(),
			link: authoredLink(file?.href),
			line: String(file?.line ?? '').trim(),
		}))
		.filter((file) => file.label !== '' && file.link !== null)
		.map((file) => ({
			label: file.label,
			href: file.link.href,
			line: file.line,
		}))
}

/**
 * The route a chosen record opens: the authored page route with the record's
 * id after it, or '' when the placement names no in-site route.
 *
 * @param {string} route The authored route.
 * @param {string} id The chosen record's id.
 * @return {string} The route, or ''.
 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-a-mijn-omgeving-widget-must-show-only-the-visitors-own-data-req-snw-012
 */
export function recordRouteOf(route, id) {
	const base = String(route || '')
		.trim()
		.replace(/\/+$/, '')
	if (!/^\/[^/]/.test(base) || String(id || '') === '') {
		return ''
	}
	return `${base}/${encodeURIComponent(id)}`
}

/**
 * The visitor's open portal tasks, through the shell's api's task proxy.
 * A refused or failed read answers null, never "nothing to do".
 *
 * @param {object|null} mijn The shell's data.
 * @return {Promise<Array<object>|null>} The tasks, or null.
 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-a-mijn-omgeving-widget-must-show-only-the-visitors-own-data-req-snw-012
 */
export async function portalTaskRows(mijn) {
	const shell = ownShell(mijn)
	if (!shell || typeof shell.api.fetchTasks !== 'function') {
		return null
	}
	let page
	try {
		page = await shell.api.fetchTasks()
	} catch {
		return null
	}
	return Array.isArray(page?.results) && page.failed !== true ? page.results : null
}
