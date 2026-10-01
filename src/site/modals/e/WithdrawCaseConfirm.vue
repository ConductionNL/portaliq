<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	The confirmation before a resident withdraws their request
	(case-actions-withdraw-screen REQ-WDS-002), ported from
	src/portal/components/WithdrawCaseConfirm.jsx. A modal dialog: it says what
	withdrawing means, in the case app's words when it gives them, asks an
	optional reason, and sends nothing until the resident presses "Withdraw
	request". Focus moves to the heading when it opens; Escape and "Keep my
	request" both emit `cancel`, and the case screen puts focus back on its
	withdraw button.
-->
<template>
	<dialog
		ref="dialog"
		class="pq-withdraw-confirm"
		aria-labelledby="pq-withdraw-title"
		aria-describedby="pq-withdraw-body"
		data-testid="case-withdraw-confirm"
		@cancel.prevent="$emit('cancel')">
		<form method="dialog" @submit.prevent="submit">
			<h2
				id="pq-withdraw-title"
				ref="heading"
				class="utrecht-heading-3"
				tabindex="-1">
				{{ t('Withdraw this request?') }}
			</h2>
			<p id="pq-withdraw-body" class="utrecht-paragraph">
				{{
					confirmText
					|| t(
						'If you withdraw, we stop handling your request. You cannot undo this.',
					)
				}}
			</p>
			<label for="pq-withdraw-reason" class="utrecht-form-label">
				{{ t('Why are you withdrawing? (optional)') }}
			</label>
			<textarea
				id="pq-withdraw-reason"
				v-model="reason"
				class="utrecht-textarea"
				data-testid="case-withdraw-reason" />
			<div class="pq-withdraw-confirm__buttons">
				<button
					type="submit"
					class="utrecht-button utrecht-button--primary-action"
					data-testid="case-withdraw-submit"
					:disabled="busy">
					{{ t('Withdraw request') }}
				</button>
				<button
					type="button"
					class="utrecht-button utrecht-button--secondary-action"
					data-testid="case-withdraw-cancel"
					@click="$emit('cancel')">
					{{ t('Keep my request') }}
				</button>
			</div>
		</form>
	</dialog>
</template>

<script>
export default {
	name: 'WithdrawCaseConfirm',

	props: {
		/** The translator `t(key, vars)`. */
		t: { type: Function, required: true },
		/** The case app's own words about withdrawing, if any. */
		confirmText: { type: String, default: '' },
		/** Whether the withdrawal is being sent. */
		busy: { type: Boolean, default: false },
	},

	emits: ['confirm', 'cancel'],

	data() {
		return { reason: '' }
	},

	/**
	 * Open as a modal and put focus on the heading.
	 *
	 * @return {void}
	 *
	 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-citizen-must-be-able-to-withdraw-a-request-req-srp-043
	 */
	mounted() {
		const dialog = this.$refs.dialog
		if (typeof dialog?.showModal === 'function') {
			dialog.showModal()
		} else {
			dialog?.setAttribute('open', '')
		}
		this.$refs.heading?.focus()
	},

	beforeUnmount() {
		if (
			this.$refs.dialog?.open
			&& typeof this.$refs.dialog.close === 'function'
		) {
			this.$refs.dialog.close()
		}
	},

	methods: {
		/**
		 * Confirm with the reason, trimmed.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-citizen-must-be-able-to-withdraw-a-request-req-srp-043
		 */
		submit() {
			this.$emit('confirm', this.reason.trim())
		},
	},
}
</script>

<style scoped>
.pq-withdraw-confirm {
	max-inline-size: min(36rem, calc(100vw - 2rem));
	padding: var(--utrecht-space-block-lg, 1.5rem);
	color: var(--utrecht-document-color, inherit);
	background: var(--utrecht-document-background-color, Canvas);
	border: var(--utrecht-border-width-sm, 1px) solid
		var(--utrecht-color-grey-80, currentcolor);
}

.pq-withdraw-confirm form > * + * {
	margin-block-start: var(--utrecht-space-block-md, 1rem);
}

.pq-withdraw-confirm textarea {
	inline-size: 100%;
}

.pq-withdraw-confirm__buttons {
	display: flex;
	flex-wrap: wrap;
	gap: var(--utrecht-space-inline-sm, 0.5rem);
}
</style>
