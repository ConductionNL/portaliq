/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The "Which form?" row action on the Form bindings page
 * (portal-intake-form-as-an-object T03).
 *
 * A form binding reads the same on the admin page whether the form it points
 * at is published, a draft, meant for another audience, or gone. This action
 * asks `FormBindingAdminController::preview`, which runs the resolution the
 * citizen's page runs, and says which form the binding opens today. When it
 * opens none, it says so as a warning and says why, and it never names a form.
 *
 * WHY THIS MODULE IMPORTS ONLY ITS REQUEST STEP. The POST, the URL generator,
 * the toasts and the translator are handed in by `src/customComponents.js`,
 * so `tests/form-binding-preview.spec.mjs` runs it as a plain node script.
 *
 * @spec openspec/specs/portal-intake-form/spec.md#requirement-a-portal-page-binds-to-a-published-form-not-to-a-field-list-req-pifo-001
 */

import { postBindingOrSay } from './formBindingRequest.js'

/**
 * @param {(text: string, vars?: object) => string} translate Translator.
 * @return {string} The sentence for an external entry without an address.
 */
function externalWithoutAddress(translate) {
	return translate(
		'This entry sends people to another website, but has no address. Nobody can start it.',
	)
}

/**
 * @param {(text: string, vars?: object) => string} translate Translator.
 * @return {string} The sentence for a case type this portal does not show.
 */
function hiddenCaseType(translate) {
	return translate(
		"This entry opens no form: this portal does not show its case type. Show it again under Case types on the portal's page.",
	)
}

/**
 * @param {(text: string, vars?: object) => string} translate Translator.
 * @return {string} The sentence for a condition the portal cannot check.
 */
function unsupportedCondition(translate) {
	return translate(
		'This form uses a condition the portal cannot check. Change it to a condition on another answer.',
	)
}

/**
 * @param {(text: string, vars?: object) => string} translate Translator.
 * @param {object} answer The preview.
 * @return {string} The sentence for a named form that is not published.
 */
function namedFormNotPublished(translate, answer) {
	return translate(
		'This entry opens no form today. It asks for "{form}", and no form of that name is published to its audience.',
		{ form: answer?.askedFor || '' },
	)
}

/** The sentence for each reason a binding opens no form. */
const NO_FORM_SENTENCES = new Map([
	['external_without_address', externalWithoutAddress],
	['hidden_case_type', hiddenCaseType],
	['unsupported_condition', unsupportedCondition],
	['named_form_not_published', namedFormNotPublished],
])

/**
 * The sentence for a binding that opens no form, by reason.
 *
 * @param {object} answer The preview.
 * @param {(text: string, vars?: object) => string} translate Translator.
 * @return {string}
 * @spec openspec/specs/portal-intake-form/spec.md#requirement-a-portal-page-binds-to-a-published-form-not-to-a-field-list-req-pifo-001
 */
export function noFormSentence(answer, translate) {
	const sentence = NO_FORM_SENTENCES.get(answer?.reason)
	if (sentence) {
		return sentence(translate, answer)
	}
	return translate(
		'This entry opens no form today. No published form matches its case type and audience.',
	)
}

/**
 * The findings that stop a resolved form being published, as one line.
 *
 * @param {Array<object>} findings The findings.
 * @return {string} Their messages, or codes, joined.
 */
function findingsLine(findings) {
	return findings
		.map((finding) => String(finding?.message || finding?.code || ''))
		.filter((text) => text !== '')
		.join(' ')
}

/**
 * What a resolved binding says: the form it opens today, or what stops it
 * being published (submit-creates-the-case-directly REQ-PIFO-007).
 *
 * @param {object} answer The preview.
 * @param {(text: string, vars?: object) => string} translate Translator.
 * @return {{warn: boolean, text: string}} The announcement.
 */
function resolvedAnnouncement(answer, translate) {
	const findings = Array.isArray(answer.findings) ? answer.findings : []
	const form = answer.formName || ''
	if (findings.length > 0) {
		return {
			warn: true,
			text: translate(
				'This entry opens "{form}", but it cannot be published yet: {findings}',
				{ form, findings: findingsLine(findings) },
			),
		}
	}
	return { warn: false, text: translate('This entry opens "{form}" today.', { form }) }
}

/**
 * What the preview says, and whether it is a warning.
 *
 * @param {object} answer The preview.
 * @param {(text: string, vars?: object) => string} translate Translator.
 * @return {{warn: boolean, text: string}} The announcement.
 */
function announcementOf(answer, translate) {
	if (answer.state === 'resolved') {
		return resolvedAnnouncement(answer, translate)
	}
	if (answer.state === 'external') {
		return {
			warn: false,
			text: translate(
				'This entry sends people to {destination}. Nothing arrives here.',
				{ destination: answer.destination || '' },
			),
		}
	}
	return { warn: true, text: noFormSentence(answer, translate) }
}

/**
 * Build the row-action handler.
 *
 * @param {object} deps Collaborators.
 * @param {(url: string, body: object) => Promise<{data: object}>} deps.post POSTs JSON.
 * @param {(path: string) => string} deps.generateUrl Nextcloud's URL generator.
 * @param {(text: string) => void} deps.notify Success toast.
 * @param {(text: string) => void} deps.notifyWarning Warning toast.
 * @param {(text: string) => void} deps.notifyError Error toast.
 * @param {(text: string, vars?: object) => string} deps.translate Translator.
 * @return {{previewFormBinding: (payload: {item: object}) => Promise<boolean>}}
 * @spec openspec/specs/portal-intake-form/spec.md#requirement-a-portal-page-binds-to-a-published-form-not-to-a-field-list-req-pifo-001
 */
export function createFormBindingPreview(deps) {
	return {
		/**
		 * Say which form the row's binding opens today.
		 *
		 * @param {{item: object}} payload The row action payload.
		 * @return {Promise<boolean>} False when the check itself failed.
		 * @spec openspec/specs/portal-intake-form/spec.md#requirement-a-portal-page-binds-to-a-published-form-not-to-a-field-list-req-pifo-001
		 */
		async previewFormBinding({ item }) {
			const answer = await postBindingOrSay(deps, {
				path: '/apps/portaliq/api/form-bindings/preview',
				item,
				failure: (translate) =>
					translate('Could not check which form this entry opens. Try again.'),
			})
			if (answer === null) {
				return false
			}
			const { warn, text } = announcementOf(answer, deps.translate)
			const announce = warn ? deps.notifyWarning : deps.notify
			announce(text)
			return true
		},
	}
}
