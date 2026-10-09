// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * The choice of family members, without Vue.
 *
 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t04
 */

/**
 * The chosen references after one person is ticked or unticked.
 *
 * @param {string[]} chosen The references chosen so far.
 * @param {string} ref The reference toggled.
 * @return {string[]} The new list, without duplicates.
 */
export function toggleRef(chosen, ref) {
	const list = Array.isArray(chosen) ? chosen : []
	return list.includes(ref)
		? list.filter((entry) => entry !== ref)
		: [...list, ref]
}

/**
 * The chosen people as the review shows them.
 *
 * @param {string[]} chosen The chosen references.
 * @param {Array<{ref: string, name: string}>} people The listed people.
 * @return {string} The names, comma separated.
 */
export function chosenNames(chosen, people) {
	return (Array.isArray(chosen) ? chosen : [])
		.map((ref) => (people || []).find((person) => person.ref === ref)?.name)
		.filter(Boolean)
		.join(', ')
}
