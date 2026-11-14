<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	"Voor medewerkers" (resident-identity-in-forms REQ-RIF-005, board
	FormulierInloggen). Before an employee has signed in it offers "Inloggen met
	werkaccount"; after, it asks whom the form is for: a resident by BSN, a
	company by KvK number, or the employee under their own name with the
	resident noted as applicant. The lookup prefills the form; the server
	checks the desk group again on every call.
-->
<template>
	<section class="pq-desk-applicant" data-testid="desk-applicant" :aria-labelledby="`${id}-heading`">
		<h2 :id="`${id}-heading`" class="utrecht-heading-3">
			{{ words.deskHeading }}
		</h2>

		<template v-if="!staff">
			<p class="utrecht-paragraph">
				{{ words.deskIntro }}
			</p>
			<a
				class="utrecht-button-link utrecht-button-link--html-a utrecht-button-link--secondary-action"
				:href="signInHref"
				data-testid="desk-sign-in">
				{{ words.deskSignIn }}
			</a>
		</template>

		<template v-else>
			<p class="utrecht-paragraph" data-testid="desk-signed-in">
				{{ words.deskSignedInAs.replace('{name}', staff.displayName || staff.uid || '') }}
			</p>

			<div v-if="chosen" data-testid="desk-chosen">
				<p class="utrecht-paragraph" role="status">
					{{ words.deskFillingFor.replace('{applicant}', chosenText) }}
				</p>
				<p v-if="problem" class="utrecht-paragraph" role="status" data-testid="desk-problem">
					{{ problem }}
				</p>
				<button
					type="button"
					class="utrecht-button utrecht-button--subtle"
					data-testid="desk-change"
					@click="change">
					{{ words.deskChange }}
				</button>
			</div>

			<!-- Not a form of its own: it sits inside the intake form, and forms do not nest. -->
			<div v-else data-testid="desk-choice">
				<fieldset class="utrecht-form-fieldset">
					<legend class="utrecht-form-fieldset__legend">
						{{ words.deskContinueAs }}
					</legend>
					<div v-for="option in options" :key="option.kind" class="utrecht-form-field utrecht-form-field--radio">
						<input
							:id="`${id}-${option.kind}`"
							v-model="kind"
							class="utrecht-radio-button"
							type="radio"
							:name="`${id}-kind`"
							:value="option.kind"
							:data-testid="`desk-kind-${option.kind}`">
						<label class="utrecht-form-label utrecht-form-label--radio" :for="`${id}-${option.kind}`">
							{{ option.label }}
						</label>
					</div>
				</fieldset>
				<label class="utrecht-form-label" :for="`${id}-value`">
					{{ valueLabel }}
				</label>
				<input
					:id="`${id}-value`"
					v-model="value"
					class="utrecht-textbox"
					type="text"
					:inputmode="kind === 'named' ? 'text' : 'numeric'"
					:autocomplete="kind === 'named' ? 'name' : 'off'"
					:aria-invalid="problem ? 'true' : 'false'"
					:aria-describedby="problem ? `${id}-problem` : undefined"
					data-testid="desk-value"
					@keydown.enter.prevent="choose">
				<p
					v-if="problem"
					:id="`${id}-problem`"
					class="utrecht-form-field-error-message"
					data-testid="desk-problem">
					{{ problem }}
				</p>
				<button
					type="button"
					class="utrecht-button utrecht-button--primary-action"
					:disabled="busy"
					data-testid="desk-continue"
					@click="choose">
					{{ words.deskFetch }}
				</button>
			</div>
		</template>
	</section>
</template>

<script>
import { pageLocale } from '../../pages/inbox/translate.js'
import { deskProblem, identityWords } from './identityWords.js'

import '@utrecht/button-link-css/dist/index.css'

/**
 * @spec openspec/changes/resident-identity-in-forms/tasks.md#T06
 */
export default {
	name: 'DeskApplicant',

	props: {
		/** The employee of the desk session, `{uid, displayName}`; null before they signed in. */
		staff: { type: Object, default: null },
		/** Where "Inloggen met werkaccount" starts. */
		signInHref: { type: String, default: '' },
		/** Looks the applicant up: `(kind, value) => Promise<{ok, error, applicant, available, prefill, reason}>`. */
		lookUp: { type: Function, default: null },
		/** The id the section's elements are named from. */
		id: { type: String, default: 'desk-applicant' },
		/** The reader's locale; the page language when empty. */
		locale: { type: String, default: '' },
	},

	emits: ['applicant'],

	data() {
		return {
			kind: 'bsn',
			value: '',
			busy: false,
			problem: '',
			chosen: null,
		}
	},

	computed: {
		/**
		 * @return {Record<string, string>} The words in the reader's language.
		 * @spec openspec/changes/resident-identity-in-forms/tasks.md#T06
		 */
		words() {
			return identityWords(pageLocale(this.locale))
		},

		/**
		 * @return {Array<{kind: string, label: string}>} The three ways to continue.
		 * @spec openspec/changes/resident-identity-in-forms/tasks.md#T06
		 */
		options() {
			return [
				{ kind: 'bsn', label: this.words.deskKindBsn },
				{ kind: 'kvk', label: this.words.deskKindKvk },
				{ kind: 'named', label: this.words.deskKindNamed },
			]
		},

		/**
		 * @return {string} The label of the input for the chosen kind.
		 * @spec openspec/changes/resident-identity-in-forms/tasks.md#T06
		 */
		valueLabel() {
			return {
				bsn: this.words.deskValueBsn,
				kvk: this.words.deskValueKvk,
				named: this.words.deskValueNamed,
			}[this.kind]
		},

		/**
		 * @return {string} The chosen applicant as one line: the name or the number.
		 * @spec openspec/changes/resident-identity-in-forms/tasks.md#T06
		 */
		chosenText() {
			if (!this.chosen) {
				return ''
			}
			return this.chosen.name || this.chosen.applicant.value
		},
	},

	methods: {
		/**
		 * Look the applicant up and hand them to the form. A number without
		 * details still names the applicant; the employee fills in the rest.
		 *
		 * @return {Promise<void>} Resolves when answered.
		 * @spec openspec/changes/resident-identity-in-forms/tasks.md#T06
		 */
		async choose() {
			this.problem = ''
			if (this.value.trim() === '' || typeof this.lookUp !== 'function') {
				this.problem = this.words.deskInvalid
				return
			}
			this.busy = true
			const outcome = await this.lookUp(this.kind, this.value.trim())
			this.busy = false
			if (!outcome.ok || !outcome.applicant) {
				this.problem = deskProblem(this.words, outcome.error)
				return
			}
			if (!outcome.available) {
				this.problem = deskProblem(this.words, outcome.reason)
			}
			this.chosen = { applicant: outcome.applicant, name: String(outcome.prefill?.applicantName || '') }
			this.$emit('applicant', { applicant: outcome.applicant, prefill: outcome.prefill || {} })
		},

		/**
		 * Start over with another applicant.
		 *
		 * @return {void}
		 * @spec openspec/changes/resident-identity-in-forms/tasks.md#T06
		 */
		change() {
			this.chosen = null
			this.problem = ''
			this.value = ''
			this.$emit('applicant', { applicant: null, prefill: {} })
		},
	},
}
</script>

<style scoped>
.pq-desk-applicant {
	border: 1px solid var(--utrecht-color-grey-80, var(--color-border));
	border-radius: var(--border-radius-large, 8px);
	padding: var(--utrecht-space-block-md, 16px);
	margin-block-end: var(--utrecht-space-block-lg, 24px);
}
</style>
