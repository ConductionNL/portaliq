<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	The code from an invitation letter, on "My account"
	(invitation-code-from-a-letter). An organisation that invited the person
	on paper printed a short code in the letter. The person signs in, types
	the code here, and what the organisation shares with them joins their own
	account. A wrong, an expired and a used code get one and the same
	sentence. The code goes to the same route as an invitation link.
-->
<template>
	<section
		class="pq-account__code"
		aria-labelledby="pq-account-code"
		data-testid="invitation-code">
		<h3 id="pq-account-code" class="utrecht-heading-3">
			{{ t('Code from a letter') }}
		</h3>
		<p class="utrecht-paragraph">
			{{
				t(
					'Did you get a letter with a code? Fill it in here. After that you see what is shared with you.',
				)
			}}
		</p>
		<p
			v-if="outcome"
			class="utrecht-paragraph"
			:class="outcome.role === 'alert' ? 'pq-e-error' : 'pq-e-notice'"
			:role="outcome.role"
			data-testid="invitation-code-outcome">
			{{ t(outcome.text) }}
		</p>
		<form class="pq-account__add" @submit.prevent="submit">
			<label for="pq-account-code-input" class="utrecht-form-label">
				{{ t('Code') }}
			</label>
			<input
				id="pq-account-code-input"
				v-model="code"
				class="utrecht-textbox"
				type="text"
				autocomplete="off"
				autocapitalize="characters"
				spellcheck="false"
				maxlength="20"
				required
				data-testid="invitation-code-input" />
			<button
				type="submit"
				class="utrecht-button utrecht-button--secondary-action"
				:disabled="busy"
				data-testid="invitation-code-submit">
				{{ t('Use the code') }}
			</button>
		</form>
	</section>
</template>

<script>
import { codeOutcome } from '../../../shared/claimInvitation.js'

export default {
	name: 'InvitationCodeForm',

	props: {
		/** The portal API adapter (`claimInvitation(secret)`). */
		api: {
			type: Object,
			required: true,
		},

		/** The translator `t(key, vars)`. */
		t: {
			type: Function,
			required: true,
		},

		/** An outcome to show without posting (test seam). */
		initialOutcome: {
			type: Object,
			default: null,
		},
	},

	emits: ['claimed'],

	data() {
		return { code: '', busy: false, outcome: this.initialOutcome }
	},

	methods: {
		/**
		 * Hand the typed code to the redeem route and show what came of it.
		 * A code that worked is cleared and reported to the page. The shell
		 * then reads the account again while this form stays on screen.
		 *
		 * @return {Promise<void>} Resolves when answered.
		 *
		 * @spec openspec/changes/invitation-code-from-a-letter/specs/portal-identity-space/spec.md
		 */
		async submit() {
			if (this.busy || this.code.trim() === '') {
				return
			}
			this.busy = true
			this.outcome = null
			const answer = await this.api.claimInvitation(this.code.trim())
			this.busy = false
			this.outcome = codeOutcome(answer)
			if (answer && answer.ok) {
				this.code = ''
				this.$emit('claimed')
			}
		},
	},
}
</script>

<style scoped>
.pq-account__code > * + * {
	margin-block-start: var(--utrecht-space-block-sm, 0.5rem);
}

.pq-e-error {
	color: var(
		--utrecht-feedback-danger-color,
		var(--nldesign-color-error, currentcolor)
	);
	font-weight: var(--utrecht-typography-weight-scale-bold-font-weight, bold);
}

.pq-e-notice {
	font-weight: var(--utrecht-typography-weight-scale-bold-font-weight, bold);
}
</style>
