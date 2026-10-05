// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// One sentence from the resident's own answers (action-summary-sentence):
// "Sami is vandaag de hele dag ziek." Each `{field}` becomes the phrase the
// action gives that answer, else the option's own label, else the answer as
// typed. Until every placeholder has an answer there is no sentence at all,
// so the resident never reads half a statement. Text only: the caller renders
// it as text, never as markup.
//
// Imports nothing, so node tests it.
//
// @spec openspec/changes/action-summary-sentence/specs/portal-contribution-contract/spec.md#requirement-an-action-may-sum-up-the-answers-in-one-sentence

/**
 * The words for one answer.
 *
 * @param {string} field The field.
 * @param {unknown} answer The answer.
 * @param {object} phrases The action's phrases.
 * @param {Array<{value: unknown, label: string}>} options The field's options.
 * @return {string} The words, or '' when there is no answer.
 */
function wordsFor(field, answer, phrases, options) {
	if (Array.isArray(answer)) {
		return answer
			.map((one) => wordsFor(field, one, phrases, options))
			.filter(Boolean)
			.join(', ')
	}
	if (answer === null || answer === undefined || typeof answer === 'object') {
		return ''
	}
	const text = String(answer).trim()
	if (text === '') {
		return ''
	}
	const phrase = phrases?.[field]?.[text]
	if (typeof phrase === 'string' && phrase.trim() !== '') {
		return phrase.trim()
	}
	const option = (Array.isArray(options) ? options : []).find(
		(candidate) => String(candidate?.value) === text,
	)
	return option && typeof option.label === 'string' && option.label.trim() !== ''
		? option.label.trim()
		: text
}

/**
 * The summary sentence, or '' while an answer it names is missing.
 *
 * @param {{template: string, phrases?: object}|null} summary The action's summary.
 * @param {Record<string, unknown>} values The answers so far.
 * @param {Record<string, Array<object>>} [options] The options per field.
 * @return {string} The sentence.
 */
export function summarySentence(summary, values, options = {}) {
	const template = typeof summary?.template === 'string' ? summary.template : ''
	if (template.trim() === '') {
		return ''
	}
	let complete = true
	const sentence = template.replace(
		/\{([A-Za-z][A-Za-z0-9_]*)\}/g,
		(all, field) => {
			const words = wordsFor(
				field,
				values?.[field],
				summary.phrases,
				options?.[field],
			)
			if (words === '') {
				complete = false
			}
			return words
		},
	)
	if (!complete) {
		return ''
	}
	const text = sentence.replace(/\s+/g, ' ').trim()
	return text.charAt(0).toUpperCase() + text.slice(1)
}
