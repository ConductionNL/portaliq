<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	"Voor medewerkers" on a form that allows employees (resident-identity-in-forms
	REQ-RIF-005, board FormulierInloggen). The employee chooses whom they fill
	the form in for, types the BSN, the KvK number or the applicant's name, and
	signs in with their work account. The parent does the signing in.
-->
<template>
	<section
		class="pq-staff-fill"
		aria-labelledby="pq-staff-fill-heading"
		data-testid="staff-fill">
		<h2 id="pq-staff-fill-heading" class="utrecht-heading-3">
			{{ words.staffHeading }}
		</h2>
		<p class="utrecht-paragraph">
			{{ words.staffIntro }}
		</p>
		<fieldset class="utrecht-form-fieldset pq-staff-fill__kinds">
			<legend class="utrecht-form-fieldset__legend">
				{{ words.staffContinueAs }}
			</legend>
			<div
				v-for="option in options"
				:key="option.kind"
				class="pq-staff-fill__kind">
				<input
					:id="`pq-staff-fill-${option.kind}`"
					v-model="kind"
					class="utrecht-radio-button"
					type="radio"
					name="pq-staff-fill-kind"
					:value="option.kind"
					:aria-describedby="`pq-staff-fill-${option.kind}-hint`"
					:data-testid="`staff-fill-kind-${option.kind}`" />
				<label
					class="utrecht-form-label"
					:for="`pq-staff-fill-${option.kind}`">
					{{ option.label }}
				</label>
				<p
					:id="`pq-staff-fill-${option.kind}-hint`"
					class="utrecht-paragraph pq-staff-fill__hint">
					{{ option.hint }}
				</p>
			</div>
		</fieldset>
		<label class="utrecht-form-label" for="pq-staff-fill-value">
			{{ valueLabel }}
		</label>
		<input
			id="pq-staff-fill-value"
			v-model="value"
			class="utrecht-textbox"
			type="text"
			:inputmode="kind === 'named' ? 'text' : 'numeric'"
			autocomplete="off"
			:aria-invalid="problem !== '' ? 'true' : 'false'"
			:aria-describedby="problem !== '' ? 'pq-staff-fill-problem' : undefined"
			data-testid="staff-fill-value" />
		<p
			v-if="problem !== ''"
			id="pq-staff-fill-problem"
			class="utrecht-paragraph pq-staff-fill__problem"
			role="alert"
			data-testid="staff-fill-problem">
			{{ problem }}
		</p>
		<button
			type="button"
			class="utrecht-button utrecht-button--secondary-action"
			:disabled="busy"
			data-testid="staff-fill-sign-in"
			@click="start">
			{{ words.staffSignIn }}
		</button>
	</section>
</template>

<script>
import { identityWords } from './identityWords.js'

/**
 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-an-employee-can-fill-in-a-form-for-a-resident-or-company-req-rif-005
 */
export default {
	name: 'StaffFillChoice',

	props: {
		/** The reader's locale; Dutch when empty. */
		locale: { type: String, default: '' },
		/** Whether the parent is signing in or starting the form. */
		busy: { type: Boolean, default: false },
		/** What went wrong last time, as the parent words it. */
		failure: { type: String, default: '' },
	},

	emits: ['start'],

	data() {
		return { kind: 'bsn', value: '', empty: false }
	},

	computed: {
		/**
		 * The words in the reader's language.
		 *
		 * @return {Record<string, string>} The words.
		 *
		 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-an-employee-can-fill-in-a-form-for-a-resident-or-company-req-rif-005
		 */
		words() {
			return identityWords(this.locale)
		},

		/**
		 * The three ways on the board offers, with their hints.
		 *
		 * @return {Array<{kind: string, label: string, hint: string}>} The options.
		 *
		 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-an-employee-can-fill-in-a-form-for-a-resident-or-company-req-rif-005
		 */
		options() {
			return [
				{
					kind: 'bsn',
					label: this.words.staffResident,
					hint: this.words.staffResidentHint,
				},
				{
					kind: 'kvk',
					label: this.words.staffCompany,
					hint: this.words.staffCompanyHint,
				},
				{
					kind: 'named',
					label: this.words.staffNamed,
					hint: this.words.staffNamedHint,
				},
			]
		},

		/**
		 * The label of the input for the chosen way on.
		 *
		 * @return {string} The label.
		 *
		 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-an-employee-can-fill-in-a-form-for-a-resident-or-company-req-rif-005
		 */
		valueLabel() {
			return {
				bsn: this.words.staffBsnLabel,
				kvk: this.words.staffKvkLabel,
				named: this.words.staffNameLabel,
			}[this.kind]
		},

		/**
		 * What to tell the employee under the input, or ''.
		 *
		 * @return {string} The sentence.
		 *
		 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-an-employee-can-fill-in-a-form-for-a-resident-or-company-req-rif-005
		 */
		problem() {
			return this.empty ? this.words.staffEmpty : this.failure
		},
	},

	methods: {
		/**
		 * Hand the chosen applicant to the parent, which signs the employee in.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-an-employee-can-fill-in-a-form-for-a-resident-or-company-req-rif-005
		 */
		start() {
			const value = this.value.trim()
			this.empty = value === ''
			if (this.empty) {
				return
			}

			this.$emit('start', { kind: this.kind, value })
		},
	},
}
</script>

<style scoped>
.pq-staff-fill {
	border-block-start: 1px solid var(--utrecht-color-grey-80, currentcolor);
	margin-block-start: var(--utrecht-space-block-lg, 1.5rem);
	padding-block-start: var(--utrecht-space-block-lg, 1.5rem);
}

.pq-staff-fill__kind {
	margin-block-end: var(--utrecht-space-block-sm, 0.5rem);
}

.pq-staff-fill input[type='text'] {
	inline-size: 100%;
	max-inline-size: 28rem;
}

.pq-staff-fill__hint {
	margin-block: 0;
	margin-inline-start: 1.75rem;
}

.pq-staff-fill__problem {
	color: var(--utrecht-feedback-danger-color, currentcolor);
	font-weight: var(--utrecht-typography-weight-scale-bold-font-weight, bold);
}
</style>
