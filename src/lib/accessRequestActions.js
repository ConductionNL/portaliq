/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Grant and Refuse row actions on the Access requests page (#797).
 *
 * WHY HANDLERS. Answering a request is not a field edit on its row: a grant
 * also records the mandate that opens the cases, and both go through
 * `AccessRequestAdminController`, behind `portal.answer-access-request`. An
 * index page's row action can only navigate or call a handler, so each answer
 * is a handler that posts to that controller.
 *
 * WHY THIS MODULE IMPORTS NOTHING. Its collaborators (the POST, the URL
 * generator, the dialogs, the toasts, the translator, the reload) are handed
 * in by `src/customComponents.js`, so `tests/access-requests.spec.mjs` runs it
 * as a plain node script, the same shape as `openPortalSite.js`.
 *
 * @spec openspec/changes/identity-access-requests/specs/portal-access-requests/spec.md#requirement-staff-answer-the-requests-of-their-organisation-req-iar-002
 */

/**
 * The request row's id, wherever the register put it.
 *
 * @param {object} row The request row.
 * @return {string}
 */
function idOf(row) {
	return String(row?.uuid || row?.id || row?.['@self']?.id || '')
}

/**
 * The sentence for a failed answer, by HTTP status.
 *
 * @param {number} status The status, 0 on a network error.
 * @return {string} The English source string.
 */
export function failureKey(status) {
	if (status === 403) {
		return 'You may not answer access requests. Ask an administrator for this right.'
	}
	if (status === 409) {
		return 'Someone already answered this request.'
	}
	if (status === 502) {
		return 'The access could not be recorded, so the request is still waiting. Try again.'
	}
	return 'The access requests could not be loaded or saved. Try again.'
}

/**
 * Build the two row-action handlers.
 *
 * @param {object} deps Collaborators.
 * @param {(url: string, body: object) => Promise<object>} deps.post POSTs JSON; rejects with `{response: {status}}` on a non-2xx.
 * @param {(path: string, params: object) => string} deps.generateUrl Nextcloud's URL generator.
 * @param {(row: object) => Promise<boolean>} deps.confirmGrant Asks whether to grant.
 * @param {() => Promise<string|null>} deps.askReason Asks for the refusal reason; null when cancelled.
 * @param {(text: string) => void} deps.notify Success toast.
 * @param {(text: string) => void} deps.notifyError Error toast.
 * @param {(text: string) => string} deps.translate Translator.
 * @param {() => void} deps.reload Reloads the list after an answer.
 * @return {{grantAccessRequest: (payload: {item: object}) => Promise<boolean>, refuseAccessRequest: (payload: {item: object}) => Promise<boolean>}}
 */
export function createAccessRequestHandlers({
	post,
	generateUrl,
	confirmGrant,
	askReason,
	notify,
	notifyError,
	translate,
	reload,
}) {
	/**
	 * Post one answer and report it.
	 *
	 * @param {object} row The request row.
	 * @param {string} verb `grant` or `refuse`.
	 * @param {object} body Extra fields.
	 * @param {string} doneKey The success sentence.
	 * @return {Promise<boolean>} Whether it landed.
	 */
	async function answer(row, verb, body, doneKey) {
		const id = idOf(row)
		if (id === '') {
			notifyError(translate(failureKey(0)))
			return false
		}
		try {
			await post(
				generateUrl('/apps/portaliq/api/access-requests/{id}/{verb}', {
					id,
					verb,
				}),
				{ ...body, organisation: row?.organisation || '' },
			)
		} catch (error) {
			notifyError(translate(failureKey(error?.response?.status ?? 0)))
			return false
		}
		notify(translate(doneKey))
		reload()
		return true
	}

	return {
		/**
		 * Grant: ask first, because a grant opens an organisation's cases.
		 *
		 * @param {{item: object}} payload The row action payload.
		 * @return {Promise<boolean>}
		 * @spec openspec/changes/identity-access-requests/specs/portal-access-requests/spec.md#requirement-a-granted-request-opens-the-cases-req-iar-003
		 */
		async grantAccessRequest({ item }) {
			if ((await confirmGrant(item)) !== true) {
				return false
			}
			return answer(item, 'grant', {}, 'Access granted.')
		},

		/**
		 * Refuse: only with a reason.
		 *
		 * @param {{item: object}} payload The row action payload.
		 * @return {Promise<boolean>}
		 * @spec openspec/changes/identity-access-requests/specs/portal-access-requests/spec.md#requirement-staff-answer-the-requests-of-their-organisation-req-iar-002
		 */
		async refuseAccessRequest({ item }) {
			const reason = await askReason()
			if (typeof reason !== 'string' || reason.trim() === '') {
				return false
			}
			return answer(
				item,
				'refuse',
				{ reason: reason.trim() },
				'Request refused.',
			)
		},
	}
}
