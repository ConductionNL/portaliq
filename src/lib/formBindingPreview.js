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
 * WHY THIS MODULE IMPORTS NOTHING. The POST, the URL generator, the toasts and
 * the translator are handed in by `src/customComponents.js`, so
 * `tests/form-binding-preview.spec.mjs` runs it as a plain node script.
 *
 * @spec openspec/specs/portal-intake-form/spec.md#requirement-a-portal-page-binds-to-a-published-form-not-to-a-field-list-req-pifo-001
 */

/**
 * The binding fields the preview reads, without the row's metadata.
 *
 * @param {object} row The binding row.
 * @return {object}
 */
function bindingOf(row) {
	const binding = {}
	for (const [key, value] of Object.entries(row || {})) {
		if (!key.startsWith('@') && key !== 'id' && key !== 'uuid') {
			binding[key] = value
		}
	}
	return binding
}

/**
 * The sentence for a binding that opens no form, by reason.
 *
 * @param {object} answer The preview.
 * @param {(text: string, vars?: object) => string} translate Translator.
 * @return {string}
 * @spec openspec/specs/portal-intake-form/spec.md#requirement-a-portal-page-binds-to-a-published-form-not-to-a-field-list-req-pifo-001
 */
export function noFormSentence(answer, translate) {
	if (answer?.reason === 'external_without_address') {
		return translate(
			'This entry sends people to another website, but has no address. Nobody can start it.',
		)
	}
	if (answer?.reason === 'hidden_case_type') {
		return translate(
			"This entry opens no form: this portal does not show its case type. Show it again on the portal's case types page.",
		)
	}
	if (answer?.reason === 'named_form_not_published') {
		return translate(
			'This entry opens no form today. It asks for "{form}", and no form of that name is published to its audience.',
			{ form: answer?.askedFor || '' },
		)
	}
	return translate(
		'This entry opens no form today. No published form matches its case type and audience.',
	)
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
export function createFormBindingPreview({
	post,
	generateUrl,
	notify,
	notifyWarning,
	notifyError,
	translate,
}) {
	return {
		/**
		 * Say which form the row's binding opens today.
		 *
		 * @param {{item: object}} payload The row action payload.
		 * @return {Promise<boolean>} False when the check itself failed.
		 * @spec openspec/specs/portal-intake-form/spec.md#requirement-a-portal-page-binds-to-a-published-form-not-to-a-field-list-req-pifo-001
		 */
		async previewFormBinding({ item }) {
			let answer
			try {
				const response = await post(
					generateUrl('/apps/portaliq/api/form-bindings/preview'),
					{
						binding: bindingOf(item),
					},
				)
				answer = response?.data || {}
			} catch {
				notifyError(
					translate(
						'Could not check which form this entry opens. Try again.',
					),
				)
				return false
			}

			if (answer.state === 'resolved') {
				notify(
					translate('This entry opens "{form}" today.', {
						form: answer.formName || '',
					}),
				)
			} else if (answer.state === 'external') {
				notify(
					translate(
						'This entry sends people to {destination}. Nothing arrives here.',
						{
							destination: answer.destination || '',
						},
					),
				)
			} else {
				notifyWarning(noFormSentence(answer, translate))
			}
			return true
		},
	}
}
