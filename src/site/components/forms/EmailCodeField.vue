<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	An e-mail field whose address must be checked with a code first
	(resident-identity-in-forms, board FormulierVelden). The resident types the
	address, asks for a code, types the six digits and gets the proof the form
	sends along. Changing the address afterwards drops the proof. A new code can
	be asked after a minute.
-->
<template>
	<div class="pq-email-code" :data-testid="testid">
		<input
			:id="id"
			class="utrecht-textbox"
			type="email"
			autocomplete="email"
			:aria-labelledby="`${id}-label`"
			:value="modelValue"
			:aria-invalid="invalid ? 'true' : 'false'"
			:data-testid="`${testid}-address`"
			@input="addressChanged($event.target.value)" />
		<p
			v-if="state === 'verified'"
			class="utrecht-paragraph"
			role="status"
			data-testid="email-code-verified">
			{{ words.emailVerified }}
		</p>
		<template v-else>
			<button
				v-if="state === 'idle'"
				type="button"
				class="utrecht-button utrecht-button--secondary-action"
				:disabled="busy"
				data-testid="email-code-send"
				@click="send">
				{{ words.emailSend }}
			</button>
			<template v-else>
				<p class="utrecht-paragraph" role="status" data-testid="email-code-sent">
					{{ words.emailSent.replace('{email}', modelValue) }}
				</p>
				<label class="utrecht-form-label" :for="`${id}-code`">
					{{ words.emailCodeLabel }}
				</label>
				<input
					:id="`${id}-code`"
					v-model="code"
					class="utrecht-textbox"
					type="text"
					inputmode="numeric"
					autocomplete="one-time-code"
					maxlength="6"
					data-testid="email-code-input" />
				<div class="pq-email-code__actions">
					<button
						type="button"
						class="utrecht-button utrecht-button--primary-action"
						:disabled="busy || code.trim() === ''"
						data-testid="email-code-check"
						@click="check">
						{{ words.emailCheck }}
					</button>
					<button
						type="button"
						class="utrecht-link utrecht-link--button pq-email-code__resend"
						:disabled="busy || !canResend"
						data-testid="email-code-resend"
						@click="send">
						{{ words.emailResend }}
					</button>
				</div>
			</template>
		</template>
		<p
			v-if="problem !== ''"
			class="utrecht-paragraph pq-email-code__error"
			role="alert"
			data-testid="email-code-problem">
			{{ problem }}
		</p>
	</div>
</template>

<script>
import { adoptSessionToken } from '../../lib/authApi.js'
import { checkEmailCode, requestEmailCode } from '../../lib/intakeApi.js'
import { pageLocale } from '../../pages/inbox/translate.js'
import { emailCodeProblem, identityWords } from './identityWords.js'

/**
 * @spec openspec/changes/resident-identity-in-forms/tasks.md#t03
 */
export default {
	name: 'EmailCodeField',

	props: {
		/** The address. */
		modelValue: { type: String, default: '' },
		/** The portal API base. */
		base: { type: String, default: '' },
		/** The form's binding route. */
		route: { type: String, default: '' },
		/** The portal's slug. */
		portal: { type: String, default: '' },
		/** The id of the address input. */
		id: { type: String, default: 'email-code-field' },
		/** Whether the field shows an error. */
		invalid: { type: Boolean, default: false },
		/** The test id of the field. */
		testid: { type: String, default: 'email-code-field' },
		/** The reader's locale; the page language when empty. */
		locale: { type: String, default: '' },
		/** The address this field starts out verified for (test seam). */
		verifiedFor: { type: String, default: '' },
	},

	emits: ['update:modelValue', 'verified'],

	data() {
		return {
			state: this.verifiedFor !== '' && this.verifiedFor === this.modelValue ? 'verified' : 'idle',
			code: '',
			busy: false,
			problem: '',
			canResend: false,
			timer: null,
		}
	},

	computed: {
		/**
		 * @return {Record<string, string>} The words in the reader's language.
		 * @spec openspec/changes/resident-identity-in-forms/tasks.md#t03
		 */
		words() {
			return identityWords(pageLocale(this.locale))
		},
	},

	beforeUnmount() {
		clearTimeout(this.timer)
	},

	methods: {
		/**
		 * The address changed: a proof for the old one no longer counts.
		 *
		 * @param {string} value The new address.
		 * @return {void}
		 * @spec openspec/changes/resident-identity-in-forms/tasks.md#t03
		 */
		addressChanged(value) {
			if (this.state !== 'idle') {
				this.$emit('verified', { address: this.modelValue, proof: '' })
			}
			clearTimeout(this.timer)
			this.state = 'idle'
			this.code = ''
			this.problem = ''
			this.$emit('update:modelValue', value)
		},

		/**
		 * Ask for a code. A new one can be asked after a minute.
		 *
		 * @return {Promise<void>} Resolves when answered.
		 * @spec openspec/changes/resident-identity-in-forms/tasks.md#t03
		 */
		async send() {
			this.problem = ''
			this.busy = true
			const answer = await requestEmailCode(
				this.base,
				this.route,
				this.modelValue.trim(),
				this.portal,
				adoptSessionToken(),
			)
			this.busy = false
			if (!answer.ok) {
				this.problem = emailCodeProblem(this.words, answer.error)
				return
			}
			this.state = 'sent'
			this.code = ''
			this.canResend = false
			clearTimeout(this.timer)
			this.timer = setTimeout(() => {
				this.canResend = true
			}, (answer.resendAfter || 60) * 1000)
		},

		/**
		 * Check the typed code and hand the proof to the form.
		 *
		 * @return {Promise<void>} Resolves when answered.
		 * @spec openspec/changes/resident-identity-in-forms/tasks.md#t03
		 */
		async check() {
			this.problem = ''
			this.busy = true
			const answer = await checkEmailCode(
				this.base,
				this.route,
				this.modelValue.trim(),
				this.code.trim(),
				this.portal,
				adoptSessionToken(),
			)
			this.busy = false
			if (!answer.ok) {
				this.problem = emailCodeProblem(this.words, answer.error)
				return
			}
			clearTimeout(this.timer)
			this.state = 'verified'
			this.$emit('verified', { address: this.modelValue.trim().toLowerCase(), proof: answer.proof })
		},
	},
}
</script>

<style scoped>
.pq-email-code > * + * {
	margin-block-start: var(--utrecht-space-block-sm, 0.5rem);
}

.pq-email-code input {
	inline-size: 100%;
	max-inline-size: 28rem;
}

.pq-email-code__actions {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--utrecht-space-inline-md, 1rem);
}

.pq-email-code__resend {
	background: none;
	border: 0;
	padding: 0;
	cursor: pointer;
	text-decoration: underline;
}

.pq-email-code__error {
	color: var(--utrecht-feedback-danger-color, currentcolor);
	font-weight: var(--utrecht-typography-weight-scale-bold-font-weight, bold);
}
</style>
