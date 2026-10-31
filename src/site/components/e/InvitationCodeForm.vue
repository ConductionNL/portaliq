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
		<h2 id="pq-account-code" class="utrecht-heading-4">
			{{ t('Code from a letter') }}
		</h2>
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
		 * then shows the sentence and reads the account again, which
		 * remounts this page.
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
/* The code from a letter stands apart on the grey surface. */
.pq-account__code {
	background: var(--nldesign-color-surface, #f4f6f9);
	border-radius: var(--nldesign-website-border-radius-large, 6px);
	padding: var(--utrecht-space-block-lg, 1.25rem)
		var(--utrecht-space-inline-lg, 1.5rem);
}

.pq-account__code > * + * {
	margin-block-start: var(--utrecht-space-block-sm, 0.5rem);
}

.pq-account__code .utrecht-heading-4 {
	margin-block: 0;
}

.pq-account__add {
	align-items: end;
	display: flex;
	flex-wrap: wrap;
	gap: var(--utrecht-space-inline-sm, 0.5rem);
}

.pq-account__add .utrecht-form-label {
	flex-basis: 100%;
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
