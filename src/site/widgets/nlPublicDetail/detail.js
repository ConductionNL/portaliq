// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// The plain logic of a provider item's public page
// (public-detail-page-for-a-provider-item): the date cards' words, the count
// kept inside its bounds, and the choices kept across a sign-in. No Vue, so
// node tests it.
//
// @spec openspec/changes/public-detail-page-for-a-provider-item/tasks.md

/** Where a visitor's choices wait while they sign in. */
export const CHOICE_PREFIX = 'portaliq.detailChoice:'

/**
 * The words of a date card's places line: the app's own, else a count.
 *
 * @param {object} entry The date: `placesLine?`, `places?`.
 * @param {(key: string, vars?: object) => string} say The widget's words.
 * @return {string} The line, '' when the app says nothing about places.
 * @spec openspec/changes/public-detail-page-for-a-provider-item/tasks.md#task-3
 */
export function placesLine(entry, say) {
	if (typeof entry?.placesLine === 'string' && entry.placesLine !== '') {
		return entry.placesLine
	}
	if (!Number.isInteger(entry?.places)) {
		return ''
	}
	if (entry.places === 0) {
		return say('full')
	}
	return say(entry.places === 1 ? 'placesOne' : 'places', { count: entry.places })
}

/**
 * Whether a date can be chosen: a date with no places left cannot.
 *
 * @param {object} entry The date.
 * @return {boolean}
 * @spec openspec/changes/public-detail-page-for-a-provider-item/tasks.md#task-3
 */
export function isChoosable(entry) {
	return !(Number.isInteger(entry?.places) && entry.places <= 0)
}

/**
 * A count inside 1 to the most the action allows; anything else becomes 1.
 *
 * @param {unknown} value What was typed.
 * @param {number} max The most the action allows.
 * @return {number} The count.
 * @spec openspec/changes/public-detail-page-for-a-provider-item/tasks.md#task-4
 */
export function clampCount(value, max = 20) {
	const count = Math.trunc(Number(value))
	if (!Number.isFinite(count) || count < 1) {
		return 1
	}
	return Math.min(count, Math.max(1, Math.trunc(Number(max)) || 20))
}

/**
 * Keep a visitor's choices while they sign in.
 *
 * @param {Storage|null} storage The session storage, or null where there is none.
 * @param {string} path The page's path.
 * @param {{date: string, count: number}} choice What was chosen.
 * @return {void}
 * @spec openspec/changes/public-detail-page-for-a-provider-item/tasks.md#task-4
 */
export function keepChoice(storage, path, choice) {
	try {
		storage?.setItem(CHOICE_PREFIX + path, JSON.stringify(choice))
	} catch {
		// Private mode: the choice then lasts exactly this page view.
	}
}

/**
 * Take the choices back after the sign-in, once.
 *
 * @param {Storage|null} storage The session storage, or null.
 * @param {string} path The page's path.
 * @param {Array<object>} dates The dates the page offers.
 * @param {number} max The most the action allows.
 * @return {{date: string, count: number}|null} The choices still valid, or null.
 * @spec openspec/changes/public-detail-page-for-a-provider-item/tasks.md#task-4
 */
export function takeChoice(storage, path, dates, max = 20) {
	let raw
	try {
		raw = storage?.getItem(CHOICE_PREFIX + path) ?? null
		storage?.removeItem(CHOICE_PREFIX + path)
	} catch {
		return null
	}
	if (!raw) {
		return null
	}
	try {
		const choice = JSON.parse(raw)
		const date = (Array.isArray(dates) ? dates : []).find(
			(entry) => entry.id === choice?.date && isChoosable(entry),
		)
		return {
			date: date ? date.id : '',
			count: clampCount(choice?.count, max),
		}
	} catch {
		return null
	}
}
