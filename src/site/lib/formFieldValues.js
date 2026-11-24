// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The values of the choice fields a `form` widget can carry, and the one check
 * the plain field rules do not make: a checkbox group's minimum
 * (site-nlds-widget-palette design D1 and D4).
 *
 * Kept apart from FormBlock so a node test holds the rules without a DOM.
 *
 * @spec openspec/changes/site-nlds-widget-palette/specs/portal-page-designer/spec.md#requirement-form-fields-must-be-added-inside-a-form-widget-req-snw-003
 */

/**
 * The options chosen so far, as a list, whatever the value held.
 *
 * @param {object} field A radio or checkboxes field.
 * @param {*} value Its current value.
 * @return {Array<string>} The chosen options.
 *
 * @spec openspec/changes/site-nlds-widget-palette/specs/portal-page-designer/spec.md#requirement-form-fields-must-be-added-inside-a-form-widget-req-snw-003
 */
export function chosenList(field, value) {
	if (Array.isArray(value)) {
		return value.filter((option) => typeof option === 'string')
	}
	return typeof value === 'string' && value !== '' ? [value] : []
}

/**
 * The value after the visitor ticks or unticks OPTION.
 *
 * A radio holds one option as a string. Checkboxes hold a list in the order of
 * the declared options, so two visitors who tick the same boxes submit the
 * same value whatever order they clicked in.
 *
 * @param {object} field A radio or checkboxes field.
 * @param {*} value Its current value.
 * @param {string} option The option.
 * @param {boolean} checked Whether it is now ticked.
 * @return {string|Array<string>} The new value.
 *
 * @spec openspec/changes/site-nlds-widget-palette/specs/portal-page-designer/spec.md#requirement-form-fields-must-be-added-inside-a-form-widget-req-snw-003
 */
export function chosenValue(field, value, option, checked) {
	if (field.type === 'radio') {
		return checked ? option : ''
	}
	const chosen = new Set(chosenList(field, value))
	if (checked) {
		chosen.add(option)
	} else {
		chosen.delete(option)
	}
	const declared = Array.isArray(field.options) ? field.options : []
	return declared.filter((candidate) => chosen.has(candidate))
}

/**
 * The message per checkbox group that has fewer ticks than its minimum.
 *
 * A group nobody ticked is the required rule's to judge, not this one's: an
 * optional group may stay empty, and only a started answer must reach the
 * minimum.
 *
 * @param {Array<object>} fields The declared fields.
 * @param {object} values The values per field id.
 * @return {Record<string, string>} The message per field id.
 *
 * @spec openspec/changes/site-nlds-widget-palette/specs/portal-page-designer/spec.md#requirement-form-fields-must-be-added-inside-a-form-widget-req-snw-003
 */
export function minimumErrors(fields, values) {
	const errors = {}
	for (const field of Array.isArray(fields) ? fields : []) {
		if (field?.type !== 'checkboxes' || !Number.isInteger(field.minimum) || field.minimum < 2) {
			continue
		}
		const count = chosenList(field, (values || {})[field.id]).length
		if (count > 0 && count < field.minimum) {
			errors[field.id] = `Kies er minstens ${field.minimum} bij ${field.label}.`
		}
	}
	return errors
}
