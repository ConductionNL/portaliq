// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// The inbox badge counts what the inbox shows (woo-inbox-notices).
//
// The shell reads the unread count once, at sign-in. A notice that arrives
// later (a saved-search match, a decision, an answer) is written by a
// background job, so the badge stayed at the old number while the inbox
// listed more unread rows. Once the inbox has loaded its rows, the badge
// takes their count.
//
// @spec openspec/changes/woo-inbox-notices/specs/portal-notifications-and-preferences/spec.md#requirement-the-inbox-badge-counts-the-unread-messages-the-inbox-shows-req-nap-011

/**
 * How many of the loaded inbox rows are unread.
 *
 * @param {Array<object>|null|undefined} messages The inbox rows.
 * @return {number} The unread count.
 */
export function unreadIn(messages) {
	if (!Array.isArray(messages)) {
		return 0
	}
	return messages.filter((message) => message && message.read !== true).length
}
