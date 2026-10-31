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
	for (const contribution of Array.isArray(answer?.contributions)
		? answer.contributions
		: []) {
		for (const entry of Array.isArray(contribution?.publicRecords)
			? contribution.publicRecords
			: []) {
			const app = String(entry?.app || contribution?.app || '')
			const id = String(entry?.id || '')
			if (app !== '' && id !== '') {
				lists.push({ app, id, label: String(entry?.label || id) })
			}
		}
	}

	return lists
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
