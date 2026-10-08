// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// Splits a translated sentence around a value inside it, so the value can be
// wrapped in NoTranslate and the words around it can not. Imports nothing, so
// node tests it.
//
// @spec openspec/changes/personal-data-left-untranslated/specs/portaliq-cms/spec.md#requirement-browser-translation-leaves-names-and-personal-data-alone-req-pdu-001

/**
 * The text before and after the first occurrence of a value.
 *
 * @param {string} text The sentence.
 * @param {string} value The value inside it.
 * @return {{before: string, value: string, after: string}|null} The parts, or null when the value is not in the text.
 */
export function markAround(text, value) {
	const sentence = String(text ?? '')
	const needle = String(value ?? '')
	const at = needle === '' ? -1 : sentence.indexOf(needle)
	if (at < 0) {
		return null
	}

	return {
		before: sentence.slice(0, at),
		value: needle,
		after: sentence.slice(at + needle.length),
	}
}
