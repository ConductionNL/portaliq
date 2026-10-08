// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * The decisions behind the address block (`addressNL`), without Vue, so
 * `node --test` asserts them (tests/address-block.spec.mjs). The block asks
 * for a postcode and house number, finds the street and town, and leaves both
 * editable: the lookup helps, it never decides.
 *
 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t01
 */

import { normaliseFormat } from './formats.js'

/**
 * An empty address block.
 *
 * @return {object} The block.
 */
export function emptyAddress() {
	return { postcode: '', number: '', letter: '', addition: '', street: '', town: '' }
}

/**
 * Whether the postcode and number are enough to ask the register.
 *
 * @param {object} block The address block.
 * @return {boolean} True when both are well formed.
 */
export function canLookUp(block) {
	return (
		normaliseFormat('postcode', block?.postcode ?? '') !== null
		&& /^[1-9]\d{0,4}$/.test(String(block?.number ?? '').trim())
	)
}

/**
 * The block after a lookup answered. A street or town the resident already
 * typed is kept; only empty ones are filled.
 *
 * @param {object} block The address block.
 * @param {{street: string, town: string}|null} found The register's answer.
 * @param {{street: boolean, town: boolean}} touched Whether the resident edited each by hand.
 * @return {object} The new block.
 */
export function withFound(block, found, touched) {
	if (!found) {
		return { ...block }
	}
	return {
		...block,
		street: touched?.street ? block.street : found.street,
		town: touched?.town ? block.town : found.town,
	}
}

/**
 * The block as the review shows it, on one line.
 *
 * @param {object} block The address block.
 * @return {string} "Lindelaan 12A, 1234 AB Zuiddrecht", or ''.
 */
export function addressLine(block) {
	if (!block || typeof block !== 'object') {
		return ''
	}
	const house = [block.number, block.letter, block.addition ? `-${block.addition}` : '']
		.join('')
		.trim()
	const left = [block.street, house].filter(Boolean).join(' ')
	const right = [block.postcode, block.town].filter(Boolean).join(' ')
	return [left, right].filter(Boolean).join(', ')
}

/**
 * What is missing from an address block, in a sentence; '' when it is whole.
 *
 * @param {object} block The address block.
 * @return {string} The message, or ''.
 */
export function addressProblem(block) {
	const whole =
		block
		&& typeof block === 'object'
		&& normaliseFormat('postcode', block.postcode ?? '') !== null
		&& /^[1-9]\d{0,4}$/.test(String(block.number ?? '').trim())
		&& String(block.street ?? '').trim() !== ''
		&& String(block.town ?? '').trim() !== ''
	return whole ? '' : 'Vul postcode, huisnummer, straat en plaats in.'
}
