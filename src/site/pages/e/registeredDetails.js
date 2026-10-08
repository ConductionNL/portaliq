// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// "My details" on the site (identity-registered-details, ported from
// the React portal's RegisteredDetailsPage.jsx). Imports nothing, so the
// node specs run it as a plain script.

/**
 * The sentence for an answer that holds no record, as an English source key.
 *
 * @param {string} reason The reason the server gave.
 * @return {string} The sentence key.
 *
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-resident-must-see-their-registered-details-req-srp-038
 */
export function reasonText(reason) {
	if (reason === 'no_registration_identifier') {
		return 'The portal cannot show registered details for this way of signing in.'
	}
	if (reason === 'not_found') {
		return 'No registered details were found for you.'
	}
	if (reason === 'no_account') {
		return 'There is no portal account for this sign-in.'
	}
	return 'Your registered details cannot be shown right now.'
}

/**
 * The two address lines of a BRP address: street and number, postcode and city.
 *
 * @param {object} address The mapped address.
 * @return {string[]} The two lines, each possibly ''.
 *
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-resident-must-see-their-registered-details-req-srp-038
 */
export function addressLines(address) {
	const a = address || {}
	return [
		[a.street, a.number].filter(Boolean).join(' '),
		[a.postcode, a.city].filter(Boolean).join(' '),
	]
}
