/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The page editor's picker for a public records block
 * (site-member-voting-record-and-confidential-papers, design.md D2). Free of
 * Vue so the node tests drive it directly.
 */

/**
 * The public record lists an author can place, from the anonymous
 * contributions answer (`GET /api/content/contributions`).
 *
 * @param {object} answer The answer's body.
 * @return {Array<{app: string, id: string, label: string}>} The lists.
 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t3
 */
export function recordListsFrom(answer) {
	const lists = []
	for (const contribution of listOf(answer?.contributions)) {
		for (const entry of listOf(contribution?.publicRecords)) {
			const list = recordListOf(entry, contribution)
			if (list !== null) {
				lists.push(list)
			}
		}
	}

	return lists
}

/**
 * @param {unknown} value A value that should be a list.
 * @return {Array} The value, or an empty list when it is not one.
 */
function listOf(value) {
	return Array.isArray(value) ? value : []
}

/**
 * @param {object|null} entry The contribution's public record entry.
 * @param {object|null} contribution The contribution.
 * @return {string} The app the list belongs to: its own, else the contribution's.
 */
function listApp(entry, contribution) {
	return String(entry?.app || contribution?.app || '')
}

/**
 * One public record list of a contribution, when it names its app and id.
 *
 * @param {object|null} entry The contribution's public record entry.
 * @param {object|null} contribution The contribution.
 * @return {{app: string, id: string, label: string}|null} The list, or null.
 */
function recordListOf(entry, contribution) {
	const app = listApp(entry, contribution)
	const id = String(entry?.id || '')
	if (app === '' || id === '') {
		return null
	}
	return { app, id, label: String(entry?.label || id) }
}

/**
 * The props a chosen list sets: both the app and the list, so the two never
 * point at different contributions. An unknown choice clears both.
 *
 * @param {Array<{app: string, id: string}>} lists The lists offered.
 * @param {string} value The option's `app/list`, or ''.
 * @return {{app: (string|undefined), list: (string|undefined)}} The props.
 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t3
 */
export function recordListChoice(lists, value) {
	const chosen = (Array.isArray(lists) ? lists : []).find(
		(recordList) => `${recordList.app}/${recordList.id}` === value,
	)
	return chosen
		? { app: chosen.app, list: chosen.id }
		: { app: undefined, list: undefined }
}
