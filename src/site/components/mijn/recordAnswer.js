// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * Reading what an app answers about the open record (its steps, its history)
 * the way the Mijn blocks do: a failed read says so and is not "nothing yet",
 * and an answer for a record that is no longer open is dropped.
 *
 * @spec openspec/specs/site-mijn-omgeving/spec.md#requirement-process-steps-must-say-where-a-case-stands-in-order-req-smo-003
 */

/**
 * The open record's id.
 *
 * @param {object|null} row The record.
 * @return {string} Its id, uuid or `@self` id, or ''.
 * @spec openspec/specs/site-mijn-omgeving/spec.md#requirement-process-steps-must-say-where-a-case-stands-in-order-req-smo-003
 */
export function recordIdOf(row) {
	return String(row?.id || row?.uuid || row?.['@self']?.id || '')
}

/**
 * The api's answer for the record, or null when the read fails.
 *
 * @param {object} block The block: `collection`, `record`.
 * @param {{api: object|null, id: string, fetch: string}} read The api, the record's id and the api's read.
 * @return {Promise<object|null>} The answer.
 */
async function answerFor(block, read) {
	try {
		return await read.api?.[read.fetch]?.(
			block.collection,
			read.id,
			block.record?._mandate?.id || '',
		)
	} catch {
		return null
	}
}

/**
 * Read the open record's answer into the block's `answer` and `failed`.
 *
 * @param {object} block The block: `recordId`, `collection`, `record`, `answer`, `failed`.
 * @param {{api: object|null, fetch: string, list: string}} read The api, its read and the list a good answer carries.
 * @return {Promise<void>}
 * @spec openspec/specs/site-mijn-omgeving/spec.md#requirement-process-steps-must-say-where-a-case-stands-in-order-req-smo-003
 */
export async function loadRecordAnswer(block, read) {
	const id = block.recordId
	if (!id || !block.collection) {
		return
	}
	block.answer = null
	block.failed = false
	const answer = await answerFor(block, { api: read.api, id, fetch: read.fetch })
	if (id !== block.recordId) {
		return
	}
	block.failed = !answer || !Array.isArray(answer[read.list])
	block.answer = block.failed ? null : answer
}
