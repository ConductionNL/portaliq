// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// The plain logic of the help dialog and the page help text
// (help-texts-and-form-help): which details a form shows, the mailto it
// offers, and which part of Mijn omgeving a page belongs to. No Vue, so node
// tests it.
//
// @spec openspec/changes/help-texts-and-form-help/specs/site-help-texts/spec.md

const KEYS = ['intro', 'image', 'phone', 'phoneNote', 'hours', 'desk', 'email']

/**
 * A form's help: the portal's details, with each key the form sets itself
 * taking its place. An empty key on the form does not hide the portal's.
 *
 * @param {object|null} portalHelp The portal's `help`.
 * @param {object|null} formHelp The form's `help`.
 * @return {object} The merged details, only keys that hold text.
 * @spec openspec/changes/help-texts-and-form-help/specs/site-help-texts/spec.md#requirement-a-form-offers-help-without-losing-the-answers-req-htf-001
 */
export function mergeHelp(portalHelp, formHelp) {
	const out = {}
	for (const source of [portalHelp, formHelp]) {
		if (!source || typeof source !== 'object') {
			continue
		}
		for (const key of KEYS) {
			const value = typeof source[key] === 'string' ? source[key].trim() : ''
			if (value !== '') {
				out[key] = value
			}
		}
	}
	return out
}

/**
 * Whether there is anything to show: "Hulp nodig?" appears only then.
 *
 * @param {object} help The merged details.
 * @return {boolean}
 * @spec openspec/changes/help-texts-and-form-help/specs/site-help-texts/spec.md#requirement-a-form-offers-help-without-losing-the-answers-req-htf-001
 */
export function hasHelp(help) {
	return KEYS.some((key) => key !== 'image' && Boolean(help?.[key]))
}

/**
 * The note under the phone number, with the form named where it asks.
 *
 * @param {string} note The `phoneNote`.
 * @param {string} title The form's title.
 * @return {string} The note.
 * @spec openspec/changes/help-texts-and-form-help/specs/site-help-texts/spec.md#requirement-a-form-offers-help-without-losing-the-answers-req-htf-001
 */
export function phoneNoteFor(note, title) {
	return String(note || '').replaceAll('{formulier}', String(title || '').trim())
}

/**
 * The "Vraag per e-mail" link: the details' address with the form's title as
 * the subject. '' without a usable address.
 *
 * @param {string} email The address.
 * @param {string} title The form's title.
 * @return {string} A `mailto:` link, or ''.
 * @spec openspec/changes/help-texts-and-form-help/specs/site-help-texts/spec.md#requirement-a-form-offers-help-without-losing-the-answers-req-htf-001
 */
export function mailtoFor(email, title) {
	const address = String(email || '').trim()
	if (!/^[^\s@<>,;]+@[^\s@<>,;]+\.[^\s@<>,;]+$/.test(address)) {
		return ''
	}
	const subject = String(title || '').trim()
	return `mailto:${address}${subject ? `?subject=${encodeURIComponent(subject)}` : ''}`
}

/**
 * The part of Mijn omgeving a menu entry belongs to, for its help text.
 *
 * @param {object|null} entry The navigation entry.
 * @param {boolean} isHome Whether this is the area's home.
 * @return {string} `overview`, `cases`, `tasks`, `messages`, `contacts`, or ''.
 * @spec openspec/changes/help-texts-and-form-help/specs/site-help-texts/spec.md#requirement-a-page-and-each-part-of-mijn-omgeving-can-carry-a-help-text-req-htf-002
 */
export function sectionOf(entry, isHome = false) {
	if (isHome) {
		return 'overview'
	}
	const special = entry?.special
	if (special === 'tasks') {
		return 'tasks'
	}
	if (special === 'inbox' || special === 'messages') {
		return 'messages'
	}
	if (special === 'cases' || special === 'myCases') {
		return 'cases'
	}
	if (special === 'contacts') {
		return 'contacts'
	}
	return ''
}
