<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<form
		class="pq-schema-form"
		:aria-label="action.label || action.id"
		data-testid="schema-form"
		novalidate
		@submit.prevent="submit">
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

		<SchemaField
			v-for="field in fields"
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

		<div class="pq-schema-form__buttons">
			<button
				type="submit"
				class="utrecht-button utrecht-button--primary-action"
				:disabled="submitting"
				data-testid="schema-form-submit">
				{{ submitting ? translate('Please wait…') : submitLabel }}
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
import SchemaField from './SchemaField.vue'
import {
	oversizedFiles,
	submitWithFiles,
	uploadFiles,
} from '../../../shared/fileFieldSubmit.js'
import { explainsOptional, summaryEntries } from '../forms/fields.js'
import {
	collectionProviders,
	fieldConfig,
	fieldErrors,
	fieldInput,
	fieldLabel,
	formBody,
	formFields,
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

	components: { ErrorSummary, SchemaField },

	props: {
		/** The normalised manifest action (`create` or `update`). */
		action: { type: Object, required: true },
		/** The portal api: `createObject`, `uploadFieldFile`, `fetchOptions`. */
		api: { type: Object, required: true },
		/** The translator `t(key, vars)`. */
		t: { type: Function, default: null },
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
			return explainsOptional(
				this.fields.map((field) => this.configOf(field).required === true),
			)
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

	mounted() {
		this.loadOptions()
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
			this.errors = fieldErrors(this.action, this.values, this.files, this.t)
			if (Object.keys(this.errors).length > 0) {
				this.$nextTick(() => this.focusSummary())
				return
			}
			const tooLarge = oversizedFiles(this.action, this.files)
			if (tooLarge.length > 0) {
				this.error = this.translate('These files are too large: {files}', {
					files: tooLarge.join(', '),
				})
				return
			}

			this.submitting = true
			const result = await submitWithFiles(
				this.api,
				this.action,
				formBody(this.action, this.values, this.options),
				this.files,
			)
			this.submitting = false
			if (!result.ok) {
				this.error = this.translate('Saving did not work.')
				return
			}

			this.values = this.startValues()
			this.files = {}
			this.fileKey++
			this.reportFailed(result.id, result.failed)
			if (result.failed.length === 0) {
				this.done = this.action.successMessage || this.translate('Saved.')
			}
			this.$emit('submitted', result.object, this.action)
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

.pq-schema-form__done:empty {
	display: none;
}
</style>
