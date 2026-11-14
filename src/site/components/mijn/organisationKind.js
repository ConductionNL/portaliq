// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// The words the site names the organisation's kind with: "het waterschap",
// "de gemeente", "de organisatie". The kind is the portal's organisation
// type label (`organisationTypeLabel`, a TOOI concept's Dutch label stored on
// save), never assumed: a portal without one is "de organisatie".
//
// Imports nothing, so the node tests read it as is.
//
// @spec openspec/changes/portal-identity-from-the-admin/specs/portaliq-cms/spec.md#requirement-the-portal-names-its-organisation-type-from-tooi-req-pia-003

/**
 * The Dutch nouns that take "het"; every other kind takes "de".
 */
const HET = ['waterschap', 'ministerie']

/**
 * The organisation's kind with its article, in the page language.
 *
 * Dutch reads the label with its article ("het waterschap"). The label is
 * Dutch, so another language names no kind: "the organisation".
 *
 * @param {string} [label] The portal's organisation type label.
 * @param {string} [locale] The page language.
 * @return {string}
 * @spec openspec/changes/portal-identity-from-the-admin/specs/portaliq-cms/spec.md#requirement-the-portal-names-its-organisation-type-from-tooi-req-pia-003
 */
export function organisationKind(label = '', locale = 'nl') {
	const noun = String(label || '').trim().toLowerCase()
	if (!String(locale || 'nl').toLowerCase().startsWith('nl')) {
		return 'the organisation'
	}
	if (noun === '') {
		return 'de organisatie'
	}
	return `${HET.includes(noun) ? 'het' : 'de'} ${noun}`
}
