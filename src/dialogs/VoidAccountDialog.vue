<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  VoidAccountDialog: the reason a withdrawn account, or a refused
  registration, needs.

  Its own file per ADR-004's modal-isolation rule. Spawned by the
  PortalAccountWithdraw widget ("Withdraw this account") and by the Refuse
  button in the PortalRegistration widget. It closes with the reason, or with
  null when cancelled. Nothing is withdrawn without a reason, because the
  reason stays on the account.

  @spec openspec/specs/portal-account-administration/spec.md#requirement-staff-issue-and-withdraw-accounts-through-the-validated-actions-req-isa-003
-->
<template>
	<NcDialog
		:name="title || t('portaliq', 'Withdraw this account')"
		size="normal"
		data-testid="void-account"
		@closing="$emit('close', null)">
		<NcTextArea
			v-model="reason"
			:label="t('portaliq', 'Reason')"
			:helperText="t('portaliq', 'The reason stays on the account.')"
			data-testid="void-account-reason" />
		<p v-if="missing" class="void__missing" role="alert">
			{{ t('portaliq', 'Give a reason.') }}
		</p>

		<template #actions>
			<NcButton @click="$emit('close', null)">
				{{ t('portaliq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="error"
				data-testid="void-account-confirm"
				@click="confirm">
				{{ confirmLabel || t('portaliq', 'Withdraw') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcDialog, NcTextArea } from '@nextcloud/vue'

export default {
	name: 'VoidAccountDialog',

	components: {
		NcButton,
		NcDialog,
		NcTextArea,
	},

	props: {
		/** The dialog title; "Withdraw this account" when empty. */
		title: {
			type: String,
			default: '',
		},

		/** The confirm button's label; "Withdraw" when empty. */
		confirmLabel: {
			type: String,
			default: '',
		},
	},

	emits: ['close'],

	data() {
		return {
			reason: '',
			missing: false,
		}
	},

	methods: {
		t,

		/**
		 * Close with the reason, or say that one is needed.
		 *
		 * @return {void}
		 * @spec openspec/specs/portal-account-administration/spec.md#requirement-staff-issue-and-withdraw-accounts-through-the-validated-actions-req-isa-003
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
</script>

<style scoped>
.void__missing {
	margin-top: 8px;
	color: var(--color-error-text);
}
</style>
