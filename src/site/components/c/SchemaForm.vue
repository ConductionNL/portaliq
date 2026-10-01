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

		<p
			v-if="error !== ''"
			class="utrecht-paragraph pq-schema-form__error"
			role="alert"
			data-testid="schema-form-error">
			{{ error }}
		</p>

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
import SchemaField from './SchemaField.vue'
import {
	oversizedFiles,
	submitWithFiles,
	uploadFiles,
} from '../../../shared/fileFieldSubmit.js'
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
} from './forms.js'

/**
 * A manifest-driven form (the Vue port of SchemaForm.jsx, which also replaces
 * ActionFieldsForm.jsx). It renders exactly the action's whitelisted `fields`,
 * shaped by `fieldConfigs` and `optionsProviders`: a static list, or a
 * collection fetched through the subject-scoped api, so a dropdown only offers
 * what the resident may already read. A file field is never in the create
 * body: the record is created first, then each file is uploaded into it.
 *
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-schema-form-must-render-only-whitelisted-fields-req-srp-022
 */
export default {
	name: 'SchemaForm',

	components: { SchemaField },

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
		return {
			values: this.emptyValues(),
			options: staticOptions(this.action),
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

		submitLabel() {
			return this.action.submitLabel || this.action.label || this.translate('Save')
		},
	},

	watch: {
		'action.id': function() {
			this.values = this.emptyValues()
			this.options = staticOptions(this.action)
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
		 * Fetch every `collection` provider's options.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-schema-form-must-render-only-whitelisted-fields-req-srp-022
		 */
		async loadOptions() {
			const actionId = this.action.id
			await Promise.all(collectionProviders(this.action).map(async ([field, provider]) => {
				const fetched = await this.api.fetchOptions(provider)
				if (this.action.id === actionId) {
					this.options = { ...this.options, [field]: Array.isArray(fetched) ? fetched : [] }
				}
			}))
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
		 * Move focus to the first field with an error.
		 */
		focusFirstError() {
			const first = this.fields.find((field) => this.errors[field])
			const element = first && typeof document !== 'undefined' ? document.getElementById(this.inputId(first)) : null
			if (element) {
				element.focus()
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
			this.error = this.translate('Saved, but these files were not attached: {files}', { files: failed.map((f) => f.file.name).join(', ') })
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
			const { failed } = await uploadFiles(this.api, this.action, this.pending.id, byField)
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
				this.error = this.translate('Not everything is filled in yet. Check the fields below.')
				this.$nextTick(() => this.focusFirstError())
				return
			}
			const tooLarge = oversizedFiles(this.action, this.files)
			if (tooLarge.length > 0) {
				this.error = this.translate('These files are too large: {files}', { files: tooLarge.join(', ') })
				return
			}

			this.submitting = true
			const result = await submitWithFiles(this.api, this.action, formBody(this.action, this.values, this.options), this.files)
			this.submitting = false
			if (!result.ok) {
				this.error = this.translate('Saving did not work.')
				return
			}

			this.values = this.emptyValues()
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

.pq-schema-form__error {
	color: var(--utrecht-form-field-error-message-color, inherit);
	font-weight: var(--utrecht-typography-weight-scale-bold, bold);
}

.pq-schema-form__done:empty {
	display: none;
}
</style>
