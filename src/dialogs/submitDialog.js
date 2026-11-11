// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The last step of the dialogs that post through a `submit` prop
 * (InviteDialog, IssueAccountDialog, NewsItemDialog): once the post answers,
 * clear `busy`, then close with the success sentence or show the refusal.
 *
 * @spec openspec/specs/portal-account-administration/spec.md#requirement-staff-invite-an-address-and-portaliq-mails-it-req-isa-001
 * @spec openspec/specs/portal-account-administration/spec.md#requirement-staff-issue-and-withdraw-accounts-through-the-validated-actions-req-isa-003
 * @spec openspec/changes/staff-news-screen/tasks.md#T4
 *
 * @param {object} dialog The dialog: its `busy`, `refusal` and `$emit`.
 * @param {{ok: boolean, message: string}} outcome What the post answered.
 * @return {void}
 */
export function closeOrRefuse(dialog, outcome) {
	dialog.busy = false
	if (outcome.ok) {
		dialog.$emit('close', outcome.message)
		return
	}
	dialog.refusal = outcome.message
}
