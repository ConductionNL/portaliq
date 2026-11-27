// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The rules of a searchable `select` field (site-nlds-widget-palette design D1
 * row 82, Select Combobox): which options a typed text shows, where the arrow
 * keys move, which option a typed text names, and the one check a plain select
 * never needs: an answer that is not one of the options.
 *
 * Kept apart from the component so a node test holds the rules without a DOM.
 *
 * @spec openspec/changes/site-nlds-widget-palette/specs/portal-page-designer/spec.md#requirement-form-fields-must-be-added-inside-a-form-widget-req-snw-003
 */

/**
 * Text folded for comparing: trimmed, lower case, accents removed, so
 * "Zuid-Holland" is found by "zuid" and "Bevrijdingsdag" by "BEVRIJDING".
 *
 * @param {*} text The text.
 * @return {string} The folded text.
 */
function fold(text) {
	return String(text ?? '')
		.normalize('NFD')
		.replace(/[̀-ͯ]/g, '')
		.trim()
		.toLowerCase()
}

/**
 * The options whose words contain the typed text, in their declared order;
 * every option when nothing is typed.
 *
 * @param {Array<string>} options The declared options.
 * @param {function(string): string} labelOf The words a visitor sees for one option.
 * @param {string} query What the visitor typed.
 * @return {Array<string>} The options to show.
 *
 * @spec openspec/changes/site-nlds-widget-palette/specs/portal-page-designer/spec.md#requirement-form-fields-must-be-added-inside-a-form-widget-req-snw-003
 */
export function filterOptions(options, labelOf, query) {
	const list = Array.isArray(options) ? options.filter((option) => typeof option === 'string') : []
	const wanted = fold(query)
	if (wanted === '') {
		return list
	}
	return list.filter((option) => fold(labelOf(option)).includes(wanted))
}

/**
 * The active option's index after an arrow key: down from nothing is the
 * first, up from nothing the last, and both wrap around.
 *
 * @param {number} current The active index, or -1 for none.
 * @param {number} count How many options are shown.
 * @param {number} step 1 for down, -1 for up.
 * @return {number} The new index, or -1 when nothing is shown.
 *
 * @spec openspec/changes/site-nlds-widget-palette/specs/portal-page-designer/spec.md#requirement-form-fields-must-be-added-inside-a-form-widget-req-snw-003
 */
export function moveActive(current, count, step) {
	if (count <= 0) {
		return -1
	}
	if (current < 0 || current >= count) {
		return step > 0 ? 0 : count - 1
	}
	return (current + step + count) % count
}

/**
 * The option a typed text names exactly (ignoring case and accents), or null.
 *
 * @param {Array<string>} options The declared options.
 * @param {function(string): string} labelOf The words a visitor sees for one option.
 * @param {string} text What the visitor typed.
 * @return {string|null} The option.
 *
 * @spec openspec/changes/site-nlds-widget-palette/specs/portal-page-designer/spec.md#requirement-form-fields-must-be-added-inside-a-form-widget-req-snw-003
 */
export function optionNamed(options, labelOf, text) {
	const wanted = fold(text)
	if (wanted === '') {
		return null
	}
	const list = Array.isArray(options) ? options : []
	return list.find((option) => fold(labelOf(option)) === wanted || fold(option) === wanted) ?? null
}

/**
 * The message per select field whose answer is not one of its options.
 *
 * A searchable select lets the visitor type, so a typed text that names no
 * option reaches the values as it was typed; it must not be sent as if it
 * were a choice. An empty answer is the required rule's to judge.
 *
 * @param {Array<object>} fields The declared fields.
 * @param {object} values The values per field id.
 * @return {Record<string, string>} The message per field id.
 *
 * @spec openspec/changes/site-nlds-widget-palette/specs/portal-page-designer/spec.md#requirement-form-fields-must-be-added-inside-a-form-widget-req-snw-003
 */
export function choiceErrors(fields, values) {
	const errors = {}
	for (const field of Array.isArray(fields) ? fields : []) {
		if (field?.type !== 'select') {
			continue
		}
		const answer = (values || {})[field.id]
		if (typeof answer !== 'string' || answer === '') {
			continue
		}
		const options = Array.isArray(field.options) ? field.options : []
		if (!options.includes(answer)) {
			errors[field.id] = `Kies bij ${field.label} een keuze uit de lijst.`
		}
	}
	return errors
}
