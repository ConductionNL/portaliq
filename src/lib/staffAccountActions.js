/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Issue an account, invite someone, withdraw an invitation and withdraw a
 * pending account, from the admin app (identity-staff-account-screens
 * T04-T06).
 *
 * WHY THE GUARDED ROUTES AND NOT THE OBJECT FORM. Every one of these goes
 * through `PortalAccountAdminController` behind `portal.provision`: the
 * provision route de-duplicates the identity, the invite route mails the
 * secret so the clerk never sees it, and void and revoke refuse what is no
 * longer theirs to undo. A generic object form would skip all of that.
 *
 * WHY THIS MODULE IMPORTS NOTHING. The POST, the URL generator, the
 * translator and the date format are handed in by `src/customComponents.js`
 * and the widgets, so `tests/staff-account-screens.spec.mjs` runs it as a
 * plain node script, the same shape as `accessRequestActions.js`.
 *
 * @spec openspec/specs/portal-account-administration/spec.md#requirement-staff-issue-and-withdraw-accounts-through-the-validated-actions-req-isa-003
 */

/**
 * The sentence for a refusal, by the server's error code, then by status.
 */
const REFUSALS = {
	refused: 'The account could not be issued. Check the organisation and the identity.',
	already_accepted: 'This invitation was already accepted, so it cannot be withdrawn.',
	not_found: 'This invitation was not found in its organisation.',
	not_pending: 'Only an account that was never used can be withdrawn.',
	reason_required: 'Give a reason.',
	mail_not_sent: 'The invitation mail could not be sent, so nobody was invited. Try again.',
}

/**
 * The English source sentence for a failed call.
 *
 * @param {object} error The rejected request.
 * @return {string}
 * @spec openspec/specs/portal-account-administration/spec.md#requirement-staff-issue-and-withdraw-accounts-through-the-validated-actions-req-isa-003
 */
export function failureKey(error) {
	const status = error?.response?.status ?? 0
	if (status === 403) {
		return 'You may not issue or withdraw portal accounts. Ask an administrator for this right.'
	}
	const code = String(error?.response?.data?.error || '')
	return REFUSALS[code] || 'The change could not be saved. Try again.'
}

/**
 * Whether an account can still be withdrawn: only one nobody used yet.
 *
 * @param {object|null} account The account row.
 * @return {boolean}
 * @spec openspec/specs/portal-account-administration/spec.md#requirement-staff-issue-and-withdraw-accounts-through-the-validated-actions-req-isa-003
 */
export function canWithdrawAccount(account) {
	return account?.status === 'pending'
}

/**
 * A row's id, wherever the register put it.
 *
 * @param {object} row The row.
 * @return {string}
 */
function idOf(row) {
	return String(row?.uuid || row?.id || row?.['@self']?.id || '')
}

/**
 * A trimmed string.
 *
 * @param {unknown} value The value.
 * @return {string}
 */
function text(value) {
	return String(value ?? '').trim()
}

/**
 * The calls behind the dialogs and row actions.
 *
 * @param {object} deps Collaborators.
 * @param {(url: string, body: object) => Promise<object>} deps.post POSTs JSON; rejects with `{response: {status, data}}` on a non-2xx.
 * @param {(path: string, params?: object) => string} deps.generateUrl Nextcloud's URL generator.
 * @param {(text: string, vars?: object) => string} deps.translate Translator.
 * @param {(iso: string) => string} deps.formatDate Formats a date for the clerk.
 * @return {object} `issue`, `invite`, `withdrawInvitation`, `voidAccount`, each answering `{ok, message}`.
 * @spec openspec/specs/portal-account-administration/spec.md#requirement-staff-issue-and-withdraw-accounts-through-the-validated-actions-req-isa-003
 */
export function createStaffAccountActions({ post, generateUrl, translate, formatDate }) {
	/**
	 * Post, and turn a refusal into a sentence.
	 *
	 * @param {string} path The app path.
	 * @param {object} params URL parameters.
	 * @param {object} body The JSON body.
	 * @return {Promise<{ok: boolean, data?: object, message?: string}>}
	 */
	async function send(path, params, body) {
		try {
			const response = await post(generateUrl(path, params), body)
			return { ok: true, data: response?.data || {} }
		} catch (error) {
			return { ok: false, message: translate(failureKey(error)) }
		}
	}

	return {
		/**
		 * Issue an account at the desk, through the provision route.
		 *
		 * @param {object} fields The dialog's fields.
		 * @return {Promise<{ok: boolean, message: string}>}
		 * @spec openspec/specs/portal-account-administration/spec.md#requirement-staff-issue-and-withdraw-accounts-through-the-validated-actions-req-isa-003
		 */
		async issue(fields) {
			const body = {
				audience: text(fields?.audience) || 'client',
				organisation: text(fields?.organisation),
				identityType: text(fields?.identityType),
				identityRef: text(fields?.identityRef),
				email: text(fields?.email),
				verifiedEmail: fields?.verifiedEmail === true,
				displayName: text(fields?.displayName),
			}
			if (body.organisation === '') {
				return { ok: false, message: translate('Give the organisation the account belongs to.') }
			}
			if (body.identityRef === '' && body.email === '') {
				return { ok: false, message: translate('Give an identity reference or an e-mail address.') }
			}
			const sent = await send('/apps/portaliq/api/accounts/provision', {}, body)
			if (sent.ok === false) {
				return sent
			}
			if (sent.data.isNew === false) {
				return {
					ok: false,
					message: translate('An account for this identity already exists, so no second account was made.'),
				}
			}
			return {
				ok: true,
				message: translate('The account is issued. It becomes active when its owner signs in for the first time.'),
			}
		},

		/**
		 * Invite an address. The answer carries the expiry, never the link.
		 *
		 * @param {object} fields `email`, `organisation`, `audience`.
		 * @return {Promise<{ok: boolean, message: string}>}
		 * @spec openspec/specs/portal-account-administration/spec.md#requirement-staff-invite-an-address-and-portaliq-mails-it-req-isa-001
		 */
		async invite(fields) {
			const body = {
				email: text(fields?.email),
				organisation: text(fields?.organisation),
				audience: text(fields?.audience) || 'client',
			}
			if (body.email === '' || body.organisation === '') {
				return { ok: false, message: translate('Give the address and the organisation.') }
			}
			const sent = await send('/apps/portaliq/api/invitations', {}, body)
			if (sent.ok === false) {
				return sent
			}
			return {
				ok: true,
				message: translate('Invitation sent to {email}. It is valid until {date}.', {
					email: body.email,
					date: formatDate(String(sent.data.expiresAt || '')),
				}),
			}
		},

		/**
		 * Withdraw an invitation that was not accepted.
		 *
		 * @param {object} row The invitation row.
		 * @return {Promise<{ok: boolean, message: string}>}
		 * @spec openspec/specs/portal-account-administration/spec.md#requirement-staff-see-and-withdraw-invitations-req-isa-002
		 */
		async withdrawInvitation(row) {
			const id = idOf(row)
			if (id === '') {
				return { ok: false, message: translate(failureKey(null)) }
			}
			const sent = await send('/apps/portaliq/api/invitations/{id}/revoke', { id }, {
				organisation: text(row?.organisation),
			})
			return sent.ok ? { ok: true, message: translate('The invitation is withdrawn. Its link admits nobody now.') } : sent
		},

		/**
		 * Withdraw a pending account, with the reason on the row.
		 *
		 * @param {object} account The account row.
		 * @param {string} reason Why.
		 * @return {Promise<{ok: boolean, message: string}>}
		 * @spec openspec/specs/portal-account-administration/spec.md#requirement-staff-issue-and-withdraw-accounts-through-the-validated-actions-req-isa-003
		 */
		async voidAccount(account, reason) {
			if (text(reason) === '') {
				return { ok: false, message: translate('Give a reason.') }
			}
			const sent = await send('/apps/portaliq/api/accounts/void', {}, {
				subjectRef: text(account?.subjectRef),
				reason: text(reason),
			})
			return sent.ok ? { ok: true, message: translate('The account is withdrawn.') } : sent
		},
	}
}

/**
 * The header and row actions the manifest names by handler.
 *
 * Issue and invite open a dialog that submits itself, so a refusal is shown
 * in the dialog and the clerk can correct it; the dialog closes with the
 * success sentence, or with null when cancelled.
 *
 * @param {object} deps Collaborators.
 * @param {object} deps.actions What `createStaffAccountActions` returns.
 * @param {(submit: Function) => Promise<string|null>} deps.openIssue Opens IssueAccountDialog.
 * @param {(submit: Function) => Promise<string|null>} deps.openInvite Opens InviteDialog.
 * @param {(row: object) => Promise<boolean>} deps.confirmWithdrawInvitation Asks before withdrawing.
 * @param {(text: string) => void} deps.notify Success toast.
 * @param {(text: string) => void} deps.notifyError Error toast.
 * @param {() => void} deps.reload Reloads the list.
 * @return {{issueAccount: Function, inviteSomeone: Function, withdrawInvitation: Function}}
 * @spec openspec/specs/portal-account-administration/spec.md#requirement-staff-see-and-withdraw-invitations-req-isa-002
 */
export function createStaffAccountHandlers({
	actions,
	openIssue,
	openInvite,
	confirmWithdrawInvitation,
	notify,
	notifyError,
	reload,
}) {
	/**
	 * Report a dialog's outcome.
	 *
	 * @param {string|null} message The success sentence, or null.
	 * @return {boolean}
	 */
	function closed(message) {
		if (typeof message !== 'string' || message === '') {
			return false
		}
		notify(message)
		reload()
		return true
	}

	return {
		/**
		 * `Issue an account` on the Accounts page.
		 *
		 * @return {Promise<boolean>}
		 * @spec openspec/specs/portal-account-administration/spec.md#requirement-staff-issue-and-withdraw-accounts-through-the-validated-actions-req-isa-003
		 */
		async issueAccount() {
			return closed(await openIssue((fields) => actions.issue(fields)))
		},

		/**
		 * `Invite someone` on the Accounts and Invitations pages.
		 *
		 * @return {Promise<boolean>}
		 * @spec openspec/specs/portal-account-administration/spec.md#requirement-staff-invite-an-address-and-portaliq-mails-it-req-isa-001
		 */
		async inviteSomeone() {
			return closed(await openInvite((fields) => actions.invite(fields)))
		},

		/**
		 * `Withdraw invitation` on an Invitations row.
		 *
		 * @param {{item: object}} payload The row action payload.
		 * @return {Promise<boolean>}
		 * @spec openspec/specs/portal-account-administration/spec.md#requirement-staff-see-and-withdraw-invitations-req-isa-002
		 */
		async withdrawInvitation({ item }) {
			if ((await confirmWithdrawInvitation(item)) !== true) {
				return false
			}
			const outcome = await actions.withdrawInvitation(item)
			if (outcome.ok === false) {
				notifyError(outcome.message)
				return false
			}
			notify(outcome.message)
			reload()
			return true
		},
	}
}
