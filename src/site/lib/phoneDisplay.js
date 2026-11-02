/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A phone number as a Dutch reader writes it. An account keeps its numbers in
 * E.164 (+31612345678); the screen shows "06 12 34 56 78". Only the display
 * changes: what is stored and sent stays E.164.
 *
 * NO IMPORTS: the node specs run it as a plain script.
 */

/** Dutch area codes of two digits after the 0; every other one has three. */
const TWO_DIGIT_AREAS = new Set([
	'10', '13', '15', '20', '23', '24', '26', '30', '33', '35', '36', '38', '40', '43',
	'45', '46', '50', '53', '55', '58', '70', '71', '72', '73', '74', '75', '76', '77',
	'78', '79',
])

/**
 * A stored phone number for the screen: a Dutch E.164 number in national
 * groups (mobile "06 12 34 56 78", landline "010 123 4567" or "0591 123 456");
 * anything else as it is stored.
 *
 * @param {string|null} value The stored number.
 * @return {string} The number to show.
 * @spec openspec/changes/archive/2026-09-30-identity-profile-page/tasks.md#T05
 */
export function phoneDisplay(value) {
	const text = String(value ?? '')
	const match = /^\+31([1-9]\d{8})$/.exec(text)
	if (!match) {
		return text
	}
	const national = match[1]
	if (national.startsWith('6')) {
		const rest = national.slice(1)
		return ['06', rest.slice(0, 2), rest.slice(2, 4), rest.slice(4, 6), rest.slice(6)].join(' ')
	}
	const area = TWO_DIGIT_AREAS.has(national.slice(0, 2)) ? national.slice(0, 2) : national.slice(0, 3)
	const local = national.slice(area.length)
	return `0${area} ${local.slice(0, 3)} ${local.slice(3)}`
}
