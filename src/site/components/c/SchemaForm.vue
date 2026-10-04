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
				:disabled="submitting || savingDraft"
				data-testid="schema-form-save-draft"
				@click="saveDraft">
				{{
					savingDraft
						? translate('Please wait…')
						: translate('Save and continue later')
				}}
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
			v-if="retentionText !== ''"
			class="utrecht-paragraph pq-schema-form__retention"
			role="status"
			data-testid="schema-form-retention">
			{{ retentionText }}
		</p>

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
	resumeStep,
	retentionSentence,
	stepHeading,
} from '../forms/steps.js'
import {
	collectionProviders,
	fieldConfig,
	fieldErrors,
	fieldInput,
	fieldLabel,
	formBody,
	formFields,
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
		/** The app the action belongs to, for its drafts. */
		app: { type: String, default: '' },
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
			draft: null,
			savingDraft: false,
		}
	},

	computed: {
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
				return this.fields
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

		/**
		 * Whether this form offers "Opslaan en later verdergaan": the action
		 * declares a `draft` and the api can keep one for a signed-in
		 * resident. It sits after "Volgende stap" in the step navigation.
		 *
		 * @return {boolean} True to show the button.
		 *
		 * @spec openspec/changes/site-multi-step-forms/specs/portal-intake-form/spec.md#requirement-save-and-resume-must-sit-in-the-step-navigation-req-smf-012
		 */
		canSaveDraft() {
			return (
				this.confirmed === null
				&& this.action.draft !== undefined
				&& typeof this.api.saveDraft === 'function'
			)
		},

		/**
		 * The sentence that says how long the answers are kept, read from the
		 * saved draft's own date.
		 *
		 * @return {string} The sentence, or ''.
		 *
		 * @spec openspec/changes/site-multi-step-forms/specs/portal-intake-form/spec.md#requirement-save-and-resume-must-sit-in-the-step-navigation-req-smf-012
		 */
		retentionText() {
			if (this.draft === null || this.confirmed !== null) {
				return ''
			}
			return retentionSentence(
				this.translate(
					'Your answers are saved. We keep them until {date}, so you can continue later.',
				),
				this.draft.expiresAt,
				this.dayLocale,
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
	 * Load the options, then take back the resident's own saved answers.
	 *
	 * @spec openspec/changes/site-multi-step-forms/specs/portal-intake-form/spec.md#requirement-save-and-resume-must-sit-in-the-step-navigation-req-smf-012
	 */
	mounted() {
		this.loadOptions()
		this.resumeDraft()
	},

	methods: {
		/**
		 * One empty string per whitelisted field.
		 *
		 * @return {Record<string, string>} The values.
		 */
		emptyValues() {
			const values = {}
			for (const field of formFields(this.action)) {
				values[field] = ''
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
				const refused = serverFieldErrors(this.action, result.errors, this.t)
				if (Object.keys(refused).length > 0) {
					this.showErrors(refused)
					return
				}
				this.error = this.translate('Saving did not work.')
				return
			}

			this.values = this.startValues()
			this.files = {}
			this.fileKey++
			this.stepIndex = 0
			this.forgetDraft()
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
		 * Save what the resident has typed, so they can carry on later. The
		 * server keeps the action's own fields and no file, and answers with
		 * the date it will keep them until.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/site-multi-step-forms/specs/portal-contribution-contract/spec.md#requirement-a-draft-of-a-create-or-endpoint-action-must-stay-with-portaliq-and-the-resident-req-smf-021
		 */
		async saveDraft() {
			this.savingDraft = true
			const saved = await this.api.saveDraft(this.appOf(), this.action.id, {
				...formBody(this.action, this.values, this.options),
				step: this.stepIndex,
			})
			this.savingDraft = false
			if (saved !== null) {
				this.draft = saved
			}
		},

		/**
		 * On opening the form, take back the resident's own saved answers and
		 * open on the first step with a gap, else on the review.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/site-multi-step-forms/specs/portal-intake-form/spec.md#requirement-save-and-resume-must-sit-in-the-step-navigation-req-smf-012
		 */
		async resumeDraft() {
			if (
				this.action.draft === undefined
				|| typeof this.api.myDraft !== 'function'
			) {
				return
			}
			const draft = await this.api.myDraft(this.appOf(), this.action.id)
			if (draft === null || typeof draft !== 'object') {
				return
			}
			this.draft = draft
			const answers =
				draft.answers && typeof draft.answers === 'object'
					? draft.answers
					: {}
			const values = { ...this.values }
			for (const field of this.fields) {
				if (typeof answers[field] === 'string') {
					values[field] = answers[field]
				}
			}
			this.values = values
			if (this.hasSteps) {
				this.openStep(
					resumeStep(
						this.flow,
						values,
						(field) => this.configOf(field).required === true,
					),
				)
			}
		},

		/**
		 * The app the action belongs to: the one the host named, else the
		 * action's own.
		 *
		 * @return {string} The app id.
		 *
		 * @spec openspec/changes/site-multi-step-forms/specs/portal-contribution-contract/spec.md#requirement-a-draft-of-a-create-or-endpoint-action-must-stay-with-portaliq-and-the-resident-req-smf-021
		 */
		appOf() {
			return this.app !== '' ? this.app : String(this.action.app || '')
		},

		/**
		 * Send the body through the endpoint sender, in the same result shape
		 * as a create.
		 *
		 * @param {object} body The body.
		 * @return {Promise<{ok: boolean, object: object|null, id: string, failed: Array, errors: object}>} The result.
		 *
		 * @spec openspec/changes/site-multi-step-forms/specs/portal-contribution-contract/spec.md#requirement-a-create-or-endpoint-action-may-run-in-steps-with-a-review-a-draft-and-a-confirmation-req-smf-020
		 */
		async sendBody(body) {
			const answer = (await this.send(body)) || {}
			return {
				ok: answer.ok === true,
				object: answer.object || null,
				id: '',
				failed: [],
				errors: answer.errors || {},
			}
		},

		/**
		 * The draft is spent once the action is sent: the answers are a record
		 * now, so portaliq keeps no copy.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/site-multi-step-forms/specs/portal-contribution-contract/spec.md#requirement-a-draft-of-a-create-or-endpoint-action-must-stay-with-portaliq-and-the-resident-req-smf-021
		 */
		forgetDraft() {
			if (this.draft === null || typeof this.api.discardDraft !== 'function') {
				return
			}
			this.draft = null
			this.api.discardDraft(this.appOf(), this.action.id)
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

.pq-schema-form__retention {
	margin-block-start: var(--utrecht-space-block-sm, 0.75rem);
}

.pq-schema-form__intro {
	margin-block-end: var(--utrecht-space-block-md, 1rem);
}

.pq-schema-form__error {
	color: var(--utrecht-form-field-error-message-color, inherit);
	font-weight: var(--utrecht-typography-weight-scale-bold, bold);
}

.pq-schema-form__done:empty {
	display: none;
}
</style>
