// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// What the embed frame says, in Dutch and English (site-reaches-portal-parity
// REQ-SRP-047). The key is the English source string; the Dutch texts are the
// React frame's own (src/portal/components/EmbeddedForm.jsx and
// src/shared/embedCopy.js). tests/embed-frame.spec.mjs checks the Dutch
// refusals stay word for word what embedCopy.js says.
//
// Its own small bundle, not the site's translator: the frame is its own entry
// (portaliq-embed) and must not pull the site or the shared portal bundles in.

import { EMBED_REFUSALS } from '../shared/embedCopy.js'

/** The English key for each refusal the controller can return. */
export const REFUSAL_KEYS = {
	form_not_found: 'This form does not exist (any more).',
	origin_not_allowed: 'This form may not be shown on this website.',
	form_not_published: 'This form is not available at the moment.',
	identified_intake: 'For this application you have to sign in first.',
}

/** Said for a refusal nobody has written copy for. */
export const REFUSAL_FALLBACK_KEY = 'This form cannot be shown here.'

const STRINGS = {
	nl: {
		[REFUSAL_KEYS.form_not_found]: EMBED_REFUSALS.form_not_found,
		[REFUSAL_KEYS.origin_not_allowed]: EMBED_REFUSALS.origin_not_allowed,
		[REFUSAL_KEYS.form_not_published]: EMBED_REFUSALS.form_not_published,
		[REFUSAL_KEYS.identified_intake]: EMBED_REFUSALS.identified_intake,
		[REFUSAL_FALLBACK_KEY]: 'Dit formulier kan hier niet worden getoond.',
		'Continue on our own portal': 'Ga verder op ons eigen portaal',
		'Thank you, we have received your application.':
			'Bedankt, wij hebben uw aanvraag ontvangen.',
		'Your reference: {reference}': 'Uw kenmerk: {reference}',
		'Your application could not be sent.':
			'Uw aanvraag kon niet worden verstuurd.',
		'Your application could not be sent. Please try again later.':
			'Uw aanvraag kon niet worden verstuurd. Probeer het later opnieuw.',
		'Sending…': 'Bezig met versturen…',
		Send: 'Versturen',
	},
	en: {
		[REFUSAL_KEYS.form_not_found]: REFUSAL_KEYS.form_not_found,
		[REFUSAL_KEYS.origin_not_allowed]: REFUSAL_KEYS.origin_not_allowed,
		[REFUSAL_KEYS.form_not_published]: REFUSAL_KEYS.form_not_published,
		[REFUSAL_KEYS.identified_intake]: REFUSAL_KEYS.identified_intake,
		[REFUSAL_FALLBACK_KEY]: REFUSAL_FALLBACK_KEY,
		'Continue on our own portal': 'Continue on our own portal',
		'Thank you, we have received your application.':
			'Thank you, we have received your application.',
		'Your reference: {reference}': 'Your reference: {reference}',
		'Your application could not be sent.': 'Your application could not be sent.',
		'Your application could not be sent. Please try again later.':
			'Your application could not be sent. Please try again later.',
		'Sending…': 'Sending…',
		Send: 'Send',
	},
}

export default STRINGS

/**
 * A translator for the frame: Dutch unless the page says English.
 *
 * @param {string} locale The page language, e.g. `nl` or `en-GB`.
 * @return {(key: string, vars?: object) => string} The translator.
 *
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-the-embed-frame-must-render-its-form-from-a-small-entry-req-srp-047
 */
export function createEmbedTranslator(locale) {
	const lang = String(locale || 'nl')
		.slice(0, 2)
		.toLowerCase()
	const bundle = STRINGS[lang] || STRINGS.nl

	return (key, vars = {}) =>
		String(bundle[key] ?? key).replace(/\{(\w+)\}/g, (match, name) =>
			Object.hasOwn(vars, name) ? String(vars[name]) : match,
		)
}

/**
 * The key of the refusal sentence for a payload, or null when it carries a form.
 *
 * An unrecognised reason still gets a sentence (the fallback), for the reason
 * embedCopy.js gives: a refusal must never render as an empty frame.
 *
 * @param {object} payload The boot payload from the frame route.
 * @return {string|null} The key, or null when there is a form to render.
 *
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-the-embed-frame-must-render-its-form-from-a-small-entry-req-srp-047
 */
export function refusalKey(payload) {
	const reason = payload?.refused
	if (!reason) {
		return null
	}

	return Object.hasOwn(REFUSAL_KEYS, reason)
		? REFUSAL_KEYS[reason]
		: REFUSAL_FALLBACK_KEY
}
