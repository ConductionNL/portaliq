<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  PortalAccountWithdraw: "Withdraw this account" on an account's own page
  (identity-staff-account-screens T06).

  Only a pending account, one nobody signed in with yet, offers it. The
  reason comes from VoidAccountDialog and the withdrawal goes through the void
  route behind portal.provision, which refuses an account in use. An active
  account shows no button, only why.

  A widget and not a header action: a detail page's header action cannot
  open a dialog that knows the record in nc-vue 2.57, and its handler map is
  not the one the index pages read.

  @spec openspec/specs/portal-account-administration/spec.md#requirement-staff-issue-and-withdraw-accounts-through-the-validated-actions-req-isa-003
-->
<template>
	<div class="account-withdraw" data-testid="portal-account-withdraw">
		<template v-if="pending">
			<p>
				{{
					t(
						'portaliq',
						'Nobody has signed in with this account yet. You can withdraw it, for example when it was issued to the wrong address.',
					)
				}}
			</p>
			<NcNoteCard v-if="notice" :type="noticeType">
				{{ notice }}
			</NcNoteCard>
			<NcButton
				variant="error"
				:disabled="busy"
				data-testid="portal-account-withdraw-button"
				@click="withdraw">
				{{ t('portaliq', 'Withdraw this account') }}
			</NcButton>
		</template>
		<p v-else class="account-withdraw__in-use">
			{{
				t(
					'portaliq',
					'Only an account that was never used can be withdrawn.',
				)
			}}
		</p>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcNoteCard } from '@nextcloud/vue'
import { spawnDialog } from '@nextcloud/vue/functions/dialog'
import VoidAccountDialog from '../dialogs/VoidAccountDialog.vue'
import {
	canWithdrawAccount,
	createStaffAccountActions,
} from '../lib/staffAccountActions.js'

export default {
	name: 'PortalAccountWithdraw',

	components: {
		NcButton,
		NcNoteCard,
	},

	props: {
		/** The account the detail page shows. */
		objectData: {
			type: Object,
			default: null,
		},
	},

	data() {
		return {
			busy: false,
			notice: '',
			noticeType: 'success',
		}
	},

	computed: {
		/**
		 * Whether this account can still be withdrawn.
		 *
		 * @return {boolean}
		 * @spec openspec/specs/portal-account-administration/spec.md#requirement-staff-issue-and-withdraw-accounts-through-the-validated-actions-req-isa-003
		 */
		pending() {
			return canWithdrawAccount(this.objectData)
		},
	},

	/**
	 * @spec openspec/specs/portal-account-administration/spec.md#requirement-staff-issue-and-withdraw-accounts-through-the-validated-actions-req-isa-003
	 */
	created() {
		this.actions = createStaffAccountActions({
			post: (url, body) => axios.post(url, body),
			generateUrl,
			translate: (text, vars) => t('portaliq', text, vars),
			formatDate: (iso) => iso,
		})
	},

	methods: {
		t,

		/**
		 * Ask for the reason, withdraw, and show the account in its new state.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/portal-account-administration/spec.md#requirement-staff-issue-and-withdraw-accounts-through-the-validated-actions-req-isa-003
		 */
		async withdraw() {
			const reason = await spawnDialog(VoidAccountDialog)
			if (typeof reason !== 'string' || reason === '') {
				return
			}
			this.busy = true
			const outcome = await this.actions.voidAccount(this.objectData, reason)
			this.busy = false
			this.notice = outcome.message
			this.noticeType = outcome.ok ? 'success' : 'error'
			if (outcome.ok) {
				window.location.reload()
			}
		},
	},
}
</script>

<style scoped>
.account-withdraw {
	display: flex;
	flex-direction: column;
	gap: 8px;
	align-items: flex-start;
}

.account-withdraw__in-use {
	color: var(--color-text-maxcontrast);
}
</style>
