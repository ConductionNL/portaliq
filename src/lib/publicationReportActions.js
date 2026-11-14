/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The "Handled" and "Not applicable" row actions on the Error reports page
 * (publication-error-reports-and-withheld-notices REQ-PER-002).
 *
 * WHY HANDLERS. Closing a report is not a field edit: only the portal's owner
 * of error reports may, and the close is stamped with who and when, so each
 * goes through `PublicationReportController::close`.
 *
 * WHY THIS MODULE IMPORTS NOTHING. Its collaborators (the POST, the URL
 * generator, the toasts, the translator, the reload) are handed in by
 * `src/customComponents.js`, so `tests/publication-report.spec.mjs` runs it
 * as a plain node script.
 *
 * @spec openspec/changes/publication-error-reports-and-withheld-notices/specs/portal-federated-search/spec.md#requirement-a-report-reaches-the-named-owner-req-per-002
 */

/**
 * The report row's id, wherever the register put it.
 *
 * @param {object} row The report row.
 * @return {string}
 */
function idOf(row) {
	return String(row?.uuid || row?.id || row?.['@self']?.id || '')
}

/**
 * The sentence for a refused close, by HTTP status.
 *
 * @param {number} status The status, 0 on a network error.
 * @return {string} The English source string.
 * @spec openspec/changes/publication-error-reports-and-withheld-notices/specs/portal-federated-search/spec.md#requirement-a-report-reaches-the-named-owner-req-per-002
 */
export function closeFailureKey(status) {
	if (status === 403) {
		return 'Only the owner of this portal\'s error reports can close them.'
	}
	return 'The report could not be closed. Try again.'
}

/**
 * Build the two row-action handlers.
 *
 * @param {object} deps Collaborators.
 * @param {(url: string, body: object) => Promise<object>} deps.post POSTs JSON; rejects with `{response: {status}}` on a non-2xx.
 * @param {(path: string, params: object) => string} deps.generateUrl Nextcloud's URL generator.
 * @param {(text: string) => void} deps.notify Success toast.
 * @param {(text: string) => void} deps.notifyError Error toast.
 * @param {(text: string) => string} deps.translate Translator.
 * @param {() => void} deps.reload Reloads the list after a close.
 * @return {{markReportHandled: (payload: {item: object}) => Promise<boolean>, markReportNotApplicable: (payload: {item: object}) => Promise<boolean>}}
 * @spec openspec/changes/publication-error-reports-and-withheld-notices/specs/portal-federated-search/spec.md#requirement-a-report-reaches-the-named-owner-req-per-002
 */
export function createPublicationReportHandlers({
	post,
	generateUrl,
	notify,
	notifyError,
	translate,
	reload,
}) {
	/**
	 * Close one report and say so.
	 *
	 * @param {object} row The report's row.
	 * @param {string} status `handled` or `not-applicable`.
	 * @param {string} done The success sentence.
	 * @return {Promise<boolean>} Whether it was closed.
	 */
	async function close(row, status, done) {
		const id = idOf(row)
		if (id === '') {
			notifyError(translate(closeFailureKey(0)))
			return false
		}
		try {
			await post(
				generateUrl('/apps/portaliq/api/publication-reports/{id}/close', { id }),
				{ portal: String(row?.portal || ''), status },
			)
		} catch (error) {
			notifyError(translate(closeFailureKey(error?.response?.status ?? 0)))
			return false
		}
		notify(translate(done))
		reload()
		return true
	}

	return {
		/**
		 * @param {{item: object}} payload The row action payload.
		 * @return {Promise<boolean>}
		 * @spec openspec/changes/publication-error-reports-and-withheld-notices/specs/portal-federated-search/spec.md#requirement-a-report-reaches-the-named-owner-req-per-002
		 */
		markReportHandled({ item }) {
			return close(item, 'handled', 'The report is marked handled.')
		},

		/**
		 * @param {{item: object}} payload The row action payload.
		 * @return {Promise<boolean>}
		 * @spec openspec/changes/publication-error-reports-and-withheld-notices/specs/portal-federated-search/spec.md#requirement-a-report-reaches-the-named-owner-req-per-002
		 */
		markReportNotApplicable({ item }) {
			return close(item, 'not-applicable', 'The report is marked not applicable.')
		},
	}
}
