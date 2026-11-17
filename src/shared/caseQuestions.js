// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// caseQuestions.js: the open questions the case app asks on one case, as the
// case page shows them in its banner (woo-dossier-in-my-cases REQ-WDM-001).
// The answer goes through the existing endpoint row forward, so the server
// proves the row is the resident's and signs the subject. Portaliq keeps no
// term state and never says the term runs again.

/** The question was answered: the row offers no second form. */
export const QUESTION_SENT = 'sent'

/** The handler closed the question before the answer arrived. */
export const QUESTION_CLOSED = 'closed'

/** The answer did not arrive; the form stays, with the reason. */
export const QUESTION_FAILED = 'failed'

/**
 * The questions of the case read, each with a usable answer action. A
 * malformed entry is left out rather than offering a form that cannot send.
 *
 * @param {object|null} data The case read (`fetchCitizenCase`).
 * @return {Array<object>} The questions.
 */
export function caseQuestions(data) {
	const questions = data && Array.isArray(data.questions) ? data.questions : []
	return questions.filter(
		(question) =>
			question
			&& typeof question.id === 'string'
			&& question.id !== ''
			&& question.collection
			&& typeof question.collection.register === 'string'
			&& question.action
			&& typeof question.action.id === 'string',
	)
}

/**
 * The fields the answer form asks, with their label and limit.
 *
 * @param {object} action The answer action.
 * @param {(key: string, vars?: object) => string} t The translator.
 * @return {Array<{name: string, label: string, maxLength: number|null, required: boolean}>} The fields.
 */
export function questionFields(action, t) {
	const names = Array.isArray(action?.fields) ? action.fields.filter((name) => typeof name === 'string' && name !== '') : []
	const required = Array.isArray(action?.requiredFields) ? action.requiredFields : []
	const configs = action?.fieldConfigs && typeof action.fieldConfigs === 'object' ? action.fieldConfigs : {}
	return names.map((name) => {
		const config = configs[name] && typeof configs[name] === 'object' ? configs[name] : {}
		return {
			name,
			label: typeof config.label === 'string' && config.label.trim() !== '' ? config.label : t('Your reply'),
			maxLength: Number.isInteger(config.maxLength) && config.maxLength > 0 ? config.maxLength : null,
			required: required.includes(name),
		}
	})
}

/**
 * Whether every required field has an answer.
 *
 * @param {object} action The answer action.
 * @param {object} values The typed values, by field name.
 * @return {boolean} True when the answer may be sent.
 */
export function questionComplete(action, values) {
	const required = Array.isArray(action?.requiredFields) ? action.requiredFields : []
	return required.every((name) => typeof values?.[name] === 'string' && values[name].trim() !== '')
}

/**
 * What a forward answer means for the row: sent on a 2xx, closed when the
 * case app no longer has the question open (404 or 409), else failed.
 *
 * @param {{ok: boolean, status: number}} result The forward's answer.
 * @return {string} One of the QUESTION_* states.
 */
export function questionOutcome(result) {
	if (result && result.ok === true) {
		return QUESTION_SENT
	}
	if (result && (result.status === 404 || result.status === 409)) {
		return QUESTION_CLOSED
	}
	return QUESTION_FAILED
}

/**
 * Send the answer to one question through the row forward. Only the action's
 * own fields travel; the server stamps the row id and the subject.
 *
 * @param {object} api The portal api (`forwardRowAction`).
 * @param {object} question The question.
 * @param {object} values The typed values, by field name.
 * @param {Array<File>} [files] The files the resident chose, for an action that declares `files`.
 * @return {Promise<string>} One of the QUESTION_* states.
 */
export async function answerQuestion(api, question, values, files = []) {
	if (!api || typeof api.forwardRowAction !== 'function') {
		return QUESTION_FAILED
	}
	const answers = {}
	for (const field of Array.isArray(question.action.fields) ? question.action.fields : []) {
		if (typeof values?.[field] === 'string') {
			answers[field] = values[field].trim()
		}
	}
	const field = question.action.files?.field
	const upload = typeof field === 'string' && field !== '' && files.length > 0 ? { field, files } : null
	const result = upload
		? await api.forwardRowAction(question.collection, question.id, question.action.id, answers, '', upload)
		: await api.forwardRowAction(question.collection, question.id, question.action.id, answers)
	return questionOutcome(result)
}
