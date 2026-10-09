<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<section
		v-if="confirmed !== null"
		class="pq-schema-form__confirmation"
		data-testid="schema-form-confirmation">
		<h2
			ref="confirmationHeading"
			class="utrecht-heading-2"
			tabindex="-1"
			data-testid="schema-form-confirmation-heading">
			{{ action.confirmation.title }}
		</h2>
		<p
			v-if="confirmed.body !== ''"
			class="utrecht-paragraph"
			role="status"
			data-testid="schema-form-confirmation-body">
			{{ confirmed.body }}
		</p>
		<div
			v-if="confirmed.summary"
			class="pq-schema-form__summary"
			data-testid="schema-form-confirmation-summary">
			<p v-if="action.summary.label" class="pq-schema-form__summary-label">
				{{ action.summary.label }}
			</p>
			<p class="utrecht-paragraph pq-schema-form__summary-text">
				{{ confirmed.summary }}
			</p>
		</div>
		<p
			v-if="confirmed.next !== ''"
			class="utrecht-paragraph"
			data-testid="schema-form-confirmation-next">
			{{ confirmed.next }}
		</p>
	</section>
	<form
		v-else
		class="pq-schema-form"
		:aria-label="action.label || action.id"
		data-testid="schema-form"
		novalidate
		@submit.prevent="onSubmit">
		<template v-if="hasSteps">
			<FormProgress
				:steps="flow"
				:current="stepIndex"
				:idBase="`f-${action.id}-progress`"
				:label="translate('Progress')"
				:shortPattern="translate('Step {n} of {m}')"
				:showLabel="translate('Show all steps')"
				:hideLabel="translate('Hide the steps')"
				:doneLabel="translate('Done')"
				:currentLabel="translate('Current step')"
				:todoLabel="translate('To do')" />
			<h2
				ref="stepHeading"
				class="utrecht-heading-2 pq-schema-form__step-heading"
				tabindex="-1"
				data-testid="schema-form-step-heading">
				{{ stepHeadingText }}
			</h2>
			<p
				v-if="currentStep.description"
				class="utrecht-paragraph"
				data-testid="schema-form-step-description">
				{{ currentStep.description }}
			</p>
		</template>

		<p
			v-if="explainOptional"
			class="utrecht-paragraph pq-schema-form__intro"
			data-testid="schema-form-optional-note">
			{{ translate('A field without "optional" must be filled in.') }}
		</p>

		<ErrorSummary
			ref="summary"
			:entries="summary"
			:idBase="`f-${action.id}-summary`"
			:heading="translate('Something is still missing')"
			:intro="translate('Fill this in. Then you can continue.')"
			:titlePrefix="translate('Error: ')" />

		<p
			v-if="error !== ''"
			class="utrecht-paragraph pq-schema-form__error"
			role="alert"
			data-testid="schema-form-error">
			{{ error }}
		</p>

		<ReviewList
			v-if="onReview"
			:sections="reviewSections"
			:editLabel="translate('Change')"
			:editPattern="translate('Change step {n}')"
			:emptyLabel="translate('Not answered')"
			@edit="editStep" />

		<template v-else>
			<SchemaField
				v-for="field in shownFields"
				:id="inputId(field)"
				:key="field"
				v-model="values[field]"
				:field="field"
				:label="labelOf(field)"
				:config="configOf(field)"
				:input="inputOf(field)"
				:options="options[field] || []"
				:files="files[field] || []"
				:fileKey="fileKey"
				:error="errors[field] || ''"
				:t="t"
				@pick="(picked) => pick(field, picked)" />
		</template>

		<!-- The answers in one sentence, as they are given
		     (action-summary-sentence). Polite: it changes while the resident
		     answers, and must not interrupt them. -->
		<div
			v-if="showsSummary"
			class="pq-schema-form__summary"
			aria-live="polite"
			data-testid="schema-form-summary">
			<p v-if="action.summary.label" class="pq-schema-form__summary-label">
				{{ action.summary.label }}
			</p>
			<p class="utrecht-paragraph pq-schema-form__summary-text">
				{{ summaryText }}
			</p>
		</div>

		<div class="pq-schema-form__buttons">
			<button
				v-if="hasSteps && stepIndex > 0"
				type="button"
				class="utrecht-button utrecht-button--secondary-action"
				:disabled="submitting"
				data-testid="schema-form-previous"
				@click="previousStep">
				{{ translate('Previous step') }}
			</button>
			<button
				v-if="hasSteps && !onReview"
				type="submit"
				class="utrecht-button utrecht-button--primary-action"
				data-testid="schema-form-next">
				{{ translate('Next step') }}
			</button>
			<button
				v-else
				type="submit"
				class="utrecht-button utrecht-button--primary-action"
				:disabled="submitting"
				data-testid="schema-form-submit">
				{{ submitting ? translate('Please wait…') : submitLabel }}
			</button>
			<button
				v-if="canSaveDraft"
				type="button"
				class="utrecht-button utrecht-button--subtle"
				:disabled="submitting"
				data-testid="schema-form-save-draft"
				@click="saveDraft">
				{{ translate('Save and continue later') }}
			</button>
			<button
				v-if="pending !== null"
				type="button"
				class="utrecht-button utrecht-button--secondary-action"
				:disabled="submitting"
				data-testid="schema-form-retry"
				@click="retryFailed">
				{{ translate('Try these files again') }}
			</button>
		</div>

		<p
			class="utrecht-paragraph pq-schema-form__done"
			role="status"
			data-testid="schema-form-done">
			{{ done }}
		</p>
	</form>
</template>

<script>
import ErrorSummary from '../forms/ErrorSummary.vue'
import FormProgress from '../forms/FormProgress.vue'
import ReviewList from '../forms/ReviewList.vue'
import SchemaField from './SchemaField.vue'
import {
	oversizedFiles,
	submitWithFiles,
	uploadFiles,
} from '../../../shared/fileFieldSubmit.js'
import { explainsOptional, summaryEntries } from '../forms/fields.js'
import stepFlow from '../forms/stepFlow.js'
import {
	confirmationText,
	landingStep,
	retentionDate,
	stepHeading,
} from '../forms/steps.js'
import { summarySentence } from '../forms/summary.js'
import {
	collectionProviders,
	fieldConfig,
	fieldErrors,
	fieldInput,
	fieldLabel,
	formBody,
	formFields,
	invalidFieldErrors,
	refusalKey,
	serverFieldErrors,
	staticOptions,
	translatorOr,
	withSingleOptions,
} from './forms.js'

/**
 * A manifest-driven form (the Vue port of SchemaForm.jsx, which also replaces
 * ActionFieldsForm.jsx). It renders exactly the action's whitelisted `fields`,
 * shaped by `fieldConfigs` and `optionsProviders`: a static list, or a
 * collection fetched through the subject-scoped api, so a dropdown only offers
 * what the resident may already read. A file field is never in the create
 * body: the record is created first, then each file is uploaded into it.
 *
 * A failed check shows the error summary above the fields: its heading takes
 * focus and each line links to its field. A form that mixes required and
 * optional fields says first that a field without "niet verplicht" must be
 * filled in.
 *
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-schema-form-must-render-only-whitelisted-fields-req-srp-022
 * @spec openspec/changes/site-multi-step-forms/specs/site-forms/spec.md#requirement-a-failed-submit-must-show-an-error-summary-that-takes-focus-req-smf-002
 */
export default {
	name: 'SchemaForm',

	components: { ErrorSummary, FormProgress, ReviewList, SchemaField },

	mixins: [stepFlow],

	props: {
		/** The normalised manifest action (`create`, `update`, or an endpoint action with `fields`). */
		action: { type: Object, required: true },
		/** The portal api: `createObject`, `uploadFieldFile`, `fetchOptions`. */
		api: { type: Object, required: true },
		/** The translator `t(key, vars)`. */
		t: { type: Function, default: null },
		/**
		 * Sends the body instead of creating a record, for an endpoint action
		 * (an attached action): `(body) => Promise<{ok, object, errors?}>`.
		 */
		send: { type: Function, default: null },
		/**
		 * Values the page gives and the resident is not asked for, by field:
		 * the open record a call to action carries into the action's
		 * `recordField` (case-actions-on-the-case-page).
		 */
		preset: { type: Object, default: () => ({}) },
		/**
		 * Values the form starts from and the resident may change, by field:
		 * the row's own values on an update (site-action-forms).
		 */
		initial: { type: Object, default: () => ({}) },
	},

	emits: ['submitted'],

	data() {
		const options = staticOptions(this.action)
		return {
			values: withSingleOptions(this.action, this.emptyValues(), options),
			options,
			files: {},
			fileKey: 0,
			errors: {},
			error: '',
			done: '',
			submitting: false,
			pending: null,
			confirmed: null,
		}
	},

	computed: {
		/**
		 * The action's summary sentence from the answers so far, '' while an
		 * answer it names is missing (action-summary-sentence).
		 *
		 * @return {string}
		 *
		 * @spec openspec/changes/action-summary-sentence/specs/portal-contribution-contract/spec.md#requirement-an-action-may-sum-up-the-answers-in-one-sentence
		 */
		summaryText() {
			// A date answer reads in the page's language ("vandaag",
			// "maandag 12 oktober").
			const locale =
				(typeof document !== 'undefined' && document.documentElement?.lang)
				|| 'nl'
			return summarySentence(
				this.action?.summary || null,
				this.values,
				this.options,
				{ locale },
			)
		},

		/**
		 * Whether the sentence shows: on a one-page form, or on the review of
		 * a stepped one, once it is whole.
		 *
		 * @return {boolean}
		 *
		 * @spec openspec/changes/action-summary-sentence/specs/portal-contribution-contract/spec.md#requirement-an-action-may-sum-up-the-answers-in-one-sentence
		 */
		showsSummary() {
			return this.summaryText !== '' && (!this.hasSteps || this.onReview)
		},

		/**
		 * The retention the action declares for a saved draft, or 0 for none.
		 *
		 * @return {number} Days.
		 *
		 * @spec openspec/changes/site-multi-step-forms/specs/portal-contribution-contract/spec.md#requirement-a-draft-of-a-create-or-endpoint-action-must-stay-with-portaliq-and-the-resident-req-smf-021
		 */
		draftDays() {
			const days = this.action.draft && this.action.draft.retentionDays
			return Number.isInteger(days) && days > 0 ? days : 0
		},

		/**
		 * Whether "Opslaan en later verdergaan" shows: the action declares a
		 * draft, the form runs in steps and the api can keep one.
		 *
		 * @return {boolean} True to show the button.
		 *
		 * @spec openspec/changes/site-multi-step-forms/specs/portal-contribution-contract/spec.md#requirement-a-draft-of-a-create-or-endpoint-action-must-stay-with-portaliq-and-the-resident-req-smf-021
		 */
		canSaveDraft() {
			return (
				this.draftDays > 0
				&& this.hasSteps
				&& !this.confirmed
				&& typeof this.api.saveDraft === 'function'
			)
		},

		/**
		 * The app part of the draft's key: the action's app, else its register.
		 *
		 * @return {string} The app.
		 *
		 * @spec openspec/changes/site-multi-step-forms/specs/portal-contribution-contract/spec.md#requirement-a-draft-of-a-create-or-endpoint-action-must-stay-with-portaliq-and-the-resident-req-smf-021
		 */
		draftApp() {
			return String(this.action.appId || this.action.register || 'portal')
		},

		translate() {
			return translatorOr(this.t)
		},

		fields() {
			return formFields(this.action)
		},

		/**
		 * Whether the form explains "(niet verplicht)": only when it mixes
		 * required and optional fields.
		 *
		 * @return {boolean} True to show the sentence.
		 *
		 * @spec openspec/changes/site-multi-step-forms/specs/site-forms/spec.md#requirement-a-site-form-must-mark-the-fields-that-are-not-required-req-smf-001
		 */
		explainOptional() {
			return (
				!this.onReview
				&& explainsOptional(
					this.shownFields.map(
						(field) => this.configOf(field).required === true,
					),
				)
			)
		},

		/**
		 * The fields on screen: the step's shown fields, or every field of a
		 * one-page form.
		 *
		 * @return {string[]} The fields.
		 *
		 * @spec openspec/changes/site-multi-step-forms/specs/portal-intake-form/spec.md#requirement-a-published-form-with-steps-must-be-filled-in-one-step-at-a-time-with-visible-progress-req-smf-010
		 */
		shownFields() {
			if (!this.hasSteps) {
				// A field the action hides, or one the page already gave
				// (`preset`), is not asked; the step branch below does the same.
				return this.fields.filter(
					(field) => this.isShownField(field) && !(field in this.preset),
				)
			}
			return this.onReview
				? []
				: this.currentStep.fields.filter((field) => this.isShownField(field))
		},

		/**
		 * The steps the server sent with the action.
		 *
		 * @return {Array<object>|undefined} The steps.
		 *
		 * @spec openspec/changes/site-multi-step-forms/specs/portal-contribution-contract/spec.md#requirement-a-create-or-endpoint-action-may-run-in-steps-with-a-review-a-draft-and-a-confirmation-req-smf-020
		 */
		rawSteps() {
			return this.action.steps
		},

		/**
		 * The titles of the loose step and of an undeclared review.
		 *
		 * @return {{other: string, review: string}} The titles.
		 *
		 * @spec openspec/changes/site-multi-step-forms/specs/portal-intake-form/spec.md#requirement-a-form-with-steps-must-end-with-a-review-and-a-confirmation-req-smf-011
		 */
		stepTitles() {
			return {
				other: this.translate('Other questions'),
				review: this.translate('Check and send'),
			}
		},

		/**
		 * "Stap 2 van 4: periode en documenten".
		 *
		 * @return {string} The heading.
		 *
		 * @spec openspec/changes/site-multi-step-forms/specs/portal-intake-form/spec.md#requirement-a-published-form-with-steps-must-be-filled-in-one-step-at-a-time-with-visible-progress-req-smf-010
		 */
		stepHeadingText() {
			return stepHeading(
				this.translate('Step {n} of {m}: {title}'),
				this.stepIndex + 1,
				this.flow.length,
				this.currentStep.title,
			)
		},

		/**
		 * The review: per step with shown fields, each answer under its question.
		 *
		 * @return {Array<object>} The sections.
		 *
		 * @spec openspec/changes/site-multi-step-forms/specs/portal-intake-form/spec.md#requirement-a-form-with-steps-must-end-with-a-review-and-a-confirmation-req-smf-011
		 */
		reviewSections() {
			return this.flow
				.map((step, index) => ({
					index,
					title: step.title,
					rows: step.fields
						.filter((field) => this.isShownField(field))
						.map((field) => ({
							field,
							label: this.labelOf(field),
							value: this.answerText(field),
						})),
				}))
				.filter((section) => section.rows.length > 0)
		},

		/**
		 * The error summary's lines, in field order, each linked to its input.
		 *
		 * @return {Array<{field: string, target: string, message: string}>} The lines.
		 *
		 * @spec openspec/changes/site-multi-step-forms/specs/site-forms/spec.md#requirement-a-failed-submit-must-show-an-error-summary-that-takes-focus-req-smf-002
		 */
		summary() {
			return summaryEntries(this.fields, this.errors, (field) =>
				this.inputId(field),
			)
		},

		submitLabel() {
			return (
				this.action.submitLabel
				|| this.action.label
				|| this.translate('Save')
			)
		},
	},

	watch: {
		'action.id': function () {
			this.options = staticOptions(this.action)
			this.values = this.startValues()
			this.loadOptions()
		},
	},

	/**
	 * Load the options, then open a saved draft when the action keeps one.
	 *
	 * @spec openspec/changes/site-multi-step-forms/specs/portal-intake-form/spec.md#requirement-save-and-resume-must-sit-in-the-step-navigation-req-smf-012
	 */
	mounted() {
		this.loadOptions()
		this.resumeDraft()
	},

	methods: {
		/**
		 * One empty string per whitelisted field, or the value the page gives
		 * for it (`preset`), or the value the form starts from (`initial`).
		 *
		 * @return {Record<string, string>} The values.
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/portal-contribution-contract/spec.md#requirement-a-cta-block-may-open-a-page-or-a-site-route-for-the-open-record-with-the-record-in-its-label-req-smo-024
		 * @spec openspec/changes/site-action-forms/specs/site-forms/spec.md#requirement-an-update-row-action-that-needs-input-must-open-its-form-on-the-row
		 */
		emptyValues() {
			const values = {}
			for (const field of formFields(this.action)) {
				values[field] =
					field in this.preset ? String(this.preset[field]) : ''
				const start = this.initial[field]
				if (
					!(field in this.preset)
					&& start !== undefined
					&& start !== null
					&& typeof start !== 'object'
				) {
					values[field] = String(start)
				}
			}
			return values
		},

		/**
		 * The values a fresh form starts from: empty, except a required select
		 * with exactly one option, which starts on that option.
		 *
		 * @return {Record<string, string>} The values.
		 */
		startValues() {
			return withSingleOptions(this.action, this.emptyValues(), this.options)
		},

		/**
		 * Fetch every `collection` provider's options.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-schema-form-must-render-only-whitelisted-fields-req-srp-022
		 */
		async loadOptions() {
			const actionId = this.action.id
			await Promise.all(
				collectionProviders(this.action).map(async ([field, provider]) => {
					const fetched = await this.api.fetchOptions(provider)
					if (this.action.id === actionId) {
						this.options = {
							...this.options,
							[field]: Array.isArray(fetched) ? fetched : [],
						}
						this.values = withSingleOptions(
							this.action,
							this.values,
							this.options,
						)
					}
				}),
			)
		},

		inputId(field) {
			return `f-${this.action.id}-${field}`
		},

		labelOf(field) {
			return fieldLabel(this.action, field)
		},

		configOf(field) {
			return fieldConfig(this.action, field)
		},

		inputOf(field) {
			return fieldInput(this.action, field, this.options[field])
		},

		/**
		 * Keep the files picked for one file field.
		 *
		 * @param {string} field The file field.
		 * @param {FileList|null} picked The picked files.
		 */
		pick(field, picked) {
			this.files = { ...this.files, [field]: Array.from(picked || []) }
		},

		/**
		 * Whether a field shows: not declared `visible: false`.
		 *
		 * @param {string} field The field.
		 * @return {boolean} True when it shows.
		 */
		isShownField(field) {
			return this.configOf(field).visible !== false
		},

		/**
		 * The errors of some fields, for one step's check.
		 *
		 * @param {string[]} fields The fields.
		 * @return {Record<string, string>} The errors.
		 *
		 * @spec openspec/changes/site-multi-step-forms/specs/portal-intake-form/spec.md#requirement-a-published-form-with-steps-must-be-filled-in-one-step-at-a-time-with-visible-progress-req-smf-010
		 */
		checkFields(fields) {
			const all = fieldErrors(this.action, this.values, this.files, this.t)
			return Object.fromEntries(
				Object.entries(all).filter(([field]) => fields.includes(field)),
			)
		},

		/**
		 * An answer as the review shows it: the option's label, the file
		 * names, a date in words, or the text.
		 *
		 * @param {string} field The field.
		 * @return {string} The answer.
		 *
		 * @spec openspec/changes/site-multi-step-forms/specs/portal-intake-form/spec.md#requirement-a-form-with-steps-must-end-with-a-review-and-a-confirmation-req-smf-011
		 */
		answerText(field) {
			const input = this.inputOf(field)
			if (input === 'file') {
				return (this.files[field] || []).map((file) => file.name).join(', ')
			}
			const value = String(this.values[field] ?? '')
			if (input === 'select') {
				const option = (this.options[field] || []).find(
					(entry) => String(entry.value) === value,
				)
				return option ? String(option.label) : value
			}
			if (input === 'date' && /^\d{4}-\d{2}-\d{2}$/.test(value)) {
				const [year, month, day] = value.split('-').map(Number)
				const lang =
					typeof document !== 'undefined' && document.documentElement
						? document.documentElement.lang || 'nl'
						: 'nl'
				return new Intl.DateTimeFormat(lang, {
					day: 'numeric',
					month: 'long',
					year: 'numeric',
				}).format(new Date(year, month - 1, day))
			}
			return value
		},

		/**
		 * The form's submit: "Volgende stap" on a step, sending on the review
		 * or on a one-page form.
		 *
		 * @return {Promise<void>|void}
		 *
		 * @spec openspec/changes/site-multi-step-forms/specs/portal-intake-form/spec.md#requirement-a-published-form-with-steps-must-be-filled-in-one-step-at-a-time-with-visible-progress-req-smf-010
		 */
		onSubmit() {
			if (this.hasSteps && !this.onReview) {
				return this.nextStep()
			}
			return this.submit()
		},

		/**
		 * The page's language, for dates.
		 *
		 * @return {string} The language code.
		 *
		 * @spec openspec/changes/site-multi-step-forms/specs/portal-contribution-contract/spec.md#requirement-a-draft-of-a-create-or-endpoint-action-must-stay-with-portaliq-and-the-resident-req-smf-021
		 */
		pageLocale() {
			return (
				(typeof document !== 'undefined' && document.documentElement?.lang)
				|| 'nl'
			)
		},

		/**
		 * Keep the answers so far and the step reached, without sending them
		 * to the app. Files are not kept.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/site-multi-step-forms/specs/portal-contribution-contract/spec.md#requirement-a-draft-of-a-create-or-endpoint-action-must-stay-with-portaliq-and-the-resident-req-smf-021
		 */
		async saveDraft() {
			this.error = ''
			this.done = ''
			const kept = await this.api.saveDraft(this.draftApp, this.action.id, {
				answers: { ...this.values },
				step: this.currentStep ? this.currentStep.id : '',
				retentionDays: this.draftDays,
			})
			if (kept === null || kept === undefined) {
				this.error = this.translate('Your answers could not be saved.')
				return
			}
			this.done = this.translate(
				'Your answers are saved until {date}. You can continue later.',
				{
					date: retentionDate(kept.expiresAt, this.pageLocale()),
				},
			)
		},

		/**
		 * Open a saved draft: its answers back in the fields, on the first step
		 * with a missing required answer, else on the review.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/site-multi-step-forms/specs/portal-intake-form/spec.md#requirement-save-and-resume-must-sit-in-the-step-navigation-req-smf-012
		 */
		async resumeDraft() {
			if (!this.canSaveDraft || typeof this.api.getDraft !== 'function') {
				return
			}
			const draft = await this.api.getDraft(this.draftApp, this.action.id)
			if (!draft || typeof draft.answers !== 'object') {
				return
			}
			const values = { ...this.values }
			for (const field of this.fields) {
				if (field in draft.answers && !(field in this.preset)) {
					values[field] = draft.answers[field]
				}
			}
			this.values = values
			this.stepIndex = landingStep(
				this.flow,
				(fields) => this.checkFields(fields),
				(field) => this.isShownField(field),
			)
			this.done = this.translate(
				'Your answers are saved until {date}. You can continue later.',
				{ date: retentionDate(draft.expiresAt, this.pageLocale()) },
			)
		},

		/**
		 * Move focus to the error summary's heading.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/site-multi-step-forms/specs/site-forms/spec.md#requirement-a-failed-submit-must-show-an-error-summary-that-takes-focus-req-smf-002
		 */
		focusSummary() {
			if (this.$refs.summary) {
				this.$refs.summary.focus()
			}
		},

		/**
		 * Report files that did not attach, or clear the report.
		 *
		 * @param {string} id The saved record's id.
		 * @param {Array<{field: string, file: File}>} failed The files that did not attach.
		 */
		reportFailed(id, failed) {
			if (failed.length === 0) {
				this.pending = null
				return
			}
			this.pending = { id, failed }
			this.error = this.translate(
				'Saved, but these files were not attached: {files}',
				{ files: failed.map((f) => f.file.name).join(', ') },
			)
		},

		/**
		 * Try the files that did not attach again, against the same record.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-file-field-must-upload-after-the-record-exists-req-srp-023
		 */
		async retryFailed() {
			if (this.pending === null) {
				return
			}
			const byField = {}
			for (const { field, file } of this.pending.failed) {
				byField[field] = [...(byField[field] || []), file]
			}
			this.error = ''
			this.submitting = true
			const { failed } = await uploadFiles(
				this.api,
				this.action,
				this.pending.id,
				byField,
			)
			this.submitting = false
			this.reportFailed(this.pending.id, failed)
			if (failed.length === 0) {
				this.done = this.translate('All files are attached.')
			}
		},

		/**
		 * Check, create the record, then upload its files.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-file-field-must-upload-after-the-record-exists-req-srp-023
		 * @spec openspec/changes/site-action-forms/specs/site-forms/spec.md#requirement-a-refused-answer-must-say-in-plain-words-which-field-to-change
		 */
		async submit() {
			this.error = ''
			this.done = ''
			const wrong = this.checkFields(
				this.fields.filter((field) => this.isShownField(field)),
			)
			if (Object.keys(wrong).length > 0) {
				this.showErrors(wrong)
				return
			}
			this.errors = {}
			const tooLarge = oversizedFiles(this.action, this.files)
			if (tooLarge.length > 0) {
				this.error = this.translate('These files are too large: {files}', {
					files: tooLarge.join(', '),
				})
				return
			}

			this.submitting = true
			const body = formBody(this.action, this.values, this.options)
			const result = this.send
				? await this.sendBody(body)
				: await submitWithFiles(this.api, this.action, body, this.files)
			this.submitting = false
			if (!result.ok) {
				// A refusal that names fields (a required field left empty)
				// lands in the summary, on its step; anything else in words.
				// A value the server refused (`invalid`, site-action-forms) gets
				// plain words on its field too.
				const refused = {
					...invalidFieldErrors(this.action, result.invalid, this.t),
					...serverFieldErrors(this.action, result.errors, this.t),
				}
				if (Object.keys(refused).length > 0) {
					this.showErrors(refused)
					return
				}
				this.error = this.translate(refusalKey(result.status || 0))
				return
			}

			if (this.draftDays > 0 && typeof this.api.discardDraft === 'function') {
				this.api.discardDraft(this.draftApp, this.action.id)
			}
			this.values = this.startValues()
			this.files = {}
			this.fileKey++
			this.stepIndex = 0
			this.reportFailed(result.id, result.failed)
			this.$emit('submitted', result.object, this.action)
			if (this.action.confirmation && result.failed.length === 0) {
				this.confirm(result.object)
				return
			}
			if (result.failed.length === 0) {
				this.done = this.action.successMessage || this.translate('Saved.')
			}
		},

		/**
		 * Send the body through the endpoint sender, in the same result shape
		 * as a create.
		 *
		 * @param {object} body The body.
		 * @return {Promise<{ok: boolean, status: number, object: object|null, id: string, failed: Array, errors: object, invalid: object}>} The result.
		 *
		 * @spec openspec/changes/site-multi-step-forms/specs/portal-contribution-contract/spec.md#requirement-a-create-or-endpoint-action-may-run-in-steps-with-a-review-a-draft-and-a-confirmation-req-smf-020
		 * @spec openspec/changes/site-action-forms/specs/site-forms/spec.md#requirement-a-refused-answer-must-say-in-plain-words-which-field-to-change
		 */
		async sendBody(body) {
			const answer = (await this.send(body)) || {}
			return {
				ok: answer.ok === true,
				status: answer.status || 0,
				object: answer.object || null,
				id: '',
				failed: [],
				errors: answer.errors || {},
				invalid: answer.invalid || {},
			}
		},

		/**
		 * Replace the form with the action's confirmation, its placeholders
		 * filled from the answer, and put focus on its heading.
		 *
		 * @param {object|null} answer What the app answered.
		 * @return {void}
		 *
		 * @spec openspec/changes/site-multi-step-forms/specs/portal-contribution-contract/spec.md#requirement-a-create-or-endpoint-action-may-word-its-own-confirmation-req-smf-022
		 */
		confirm(answer) {
			this.confirmed = {
				// The sentence as it stood when the resident sent it.
				summary: this.summaryText,
				body: confirmationText(this.action.confirmation.body, answer),
				next: confirmationText(this.action.confirmation.next, answer),
			}
			this.$nextTick(() => {
				if (this.$refs.confirmationHeading) {
					this.$refs.confirmationHeading.focus()
				}
			})
		},
	},
}
</script>

<style scoped>
.pq-schema-form {
	display: block;
	margin-block: var(--utrecht-space-block-md, 1rem);
}

.pq-schema-form__buttons {
	display: flex;
	flex-wrap: wrap;
	gap: var(--utrecht-space-inline-sm, 0.5rem);
	margin-block-start: var(--utrecht-space-block-md, 1rem);
}

.pq-schema-form__intro {
	margin-block-end: var(--utrecht-space-block-md, 1rem);
}

.pq-schema-form__error {
	color: var(--utrecht-form-field-error-message-color, inherit);
	font-weight: var(--utrecht-typography-weight-scale-bold, bold);
}

.pq-schema-form__summary {
	margin-block-start: var(--utrecht-space-block-md, 1rem);
	padding: 1rem 1.25rem;
	border: 1px solid
		var(--nldesign-color-border, var(--utrecht-color-grey-80, currentcolor));
	border-radius: var(
		--nldesign-website-border-radius-large,
		var(--utrecht-border-radius-md, 0.75rem)
	);
	background: var(--nldesign-color-primary-light, transparent);
}

.pq-schema-form__summary-label {
	margin: 0 0 0.25rem;
	color: var(
		--nldesign-color-primary-hover,
		var(--utrecht-document-color, CanvasText)
	);
	font-size: 0.8125rem;
	font-weight: 700;
	letter-spacing: 0.06em;
	text-transform: uppercase;
}

.pq-schema-form__summary-text {
	margin: 0;
}

.pq-schema-form__done:empty {
	display: none;
}
</style>
