// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// A text block filled from the open record (site-mijn-omgeving-components
// REQ-SMO-027), and a cta label with `{title}` (REQ-SMO-024). Values go in as
// plain text: the result is rendered as text, never as markdown or HTML. No
// Vue, so tests/mijn-wave6.spec.mjs runs it as node.
//
// @spec openspec/changes/site-mijn-omgeving-components/specs/portal-contribution-contract/spec.md#requirement-a-text-block-on-a-record-page-may-be-filled-from-the-record-req-smo-027

const PLACEHOLDER = /\{([A-Za-z_][A-Za-z0-9_]*)\}/g

/**
 * A value as a resident reads it: a date in words, else its text.
 *
 * @param {unknown} value The field's value.
 * @param {string} locale The page language.
 * @return {string} The words, or '' for no value.
 */
function words(value, locale) {
	if (
		value === undefined
		|| value === null
		|| value === ''
		|| typeof value === 'object'
	) {
		return ''
	}
	const text = String(value)
	if (/^\d{4}-\d{2}-\d{2}(T|$)/.test(text) && !Number.isNaN(Date.parse(text))) {
		return new Date(text).toLocaleDateString(
			String(locale || 'nl').startsWith('en') ? 'en-GB' : 'nl-NL',
			{ day: 'numeric', month: 'long', year: 'numeric' },
		)
	}
	return text
}

/**
 * The sentences of a template, each filled from the record. A sentence
 * whose placeholder has no value is left out, or replaced by that field's
 * `whenEmpty` text; a sentence naming a field the collection does not
 * project is left out.
 *
 * @param {string} template The template.
 * @param {object|null} record The open record.
 * @param {object} [options] The options.
 * @param {Record<string, string>} [options.whenEmpty] Words per empty field.
 * @param {Array<string>|null} [options.fields] The projected fields; null for any.
 * @param {string} [options.locale] The page language.
 * @return {Array<string>} The sentences to show.
 * @spec openspec/changes/site-mijn-omgeving-components/specs/portal-contribution-contract/spec.md#requirement-a-text-block-on-a-record-page-may-be-filled-from-the-record-req-smo-027
 */
export function fillTemplate(
	template,
	record,
	{ whenEmpty = {}, fields = null, locale = 'nl' } = {},
) {
	return String(template || '')
		.split(/(?<=[.!?])\s+/)
		.map((sentence) => sentence.trim())
		.filter(Boolean)
		.map((sentence) => filledSentence(sentence, { record, whenEmpty, fields, locale }))
		.filter((sentence) => sentence !== null)
}

/**
 * One sentence filled from the record; the field's `whenEmpty` text when a
 * placeholder has no value; null when it is left out.
 *
 * @param {string} sentence The sentence.
 * @param {{record: object|null, whenEmpty: Record<string, string>, fields: Array<string>|null, locale: string}} context The record, the words per empty field, the projected fields and the page language.
 * @return {string|null} The sentence to show, or null.
 */
function filledSentence(sentence, { record, whenEmpty, fields, locale }) {
	const names = [...sentence.matchAll(PLACEHOLDER)].map((match) => match[1])
	if (Array.isArray(fields) && names.some((name) => !fields.includes(name))) {
		return null
	}
	const empty = names.find((name) => words(record?.[name], locale) === '')
	if (empty !== undefined) {
		return typeof whenEmpty?.[empty] === 'string' && whenEmpty[empty] !== ''
			? whenEmpty[empty]
			: null
	}
	return sentence.replace(PLACEHOLDER, (_, name) => words(record?.[name], locale))
}

/**
 * A cta label with `{title}` filled with the open record's title, as text;
 * without a record the placeholder and the space after it go.
 *
 * @param {string} label The label.
 * @param {string} title The open record's title, or ''.
 * @return {string}
 * @spec openspec/changes/site-mijn-omgeving-components/specs/portal-contribution-contract/spec.md#requirement-a-cta-block-may-open-a-page-or-a-site-route-for-the-open-record-with-the-record-in-its-label-req-smo-024
 */
export function ctaLabel(label, title) {
	const text = String(label || '')
	if (title) {
		return text.split('{title}').join(title)
	}
	return text.replace(/\{title\}\s*/g, '').trim()
}

/**
 * A cta label filled from the open record: `{title}` with its title, any
 * other `{field}` with that field's value, as text ("Bericht aan {teacherName}"
 * reads "Bericht aan Meester Daan"). When a named field is empty the block's
 * `labelWhenEmpty` is shown instead, if it declares one.
 *
 * @param {{label?: string, labelWhenEmpty?: string}} block The cta block.
 * @param {{title: string, record: object|null}} open The open record's title and row.
 * @return {string}
 * @spec openspec/changes/guardian-pages-follow-the-wilgenboom-boards/specs/portal-contribution-contract/spec.md#requirement-a-cta-label-may-name-any-field-of-the-open-record
 */
export function recordLabel(block, open) {
	const label = ctaLabel(block?.label, open.title)
	const names = [...label.matchAll(PLACEHOLDER)].map((match) => match[1])
	const empty = names.some((name) => words(open.record?.[name], 'nl') === '')
	if (empty && block?.labelWhenEmpty) {
		return block.labelWhenEmpty
	}
	return label.replace(PLACEHOLDER, (_, name) => words(open.record?.[name], 'nl')).replace(/\s+/g, ' ').trim()
}
