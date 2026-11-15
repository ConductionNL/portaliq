// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// The messages about one case, as the case page lists them: newest first,
// each with who wrote it and when. The server already holds them to one
// shape and order; this keeps the page right when an answer arrives in
// another order, and never drops a message it was given.
//
// Imports nothing, so tests/case-messages.spec.mjs reads it as node.
//
// @spec openspec/changes/capability-rows-said-and-planned/tasks.md#t07

/**
 * The time of a message, or NaN when it has none.
 *
 * @param {object} message A message.
 * @return {number}
 */
function timeOf(message) {
	return Date.parse(String(message?.receivedAt || ''))
}

/**
 * The messages, newest first; undated ones last, in the order given.
 *
 * @param {Array<object>|unknown} messages The messages.
 * @return {Array<object>}
 * @spec openspec/changes/capability-rows-said-and-planned/tasks.md#t07
 */
export function newestMessagesFirst(messages) {
	const list = Array.isArray(messages)
		? messages.filter((m) => m && typeof m === 'object')
		: []
	return list
		.map((message, index) => ({ message, index, time: timeOf(message) }))
		.sort((a, b) => {
			const aDated = !Number.isNaN(a.time)
			const bDated = !Number.isNaN(b.time)
			if (aDated !== bDated) {
				return aDated ? -1 : 1
			}
			if (aDated && a.time !== b.time) {
				return b.time - a.time
			}
			return a.index - b.index
		})
		.map((entry) => entry.message)
}

/**
 * Who wrote a message, in words: "You wrote", "{name} wrote" or "The
 * organisation wrote".
 *
 * @param {object} message A message.
 * @param {(key: string, vars?: object) => string} tr The translator.
 * @return {string}
 * @spec openspec/changes/capability-rows-said-and-planned/tasks.md#t07
 */
export function writerOf(message, tr) {
	if (message?.fromResident === true) {
		return tr('You wrote')
	}
	const name = String(message?.senderName || '').trim()
	return name
		? tr('{name} wrote', { name })
		: tr('The organisation wrote')
}

/**
 * The cta block that opens the ask action for the open record, or null
 * when the messages block names none.
 *
 * @param {object} block The messages block: `askAction?`, `askLabel?`.
 * @param {(key: string) => string} tr The translator.
 * @return {object|null}
 * @spec openspec/changes/capability-rows-said-and-planned/tasks.md#t07
 */
export function askBlockOf(block, tr) {
	if (!block || typeof block.askAction !== 'string' || !block.askAction) {
		return null
	}
	return {
		type: 'cta',
		action: block.askAction,
		withRecord: true,
		label: block.askLabel || tr('Ask a question about this case'),
	}
}
