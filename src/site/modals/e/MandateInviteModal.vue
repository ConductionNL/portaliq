<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	"Iemand machtigen" (site-mandates-the-represented-manage T4, board
	Machtigen). An e-mail address, what the person may arrange (all cases or
	chosen kinds of case), and an optional end date as day, month and year.
	Nobody is looked up: the server mails an invitation, and the mandate counts
	only once the person signs in and accepts. A refusal is named in words and
	what was typed stays. Escape and "Cancel" emit `cancel`.
-->
<template>
	<dialog
		ref="dialog"
		class="pq-mandate-invite"
		aria-labelledby="pq-mandate-invite-title"
		data-testid="mandate-invite"
		@cancel.prevent="$emit('cancel')">
		<form method="dialog" novalidate @submit.prevent="submit">
			<h2
				id="pq-mandate-invite-title"
				ref="heading"
				class="utrecht-heading-3"
				tabindex="-1">
				{{ t('Authorise someone') }}
			</h2>
			<p class="utrecht-paragraph">
				{{
					t(
						'We send an invitation by e-mail. The mandate counts only once this person signs in and accepts the invitation.',
					)
				}}
			</p>
			<div class="utrecht-form-field">
				<label for="pq-mandate-email" class="utrecht-form-label">
					{{ t('E-mail address') }}
				</label>
				<input
					id="pq-mandate-email"
					v-model="email"
					class="utrecht-textbox"
					type="email"
					autocomplete="off"
					:aria-invalid="problem === 'bad_email' ? 'true' : null"
					data-testid="mandate-invite-email" />
			</div>
			<fieldset class="utrecht-form-fieldset">
				<legend class="utrecht-form-fieldset__legend">
					{{ t('What may this person arrange?') }}
				</legend>
				<label class="pq-mandate-invite__choice">
					<input
						v-model="scope"
						type="radio"
						name="pq-mandate-scope"
						value="all"
						data-testid="mandate-invite-all" />
					{{ t('All cases') }}
				</label>
				<label v-if="caseTypes.length > 0" class="pq-mandate-invite__choice">
					<input
						v-model="scope"
						type="radio"
						name="pq-mandate-scope"
						value="some"
						data-testid="mandate-invite-some" />
					{{ t('Only these kinds of case') }}
				</label>
				<div v-if="scope === 'some'" class="pq-mandate-invite__types">
					<label
						v-for="type in caseTypes"
						:key="type.id"
						class="pq-mandate-invite__choice">
						<input
							v-model="chosen"
							type="checkbox"
							:value="type.id"
							data-testid="mandate-invite-type" />
						{{ type.title }}
					</label>
				</div>
			</fieldset>
			<fieldset
				class="utrecht-form-fieldset"
				aria-describedby="pq-mandate-end-hint">
				<legend class="utrecht-form-fieldset__legend">
					{{ t('Until when?') }} {{ t('(optional)') }}
				</legend>
				<p id="pq-mandate-end-hint" class="utrecht-paragraph">
					{{ t('For example 31 12 2026') }}
				</p>
				<div class="pq-mandate-invite__date">
					<label v-for="part in DATE_PARTS" :key="part.key">
						<span class="utrecht-form-label">{{ t(part.label) }}</span>
						<input
							v-model="end[part.key]"
							class="utrecht-textbox"
							inputmode="numeric"
							:maxlength="part.size"
							:size="part.size"
							:aria-invalid="
								problem === 'bad_end_date' ? 'true' : null
							"
							:data-testid="`mandate-invite-${part.key}`" />
					</label>
				</div>
			</fieldset>
			<p
				v-if="problem !== ''"
				class="utrecht-paragraph pq-mandate-invite__error"
				role="alert"
				data-testid="mandate-invite-problem">
				{{ t(problemText(problem)) }}
			</p>
			<div class="pq-mandate-invite__buttons">
				<button
					type="button"
					class="utrecht-button utrecht-button--secondary-action"
					data-testid="mandate-invite-cancel"
					@click="$emit('cancel')">
					{{ t('Cancel') }}
				</button>
				<button
					type="submit"
					class="utrecht-button utrecht-button--primary-action"
					data-testid="mandate-invite-submit"
					:disabled="busy">
					<svg
						class="pq-button-icon"
						viewBox="0 0 24 24"
						aria-hidden="true"
						focusable="false"><path d="M22 5.5H9C7.9 5.5 7 6.4 7 7.5V16.5C7 17.61 7.9 18.5 9 18.5H22C23.11 18.5 24 17.61 24 16.5V7.5C24 6.4 23.11 5.5 22 5.5M22 16.5H9V9.17L15.5 12.5L22 9.17V16.5M15.5 10.81L9 7.5H22L15.5 10.81M5 16.5C5 16.67 5.03 16.83 5.05 17H1C.448 17 0 16.55 0 16S.448 15 1 15H5V16.5M3 7H5.05C5.03 7.17 5 7.33 5 7.5V9H3C2.45 9 2 8.55 2 8S2.45 7 3 7M1 12C1 11.45 1.45 11 2 11H5V13H2C1.45 13 1 12.55 1 12Z" /></svg>
					{{ t('Send the invitation') }}
				</button>
			</div>
		</form>
	</dialog>
</template>

<script>
import { mandatesApi } from '../../../shared/areaApi.js'
import { endDayOf, mandateProblemText } from '../../components/e/mandates.js'

const DATE_PARTS = [
	{ key: 'day', label: 'Day', size: 2 },
	{ key: 'month', label: 'Month', size: 2 },
	{ key: 'year', label: 'Year', size: 4 },
]

export default {
	name: 'MandateInviteModal',

	props: {
		/** The portal API adapter (`createPortalApi` shape). */
		api: { type: Object, required: true },
		/** The translator `t(key, vars)`. */
		t: { type: Function, required: true },
		/** The case types a mandate may be narrowed to: `{id, title}`. */
		caseTypes: { type: Array, default: () => [] },
	},

	emits: ['sent', 'cancel'],

	data() {
		return {
			DATE_PARTS,
			email: '',
			scope: 'all',
			chosen: [],
			end: { day: '', month: '', year: '' },
			problem: '',
			busy: false,
		}
	},

	/**
	 * Open as a modal and put focus on the heading.
	 *
	 * @return {void}
	 *
	 * @spec openspec/changes/site-mandates-the-represented-manage/tasks.md#t4
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

	/**
	 * Close the dialog with the component.
	 *
	 * @return {void}
	 *
	 * @spec openspec/changes/site-mandates-the-represented-manage/tasks.md#t4
	 */
	beforeUnmount() {
		if (
			this.$refs.dialog?.open
			&& typeof this.$refs.dialog.close === 'function'
		) {
			this.$refs.dialog.close()
		}
	},

	methods: {
		problemText: mandateProblemText,

		/**
		 * Send the invitation. A refusal is shown and the dialog stays open.
		 *
		 * @return {Promise<void>} Resolves when answered.
		 *
		 * @spec openspec/changes/site-mandates-the-represented-manage/tasks.md#t4
		 */
		async submit() {
			this.problem = ''
			const expiresAt = endDayOf(this.end)
			if (expiresAt === null) {
				this.problem = 'bad_end_date'
				return
			}
			if (this.scope === 'some' && this.chosen.length === 0) {
				this.problem = 'no_case_type'
				return
			}
			this.busy = true
			const answer = await mandatesApi(this.api).invite({
				email: this.email.trim(),
				caseTypes: this.scope === 'some' ? [...this.chosen] : [],
				expiresAt,
			})
			this.busy = false
			if (!answer.ok) {
				this.problem = answer.error || 'failed'
				return
			}
			this.$emit('sent')
		},
	},
}
</script>

<style scoped>
/* The dialog as the Machtigen board draws it: 640px on a dimmed page, no
   frame but rounded corners and a shadow, the actions at the end of the
   last line. */
.pq-mandate-invite {
	background: var(--utrecht-document-background-color, Canvas);
	border: 0;
	border-radius: var(--nldesign-website-border-radius-large, 8px);
	box-shadow: 0 8px 32px rgb(0 0 0 / 20%);
	box-sizing: border-box;
	color: var(--utrecht-document-color, inherit);
	inline-size: min(40rem, calc(100vw - 2rem));
	max-inline-size: none;
	padding: 1.75rem;
}

.pq-mandate-invite::backdrop {
	background: rgb(0 0 0 / 40%);
}

.pq-mandate-invite form > * + * {
	margin-block-start: var(--utrecht-space-block-md, 1rem);
}

.pq-mandate-invite input[type='email'] {
	inline-size: 100%;
}

.pq-mandate-invite__choice {
	display: flex;
	align-items: center;
	gap: var(--utrecht-space-inline-sm, 0.5rem);
}

.pq-mandate-invite__types {
	padding-inline-start: var(--utrecht-space-inline-lg, 1.5rem);
}

.pq-mandate-invite__date {
	display: flex;
	flex-wrap: wrap;
	gap: var(--utrecht-space-inline-md, 1rem);
}

.pq-mandate-invite__date label {
	display: flex;
	flex-direction: column;
}

.pq-mandate-invite__error {
	color: var(--utrecht-feedback-danger-color, currentcolor);
	font-weight: var(--utrecht-typography-weight-scale-bold-font-weight, bold);
}

.pq-mandate-invite__buttons {
	display: flex;
	flex-wrap: wrap;
	gap: 0.75rem;
	justify-content: flex-end;
}
</style>
