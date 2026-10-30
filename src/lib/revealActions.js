/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Allow and Refuse on a reveal request (intake-report-pages T09): both ask
 * for a reason and post the custodian's decision. An allowed reveal shows
 * what the reporter gave once, in a dialog, and nowhere else: never in a
 * toast, never in the list. The factory takes its collaborators as
 * arguments so tests/report-staff-lists.spec.mjs runs it in node.
 *
 * @spec openspec/changes/intake-report-pages/specs/report-pages/spec.md#requirement-the-custodian-decides-a-reveal-on-a-screen-of-their-own-req-irp-006
 */

/** The route that records the custodian's decision. */
export const DECIDE_PATH = '/apps/portaliq/api/reveal-requests/{id}/decide'

/** Why a decision was refused, by the server's error code. */
const REFUSALS = {
	not_custodian: 'Only the custodian of this kind of report may answer.',
	not_pending: 'This request was already answered.',
	request_not_found: 'This request was not found.',
	report_not_found: 'This request was not found.',
}

/**
 * The sentence for a refused decision.
 *
 * @param {object} error The transport error.
 * @return {string} An English source sentence.
 * @spec openspec/changes/intake-report-pages/specs/report-pages/spec.md#requirement-the-custodian-decides-a-reveal-on-a-screen-of-their-own-req-irp-006
 */
export function decisionFailure(error) {
	return (
		REFUSALS[String(error?.response?.data?.error || '')]
		|| 'The change could not be saved. Try again.'
	)
}

/**
 * The reveal request row handlers.
 *
 * @param {object} deps Collaborators.
 * @param {(url: string, body: object) => Promise<object>} deps.post HTTP POST.
 * @param {(path: string, params: object) => string} deps.generateUrl Nextcloud URL generator.
 * @param {(allow: boolean) => Promise<string|null>} deps.askReason Asks the reason, null when cancelled.
 * @param {(contact: object) => Promise<void>} deps.showContact Shows what the reporter gave, once.
 * @param {(text: string) => void} deps.notify Success toast.
 * @param {(text: string) => void} deps.notifyError Error toast.
 * @param {(text: string) => string} deps.translate Translator.
 * @param {() => void} deps.reload Shows the list again.
 * @return {{allowReveal: Function, refuseReveal: Function}}
 * @spec openspec/changes/intake-report-pages/specs/report-pages/spec.md#requirement-the-custodian-decides-a-reveal-on-a-screen-of-their-own-req-irp-006
 */
export function createRevealHandlers({
	post,
	generateUrl,
	askReason,
	showContact,
	notify,
	notifyError,
	translate,
	reload,
}) {
	/**
	 * Ask the reason and post one decision.
	 *
	 * @param {object} item The request row.
	 * @param {boolean} allow Whether it is allowed.
	 * @return {Promise<boolean>} Whether it was recorded.
	 */
	async function decide(item, allow) {
		const id = String(item?.id || '')
		if (id === '') {
			return false
		}
		const reason = await askReason(allow)
		if (typeof reason !== 'string' || reason.trim() === '') {
			return false
		}
		let answer
		try {
			answer =
				(
					await post(generateUrl(DECIDE_PATH, { id }), {
						allow,
						reason: reason.trim(),
					})
				)?.data || {}
		} catch (error) {
			notifyError(translate(decisionFailure(error)))
			return false
		}
		if (allow && answer.contact) {
			await showContact(answer.contact)
		}
		notify(
			translate(
				allow
					? 'The reveal is allowed and recorded.'
					: 'The reveal is refused and recorded.',
			),
		)
		reload()
		return true
	}

	return {
		/**
		 * `Toestaan` on a reveal request.
		 *
		 * @param {{item: object}} payload The row action payload.
		 * @return {Promise<boolean>}
		 * @spec openspec/changes/intake-report-pages/specs/report-pages/spec.md#requirement-the-custodian-decides-a-reveal-on-a-screen-of-their-own-req-irp-006
		 */
		allowReveal({ item }) {
			return decide(item, true)
		},

		/**
		 * `Weigeren` on a reveal request.
		 *
		 * @param {{item: object}} payload The row action payload.
		 * @return {Promise<boolean>}
		 * @spec openspec/changes/intake-report-pages/specs/report-pages/spec.md#requirement-the-custodian-decides-a-reveal-on-a-screen-of-their-own-req-irp-006
		 */
		refuseReveal({ item }) {
			return decide(item, false)
		},
	}
}
