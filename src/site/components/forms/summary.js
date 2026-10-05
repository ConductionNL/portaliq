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
// A date answer (`yyyy-mm-dd`, as the date choices and the date group send
// it) reads as "vandaag", "morgen" or "gisteren", else as "maandag 12
// oktober", with the year only when it is not this year. A phrase the action
// gives for that exact answer still wins.
//
// Imports nothing, so node tests it.
//
// @spec openspec/changes/action-summary-sentence/specs/portal-contribution-contract/spec.md#requirement-an-action-may-sum-up-the-answers-in-one-sentence

/** A date-only answer. */
const DAY = /^(\d{4})-(\d{2})-(\d{2})$/

/**
 * A date answer in words, or '' when the answer is no date.
 *
 * @param {string} text The answer.
 * @param {{locale?: string, now?: Date}} context The page language and today.
 * @return {string} The words.
 */
export function dayWords(text, { locale = 'nl', now = new Date() } = {}) {
	const match = DAY.exec(text)
	if (!match) {
		return ''
	}
	const day = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]))
	if (Number.isNaN(day.getTime())) {
		return ''
	}
	const english = String(locale).toLowerCase().startsWith('en')
	const today = new Date(now.getFullYear(), now.getMonth(), now.getDate())
	const offset = Math.round((day - today) / 86400000)
	const relative = english
		? { 0: 'today', 1: 'tomorrow', '-1': 'yesterday' }
		: { 0: 'vandaag', 1: 'morgen', '-1': 'gisteren' }
	if (Object.hasOwn(relative, String(offset))) {
		return relative[String(offset)]
	}
	const options = { weekday: 'long', day: 'numeric', month: 'long' }
	if (day.getFullYear() !== today.getFullYear()) {
		options.year = 'numeric'
	}
	return new Intl.DateTimeFormat(english ? 'en-GB' : 'nl-NL', options).format(day)
}

/**
 * The words for one answer.
 *
 * @param {string} field The field.
 * @param {unknown} answer The answer.
 * @param {object} phrases The action's phrases.
 * @param {Array<{value: unknown, label: string}>} options The field's options.
 * @param {{locale?: string, now?: Date}} context The page language and today.
 * @return {string} The words, or '' when there is no answer.
 */
function wordsFor(field, answer, phrases, options, context) {
	if (Array.isArray(answer)) {
		return answer
			.map((one) => wordsFor(field, one, phrases, options, context))
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
	if (option && typeof option.label === 'string' && option.label.trim() !== '') {
		return option.label.trim()
	}
	return dayWords(text, context) || text
}

/**
 * The summary sentence, or '' while an answer it names is missing.
 *
 * @param {{template: string, phrases?: object}|null} summary The action's summary.
 * @param {Record<string, unknown>} values The answers so far.
 * @param {Record<string, Array<object>>} [options] The options per field.
 * @param {{locale?: string, now?: Date}} [context] The page language and today.
 * @return {string} The sentence.
 */
export function summarySentence(summary, values, options = {}, context = {}) {
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
				context,
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
