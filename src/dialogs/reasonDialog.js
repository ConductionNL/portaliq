// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * What every dialog that asks for a reason shares: the reason being typed,
 * whether the "give a reason" notice shows, and the confirm step. Each dialog
 * (RefuseAccessRequestDialog, RevealDecisionDialog, VoidAccountDialog) keeps
 * its own texts, test ids, props and `emits: ['close']`, and spreads these
 * options into its own.
 *
 * @spec openspec/specs/portal-access-requests/spec.md#requirement-staff-answer-the-requests-of-their-organisation-req-iar-002
 * @spec openspec/changes/intake-report-pages/specs/report-pages/spec.md#requirement-the-custodian-decides-a-reveal-on-a-screen-of-their-own-req-irp-006
 * @spec openspec/specs/portal-account-administration/spec.md#requirement-staff-issue-and-withdraw-accounts-through-the-validated-actions-req-isa-003
 */

import { translate as t } from '@nextcloud/l10n'

export const reasonDialog = {
	data() {
		return {
			reason: '',
			missing: false,
		}
	},

	methods: {
		t,

		/**
		 * Close with the trimmed reason, or say that one is needed.
		 *
		 * @return {void}
		 */
		confirm() {
			const reason = this.reason.trim()
			if (reason === '') {
				this.missing = true
				return
			}

			this.$emit('close', reason)
		},
	},
}
