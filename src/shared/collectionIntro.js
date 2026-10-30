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
 * The intros of every `kind: cases` collection in the contributions, once
 * each, in contribution order: the text above "My cases".
 *
 * @param {object|Array|null|undefined} contributions The contributions aggregate, or its list.
 * @return {Array<string>} The intro texts.
 *
 * @spec openspec/changes/archive/2026-10-30-collection-intro/tasks.md#t2
 */
export function casesIntros(contributions) {
	const list = Array.isArray(contributions)
		? contributions
		: contributions?.contributions
	const out = []
	for (const contribution of Array.isArray(list) ? list : []) {
		const collections = Array.isArray(contribution?.collections)
			? contribution.collections
			: []
		for (const collection of collections) {
			const intro = collection?.kind === 'cases' ? introOf(collection) : ''
			if (intro !== '' && !out.includes(intro)) {
				out.push(intro)
			}
		}
	}
	return out
}
