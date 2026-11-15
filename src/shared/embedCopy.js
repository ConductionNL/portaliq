// SPDX-License-Identifier: EUPL-1.2
//
// What the embed frame says when it is not showing a form, and how a field is
// labelled (embed-frame-renders-the-form).
//
// Kept out of the JSX on purpose: this is the half that decides WHAT is said,
// and it is the half that must never fall through to nothing. Separating it
// means it can be exercised by `node --test` without a browser or a bundler,
// so the rule "every refusal has a sentence" is checked on every run rather
// than only when somebody boots a seeded instance.

/** What the controller sends when it will not render a form. */
export const EMBED_REFUSALS = {
	form_not_found: 'Dit formulier bestaat niet (meer).',
	origin_not_allowed: 'Dit formulier mag niet op deze website worden getoond.',
	form_not_published: 'Dit formulier is op dit moment niet beschikbaar.',
	identified_intake: 'Voor deze aanvraag moet u eerst inloggen.',
}

/** Said when the controller refuses for a reason nobody has written copy for. */
export const EMBED_REFUSAL_FALLBACK = 'Dit formulier kan hier niet worden getoond.'

/**
 * The refusal sentence for a payload, or null when it carries a form.
 *
 * 🔴 AN UNRECOGNISED REASON STILL PRODUCES A SENTENCE. A refusal nobody has
 * written copy for must not render as an empty frame: that is exactly the
 * failure this change exists to remove, reappearing one level down. A visitor
 * meeting a blank rectangle on a municipality's website cannot tell whether the
 * form is broken, still loading, or simply not for them.
 *
 * @param {object} payload The boot payload from the frame route.
 * @return {string|null} The sentence, or null when there is a form to render.
 */
export function refusalSentence(payload) {
	const reason = payload?.refused
	if (!reason) {
		return null
	}

	return EMBED_REFUSALS[reason] || EMBED_REFUSAL_FALLBACK
}

/**
 * A field's label, falling back to its own name rather than to nothing.
 *
 * An input with no label is one a screen reader announces as nothing at all,
 * so a missing label degrades to the field name instead of to silence.
 *
 * @param {object} field The field.
 * @return {string} The label.
 */
export function labelFor(field) {
	const label = (field?.label || '').trim()
	if (label !== '') {
		return label
	}

	return String(field?.name || '')
}
