<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  RevealDecisionDialog: the reason a custodian gives for allowing or refusing
  a reveal request. Spawned by the row actions on the Reveal requests page
  (src/lib/revealActions.js); it closes with the reason, or with nothing when
  cancelled. Every decision is recorded with its reason.

  @spec openspec/changes/intake-report-pages/specs/report-pages/spec.md#requirement-the-custodian-decides-a-reveal-on-a-screen-of-their-own-req-irp-006
-->
<template>
	<CnDialog
		:look="look"
		:name="allow ? t('portaliq', 'Allow this reveal') : t('portaliq', 'Refuse this reveal')"
		size="normal"
		data-testid="reveal-decision"
		@closing="$emit('close', null)">
		<p>{{ t('portaliq', 'Your answer and its reason are recorded with the request.') }}</p>
		<NcTextArea
			v-model="reason"
			:label="t('portaliq', 'Reason')"
			data-testid="reveal-decision-reason" />
		<p v-if="missing" class="reveal-decision__missing" role="alert">
			{{ t('portaliq', 'Give a reason.') }}
		</p>

		<template #actions>
			<NcButton @click="$emit('close', null)">
				{{ t('portaliq', 'Cancel') }}
			</NcButton>
			<NcButton
				:variant="allow ? 'primary' : 'error'"
				data-testid="reveal-decision-confirm"
				@click="confirm">
				{{ allow ? t('portaliq', 'Allow') : t('portaliq', 'Refuse') }}
			</NcButton>
		</template>
	</CnDialog>
</template>

<script>
import { CnDialog } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcTextArea } from '@nextcloud/vue'

export default {
	name: 'RevealDecisionDialog',

	components: {
		NcButton,
		CnDialog,
		NcTextArea,
	},

	props: {
		/** Whether the custodian is allowing (true) or refusing (false). */
		allow: { type: Boolean, default: false },
		/** The look to draw in; a spawned dialog gets the app's (src/lib/dialogLook.js). */
		look: {
			type: String,
			default: undefined,
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
		 *
		 * @spec openspec/changes/intake-report-pages/specs/report-pages/spec.md#requirement-the-custodian-decides-a-reveal-on-a-screen-of-their-own-req-irp-006
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
.reveal-decision__missing {
	margin-top: 8px;
	color: var(--color-error-text);
}
</style>
