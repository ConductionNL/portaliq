// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * A reply to an inbox message (inbox-reply-with-attachments), without Vue so
 * `node --test` asserts it: which questions the form asks, what it starts
 * with, and how the reply and its files are sent. The server builds the reply
 * from the message the resident owns; the browser sends what the resident
 * wrote and uploads the files into the new reply.
 *
 * @spec openspec/changes/inbox-reply-with-attachments/tasks.md#t05
 */

import { fileFields, submitWithFiles } from '../../../shared/fileFieldSubmit.js'

/**
 * The reply a message can be answered with, or null.
 *
 * @param {object} message An inbox row with its `_source`.
 * @return {object|null} `{action, carried, subjectFrom?}`.
 * @spec openspec/changes/inbox-reply-with-attachments/tasks.md#t05
 */
export function replyOf(message) {
	const reply = message && message._source && message._source.reply
	return reply && reply.action && typeof reply.action.id === 'string'
		? reply
		: null
}

/**
 * The questions the form asks: the action's fields less the carried ones and
 * the file fields, each with its label and whether it is a long text.
 *
 * @param {object} reply The reply declaration.
 * @return {Array<{name: string, label: string, long: boolean, required: boolean}>} The questions.
 * @spec openspec/changes/inbox-reply-with-attachments/tasks.md#t05
 */
export function replyQuestions(reply) {
	const configs = reply.action.fieldConfigs || {}
	const skip = new Set([...(reply.carried || []), ...fileFields(reply.action)])
	return (reply.action.fields || [])
		.filter((name) => !skip.has(name))
		.map((name) => {
			const config = configs[name] || {}
			return {
				name,
				label:
					typeof config.label === 'string' && config.label !== ''
						? config.label
						: name,
				long:
					config.type === 'textarea'
					|| ['content', 'body', 'text', 'message'].includes(name),
				required: config.required === true,
			}
		})
}

/**
 * The file fields the form offers, with the limits the action declares.
 *
 * @param {object} reply The reply declaration.
 * @return {Array<{name: string, label: string, multiple: boolean, accept: string, maxSizeMb: number}>} The file fields.
 * @spec openspec/changes/inbox-reply-with-attachments/tasks.md#t05
 */
export function replyFileFields(reply) {
	const configs = reply.action.fieldConfigs || {}
	return fileFields(reply.action).map((name) => ({
		name,
		label:
			typeof configs[name].label === 'string' && configs[name].label !== ''
				? configs[name].label
				: name,
		multiple: configs[name].multiple === true,
		accept: typeof configs[name].accept === 'string' ? configs[name].accept : '',
		maxSizeMb: Number(configs[name].maxSizeMb) || 0,
	}))
}

/**
 * What the form starts with: the subject, "Re: " and the message's subject.
 *
 * @param {object} message The message.
 * @param {object} reply The reply declaration.
 * @return {Record<string, string>} The starting value per question.
 * @spec openspec/changes/inbox-reply-with-attachments/tasks.md#t05
 */
export function replyStart(message, reply) {
	const values = Object.fromEntries(
		replyQuestions(reply).map((question) => [question.name, '']),
	)
	const from = reply.subjectFrom
	if (
		from
		&& Object.hasOwn(values, 'subject')
		&& typeof message[from] === 'string'
		&& message[from] !== ''
	) {
		values.subject = /^re:\s/i.test(message[from])
			? message[from]
			: `Re: ${message[from]}`
	}
	return values
}

/**
 * Send the reply, then upload its files into it. The reply is not rolled back
 * when a file fails: the text arrived.
 *
 * @param {object} api The portal api (`replyToMessage`, `uploadFieldFile`).
 * @param {object} message The message being answered.
 * @param {object} reply The reply declaration.
 * @param {Record<string, string>} values What the resident wrote.
 * @param {Record<string, File[]>} filesByField The picked files per field.
 * @return {Promise<{ok: boolean, errors: Record<string, string>, failed: string[]}>} `failed` names the files that did not attach.
 * @spec openspec/changes/inbox-reply-with-attachments/tasks.md#t06
 */
export async function sendReply(api, message, reply, values, filesByField) {
	const adapter = {
		createObject: (action, body) => api.replyToMessage(message, body),
		uploadFieldFile: (action, id, field, file) =>
			api.uploadFieldFile(action, id, field, file),
	}
	const result = await submitWithFiles(adapter, reply.action, values, filesByField)
	return {
		ok: result.ok === true,
		errors: result.errors || {},
		failed: (result.failed || []).map((entry) => entry.file.name),
	}
}

/**
 * The words after sending: all well, or the files that were not added.
 *
 * @param {string[]} failed The names of the files that did not attach.
 * @param {{sent: string, partial: string}} words The sentences, `partial` with `{name}`.
 * @return {string} The sentence.
 * @spec openspec/changes/inbox-reply-with-attachments/tasks.md#t06
 */
export function sentMessage(failed, words) {
	return failed.length === 0
		? words.sent
		: words.partial.split('{name}').join(failed.join(', '))
}
