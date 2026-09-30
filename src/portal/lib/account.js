// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// "My account" helpers (identity-profile-page): the confirmation link's
// fragment, the sentence for each refusal, and the session-long dismissal of
// the prompt for a missing e-mail address.

const DISMISS_KEY = 'portaliq.contactPromptDismissed'

/**
 * Read the secret from a `#confirm-email=<secret>` link once and strip it
 * from the address bar, the same way the sign-in fragment is consumed. A
 * fragment never reaches a server access log.
 *
 * @param {Location|object} location The window location.
 * @param {History|object} history The window history.
 * @return {string} The secret, or '' when the page was not opened from the link.
 *
 * @spec openspec/changes/archive/2026-09-30-identity-profile-page/tasks.md#T08
 */
export function consumeConfirmEmail(location, history) {
	const match = String(location?.hash || '').match(/^#confirm-email=([^&]+)$/)
	if (!match) {
		return ''
	}

	const url = String(location.href || '').replace(/#.*$/, '')
	history?.replaceState?.(null, '', url)
	return decodeURIComponent(match[1])
}

/**
 * The sentence for a refusal the account routes answer, as an English
 * source key.
 *
 * @param {string} code The refusal code.
 * @return {string} The sentence key.
 *
 * @spec openspec/changes/archive/2026-09-30-identity-profile-page/specs/portal-profile/spec.md#requirement-you-keep-several-addresses-one-of-each-kind-preferred-req-ipp-003
 */
export function refusalText(code) {
	const texts = {
		confirm_first: 'Confirm this address first.',
		choose_another_preferred: 'Choose another preferred address first.',
		too_many_pending:
			'Five addresses are waiting for confirmation. Confirm or remove one first.',
		exists: 'This address is already on your account.',
		invalid: 'Check the address and try again.',
		link_not_valid: 'This link is no longer valid.',
	}
	return texts[code] || 'That did not work. Try again later.'
}

/**
 * Whether the prompt for an e-mail address was dismissed in this session.
 *
 * @param {Storage|null} store sessionStorage, or null where the browser refuses it.
 * @return {boolean}
 *
 * @spec openspec/changes/archive/2026-09-30-identity-profile-page/tasks.md#T09
 */
export function promptDismissed(store) {
	try {
		return store?.getItem(DISMISS_KEY) === '1'
	} catch {
		return false
	}
}

/**
 * Dismiss the prompt for the rest of the session. The next sign-in asks
 * again while the account still has no address.
 *
 * @param {Storage|null} store sessionStorage, or null.
 *
 * @spec openspec/changes/archive/2026-09-30-identity-profile-page/tasks.md#T09
 */
export function dismissPrompt(store) {
	try {
		store?.setItem(DISMISS_KEY, '1')
	} catch {
		// A browser that refuses storage asks again on the next page load.
	}
}
