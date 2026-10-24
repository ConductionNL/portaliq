// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * The Dutch format checks a form field may ask for with `format`, without
 * Vue. It mirrors `lib/Service/Intake/DutchFormats.php` rule for rule; both
 * read `tests/fixtures/dutch-formats.json`, so the screen and the server
 * cannot disagree. The server still checks every submission: this only spares
 * the resident a round trip.
 *
 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t02
 */

/** The RDW side codes 1 to 14 as letter (L) and digit (D) runs. */
const PLATES = [
	'LLDDDD',
	'DDDDLL',
	'DDLLDD',
	'LLDDLL',
	'LLLLDD',
	'DDLLLL',
	'DDLLLD',
	'DLLLDD',
	'LLDDDL',
	'LDDDLL',
	'LLLDDL',
	'LDDLLL',
	'DLLDDD',
	'DDDLLD',
]

/** The formats a field may name. */
export const FORMATS = [
	'bsn',
	'iban',
	'nl-licence-plate',
	'phone-nl',
	'phone-international',
	'postcode',
	'kvk',
	'kvk-branch',
]

/**
 * @param {string} value The value.
 * @return {string|null} The BSN when it passes the elfproef.
 */
function bsn(value) {
	if (!/^\d{9}$/.test(value) || value === '000000000') {
		return null
	}
	let sum = 0
	for (let index = 0; index < 9; index++) {
		sum += (index === 8 ? -1 : 9 - index) * Number(value[index])
	}
	return sum % 11 === 0 ? value : null
}

/**
 * @param {string} value The value.
 * @return {string|null} The IBAN in capitals when ISO 13616 mod 97 holds.
 */
function iban(value) {
	const code = value.replace(/\s+/g, '').toUpperCase()
	if (!/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/.test(code)) {
		return null
	}
	if (code.startsWith('NL') && code.length !== 18) {
		return null
	}
	let remainder = 0
	for (const char of code.slice(4) + code.slice(0, 4)) {
		const number = /[A-Z]/.test(char) ? String(char.charCodeAt(0) - 55) : char
		remainder = Number(String(remainder) + number) % 97
	}
	return remainder === 1 ? code : null
}

/**
 * @param {string} value The value.
 * @return {string|null} The plate in capitals without dashes.
 */
function plate(value) {
	const code = value.replace(/[\s-]+/g, '').toUpperCase()
	if (!/^[A-Z0-9]{6}$/.test(code)) {
		return null
	}
	const index = PLATES.indexOf(code.replace(/[A-Z]/g, 'L').replace(/\d/g, 'D'))
	if (index === -1 || (index >= 6 && /[AEIOUCQ]/.test(code))) {
		return null
	}
	return code
}

/**
 * @param {string} value The value.
 * @param {RegExp} pattern What the cleaned number must match.
 * @return {string|null} The number without spaces, dashes and brackets.
 */
function phone(value, pattern) {
	const number = value.replace(/[\s\-()]+/g, '')
	return pattern.test(number) ? number : null
}

/**
 * @param {string} value The value.
 * @return {string|null} "1234 AB".
 */
function postcode(value) {
	const match = /^([1-9]\d{3})\s?([A-Za-z]{2})$/.exec(value)
	if (!match || ['SA', 'SD', 'SS'].includes(match[2].toUpperCase())) {
		return null
	}
	return `${match[1]} ${match[2].toUpperCase()}`
}

/**
 * The value in its stored form when it fits the format, else null.
 *
 * @param {string} format The format name.
 * @param {string} value The typed value.
 * @return {string|null} The normalised value, or null.
 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t02
 */
export function normaliseFormat(format, value) {
	const text = String(value ?? '').trim()
	switch (format) {
		case 'bsn':
			return bsn(text)
		case 'iban':
			return iban(text)
		case 'nl-licence-plate':
			return plate(text)
		case 'phone-nl':
			return phone(text, /^(0[1-9]\d{8}|(\+|00)31[1-9]\d{8})$/)
		case 'phone-international':
			return phone(text, /^\+[1-9]\d{6,14}$/)
		case 'postcode':
			return postcode(text)
		case 'kvk':
			return /^\d{8}$/.test(text) ? text : null
		case 'kvk-branch':
			return /^\d{12}$/.test(text) ? text : null
		default:
			return null
	}
}

/** The sentence per format, in Dutch. */
export const FORMAT_MESSAGES = Object.freeze({
	bsn: 'Dit burgerservicenummer klopt niet. Controleer de cijfers.',
	iban: 'Dit IBAN klopt niet. Controleer de cijfers.',
	'nl-licence-plate':
		'Dit kenteken klopt niet. U mag het met of zonder streepjes invullen.',
	'phone-nl':
		'Dit is geen Nederlands telefoonnummer. Vul het in als 06 12345678 of +31 6 12345678.',
	'phone-international':
		'Dit is geen internationaal telefoonnummer. Begin met + en de landcode.',
	postcode: 'Deze postcode klopt niet. Vul hem in als 1234 AB.',
	kvk: 'Een KvK-nummer heeft 8 cijfers.',
	'kvk-branch': 'Een vestigingsnummer heeft 12 cijfers.',
})

/**
 * The sentence for a value that does not fit its format, or '' when it fits,
 * the format is not one this file checks, or nothing is typed.
 *
 * @param {string} format The format name.
 * @param {string} value The typed value.
 * @return {string} The message, or ''.
 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t02
 */
export function formatProblem(format, value) {
	if (!FORMATS.includes(format) || String(value ?? '').trim() === '') {
		return ''
	}
	return normaliseFormat(format, value) === null ? FORMAT_MESSAGES[format] : ''
}
