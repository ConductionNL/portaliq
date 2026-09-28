<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  RefuseAccessRequestDialog: the reason a refusal needs.

  Its own file per ADR-004's modal-isolation rule. A refusal is never saved
  without a reason, because the asker reads it in their request list.

  @spec openspec/changes/identity-access-requests/specs/portal-access-requests/spec.md#requirement-staff-answer-the-requests-of-their-organisation-req-iar-002
-->
<template>
	<NcDialog
		:name="t('portaliq', 'Refuse this request')"
		:open="open"
		size="normal"
		data-testid="refuse-access-request"
		@update:open="$emit('update:open', $event)">
		<NcTextArea
			v-model="reason"
			:label="t('portaliq', 'Reason for the refusal')"
			:helperText="t('portaliq', 'The person who asked reads this reason.')"
			data-testid="refuse-access-request-reason" />
		<p v-if="missing" class="refuse__missing" role="alert">
			{{ t('portaliq', 'Give a reason for the refusal.') }}
		</p>

		<template #actions>
			<NcButton @click="$emit('update:open', false)">
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
import { NcButton, NcDialog, NcTextArea } from '@nextcloud/vue'

export default {
	name: 'RefuseAccessRequestDialog',

	components: {
		NcButton,
		NcDialog,
		NcTextArea,
	},

	props: {
		/** Whether the dialog is open. */
		open: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['update:open', 'refuse'],

	data() {
		return {
			reason: '',
			missing: false,
		}
	},

	watch: {
		/**
		 * Start empty every time the dialog opens.
		 *
		 * @param {boolean} isOpen Whether it is open now.
		 * @return {void}
		 */
		open(isOpen) {
			if (isOpen) {
				this.reason = ''
				this.missing = false
			}
		},
	},

	methods: {
		/**
		 * Hand the reason to the page, or say that one is needed.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/identity-access-requests/specs/portal-access-requests/spec.md#requirement-staff-answer-the-requests-of-their-organisation-req-iar-002
		 */
		confirm() {
			const reason = this.reason.trim()
			if (reason === '') {
				this.missing = true
				return
			}

			this.$emit('refuse', reason)
			this.$emit('update:open', false)
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
