/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The row actions of the Sessions list: open the account a session was
 * issued for, and revoke one session through the admin route. The factory
 * takes its collaborators as arguments so it runs as a plain node module in
 * tests/session-row-actions.spec.mjs.
 *
 * @spec openspec/changes/staff-revoke-one-session/specs/portal-account-administration/spec.md#requirement-staff-revoke-one-portal-session-req-isa-010
 */

/** The admin route that revokes one session by its token id. */
export const SESSION_REVOKE_PATH = '/apps/portaliq/api/session-admin/revoke-session'

/** The app route of one portal account. */
const ACCOUNT_PATH = '/apps/portaliq/accounts/'

/**
 * The sentence for a refused revoke.
 *
 * @param {object} error The transport error.
 * @return {string} An English source sentence.
 * @spec openspec/changes/staff-revoke-one-session/specs/portal-account-administration/spec.md#requirement-staff-revoke-one-portal-session-req-isa-010
 */
export function revokeFailure(error) {
	const status = error?.response?.status ?? 0
	if (status === 403 || status === 401) {
		return 'Only an administrator may revoke a session.'
	}
	if (status === 404) {
		return 'This session was not found or is already revoked.'
	}
	return 'The change could not be saved. Try again.'
}

/**
 * The Sessions row action handlers.
 *
 * @param {object} deps Collaborators.
 * @param {(url: string, body: object) => Promise<object>} deps.post HTTP POST.
 * @param {(path: string) => string} deps.generateUrl Nextcloud URL generator.
 * @param {(row: object) => Promise<boolean>} deps.confirmRevoke Asks before revoking.
 * @param {(text: string) => void} deps.notify Success toast.
 * @param {(text: string) => void} deps.notifyError Error toast.
 * @param {(text: string) => string} deps.translate Translator.
 * @param {() => void} deps.reload Shows the list again.
 * @param {(url: string) => void} deps.open Goes to an address.
 * @return {{revokeSession: Function, openSessionAccount: Function}}
 * @spec openspec/changes/staff-revoke-one-session/specs/portal-account-administration/spec.md#requirement-staff-revoke-one-portal-session-req-isa-010
 */
export function createSessionHandlers({ post, generateUrl, confirmRevoke, notify, notifyError, translate, reload, open }) {
	return {
		/**
		 * `Sessie intrekken` on a Sessions row.
		 *
		 * @param {{item: object}} payload The row action payload.
		 * @return {Promise<boolean>} Whether the session was revoked.
		 * @spec openspec/changes/staff-revoke-one-session/specs/portal-account-administration/spec.md#requirement-staff-revoke-one-portal-session-req-isa-010
		 */
		async revokeSession({ item }) {
			const jti = String(item?.jti ?? '').trim()
			if (jti === '') {
				notifyError(translate(revokeFailure({ response: { status: 404 } })))
				return false
			}
			if ((await confirmRevoke(item)) !== true) {
				return false
			}
			try {
				await post(generateUrl(SESSION_REVOKE_PATH), { jti })
			} catch (error) {
				notifyError(translate(revokeFailure(error)))
				return false
			}
			notify(translate('The session is revoked.'))
			reload()
			return true
		},

		/**
		 * `Naar het account` on a Sessions row.
		 *
		 * @param {{item: object}} payload The row action payload.
		 * @return {boolean} Whether there was an account to open.
		 * @spec openspec/changes/staff-revoke-one-session/specs/portal-account-administration/spec.md#requirement-staff-revoke-one-portal-session-req-isa-010
		 */
		openSessionAccount({ item }) {
			const account = String(item?.accountRef ?? '').trim()
			if (account === '') {
				return false
			}
			open(generateUrl(ACCOUNT_PATH + encodeURIComponent(account)))
			return true
		},
	}
}
