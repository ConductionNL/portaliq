/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A contributed collection's `intro` (collection-intro): plain text shown
 * above the collection's list. The renderers put it in a text node, never in
 * `v-html`, so markup in it shows as typed.
 *
 * Pure functions, no imports, so `tests/collection-intro.spec.mjs` runs them
 * as a plain node script.
 *
 * @spec openspec/changes/archive/2026-10-30-collection-intro/tasks.md#t2
 */

/**
 * A collection's intro, trimmed, or '' when it has none.
 *
 * @param {object|null|undefined} collection The contributed collection.
 * @return {string} The intro text.
 *
 * @spec openspec/changes/archive/2026-10-30-collection-intro/tasks.md#t2
 */
export function introOf(collection) {
	const intro = collection?.intro
	return typeof intro === 'string' ? intro.trim() : ''
}

/**
 * The contributions as a list, from the aggregate or the list itself.
 *
 * @param {object|Array|null|undefined} contributions The contributions aggregate, or its list.
 * @return {Array<object>} The contributions.
 */
function contributionList(contributions) {
	const list = Array.isArray(contributions)
		? contributions
		: contributions?.contributions
	return Array.isArray(list) ? list : []
}

/**
 * The intro of a `kind: cases` collection, or ''.
 *
 * @param {object|null} collection The collection.
 * @return {string} The intro.
 */
function casesIntroOf(collection) {
	return collection?.kind === 'cases' ? introOf(collection) : ''
}

/**
 * The intros of every `kind: cases` collection in the contributions, once
 * each, in contribution order: the text above "My cases".
 *
 * @param {object|Array|null|undefined} contributions The contributions aggregate, or its list.
 * @return {Array<string>} The intro texts.
 *
 * @spec openspec/changes/archive/2026-10-30-collection-intro/tasks.md#t2
 */
export function casesIntros(contributions) {
	const intros = contributionList(contributions)
		.flatMap((contribution) =>
			Array.isArray(contribution?.collections) ? contribution.collections : [],
		)
		.map(casesIntroOf)
		.filter((intro) => intro !== '')
	return [...new Set(intros)]
}
