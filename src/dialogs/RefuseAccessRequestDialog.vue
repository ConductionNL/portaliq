<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  RefuseAccessRequestDialog: the reason a refusal needs.

  Its own file per ADR-004's modal-isolation rule. Spawned by the Refuse row
  action on the Access requests page (src/lib/accessRequestActions.js); it
  closes with the reason, or with nothing when cancelled. A refusal is never
  sent without a reason, because the asker reads it in their request list.

  @spec openspec/specs/portal-access-requests/spec.md#requirement-staff-answer-the-requests-of-their-organisation-req-iar-002
-->
<template>
	<NcDialog
		:name="t('portaliq', 'Refuse this request')"
		size="normal"
		data-testid="refuse-access-request"
		@closing="$emit('close', null)">
		<NcTextArea
			v-model="reason"
			:label="t('portaliq', 'Reason for the refusal')"
			:helperText="t('portaliq', 'The person who asked reads this reason.')"
			data-testid="refuse-access-request-reason" />
		<p v-if="missing" class="refuse__missing" role="alert">
			{{ t('portaliq', 'Give a reason for the refusal.') }}
		</p>

		<template #actions>
			<NcButton @click="$emit('close', null)">
				{{ t('portaliq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="error"
				data-testid="refuse-access-request-confirm"
				@click="confirm">
				{{ t('portaliq', 'Refuse') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcDialog, NcTextArea } from '@nextcloud/vue'

export default {
	name: 'RefuseAccessRequestDialog',

	components: {
		NcButton,
		NcDialog,
		NcTextArea,
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
		 *
		 * @spec openspec/specs/portal-access-requests/spec.md#requirement-staff-answer-the-requests-of-their-organisation-req-iar-002
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
.refuse__missing {
	margin-top: 8px;
	color: var(--color-error-text);
}
</style>
