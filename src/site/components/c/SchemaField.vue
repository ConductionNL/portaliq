<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<div
		class="utrecht-form-field pq-field"
		:class="[`pq-field--${size}`, { 'utrecht-form-field--invalid': error !== '' }]"
		:data-testid="`schema-field-${field}`">
		<div class="utrecht-form-field__label">
			<label :for="id" class="utrecht-form-label">
				{{ label }}<span v-if="required" class="pq-field__required" aria-hidden="true"> *</span>
			</label>
		</div>
		<div
			v-if="help !== ''"
			:id="`${id}-help`"
			class="utrecht-form-field-description">
			{{ help }}
		</div>
		<div
			v-if="error !== ''"
			:id="`${id}-error`"
			class="utrecht-form-field-error-message"
			:data-testid="`schema-field-error-${field}`">
			{{ error }}
		</div>
		<div class="utrecht-form-field__input">
			<template v-if="input === 'file'">
				<input
					:id="id"
					:key="fileKey"
					type="file"
					class="pq-field__file"
					:multiple="config.multiple === true"
					:accept="accept || undefined"
					:disabled="config.disabled === true"
					:required="required"
					:aria-invalid="error !== '' ? 'true' : undefined"
					:aria-describedby="describedBy"
					@change="$emit('pick', $event.target.files)">
				<p
					v-if="files.length > 0"
					:id="`${id}-picked`"
					class="utrecht-form-field-description">
					{{ translate('Selected: {files}', { files: files.map((f) => f.name).join(', ') }) }}
				</p>
				<p :id="`${id}-limit`" class="utrecht-form-field-description">
					{{ translate('Up to {size} MB per file', { size: config.maxSizeMb || defaultMaxSize }) }}
				</p>
			</template>
			<select
				v-else-if="input === 'select'"
				:id="id"
				class="utrecht-select utrecht-select--html-select"
				:class="{ 'utrecht-select--invalid': error !== '' }"
				:value="modelValue"
				:disabled="config.disabled === true"
				:required="required"
				:aria-invalid="error !== '' ? 'true' : undefined"
				:aria-describedby="describedBy"
				@change="$emit('update:modelValue', $event.target.value)">
				<option value="">
					{{ translate('Choose an option') }}
				</option>
				<option
					v-for="option in options"
					:key="option.value"
					:value="String(option.value)">
					{{ option.label }}
				</option>
			</select>
			<textarea
				v-else-if="input === 'textarea'"
				:id="id"
				class="utrecht-textarea utrecht-textarea--html-textarea"
				:class="{ 'utrecht-textarea--invalid': error !== '' }"
				:value="modelValue"
				:placeholder="config.placeholder || undefined"
				:disabled="config.disabled === true"
				:required="required"
				:aria-invalid="error !== '' ? 'true' : undefined"
				:aria-describedby="describedBy"
				@input="$emit('update:modelValue', $event.target.value)" />
			<input
				v-else
				:id="id"
				:type="input"
				class="utrecht-textbox utrecht-textbox--html-input"
				:class="{ 'utrecht-textbox--invalid': error !== '' }"
				:value="modelValue"
				:placeholder="config.placeholder || undefined"
				:disabled="config.disabled === true"
				:required="required"
				:aria-invalid="error !== '' ? 'true' : undefined"
				:aria-describedby="describedBy"
				@input="$emit('update:modelValue', $event.target.value)">
		</div>
	</div>
</template>

<script>
import { DEFAULT_MAX_SIZE_MB } from '../../../shared/fileFieldSubmit.js'
import { translatorOr } from './forms.js'

import '@utrecht/form-field-css/dist/index.css'
import '@utrecht/form-field-description-css/dist/index.css'
import '@utrecht/form-field-error-message-css/dist/index.css'
import '@utrecht/select-css/dist/index.css'
import '@utrecht/textarea-css/dist/index.css'

/**
 * One labelled field of a schema form: a text box, a date or number input, a
 * select, a textarea or a file picker, with its required marker, help text and
 * inline error tied to the input for a screen reader.
 *
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-schema-form-must-render-only-whitelisted-fields-req-srp-022
 */
export default {
	name: 'SchemaField',

	props: {
		/** The input's id; the label points at it. */
		id: { type: String, required: true },
		/** The field name. */
		field: { type: String, required: true },
		/** The visible label. */
		label: { type: String, required: true },
		/** The field config (`required`, `size`, `placeholder`, `help`, `disabled`, file keys). */
		config: { type: Object, default: () => ({}) },
		/** The input kind, from `fieldInput()`. */
		input: { type: String, default: 'text' },
		/** The options of a select. */
		options: { type: Array, default: () => [] },
		/** The value. */
		modelValue: { type: String, default: '' },
		/** The files picked for a file field. */
		files: { type: Array, default: () => [] },
		/** Bumped by the form to clear a file input. */
		fileKey: { type: Number, default: 0 },
		/** The inline error, '' when none. */
		error: { type: String, default: '' },
		/** The translator `t(key, vars)`. */
		t: { type: Function, default: null },
	},

	emits: ['update:modelValue', 'pick'],

	computed: {
		translate() {
			return translatorOr(this.t)
		},

		required() {
			return this.config.required === true
		},

		size() {
			return ['small', 'medium', 'large', 'full'].includes(this.config.size) ? this.config.size : 'medium'
		},

		help() {
			return typeof this.config.help === 'string' ? this.config.help : ''
		},

		accept() {
			return Array.isArray(this.config.accept) ? this.config.accept.join(',') : ''
		},

		defaultMaxSize() {
			return DEFAULT_MAX_SIZE_MB
		},

		describedBy() {
			const ids = []
			if (this.help !== '') {
				ids.push(`${this.id}-help`)
			}
			if (this.input === 'file') {
				if (this.files.length > 0) {
					ids.push(`${this.id}-picked`)
				}
				ids.push(`${this.id}-limit`)
			}
			if (this.error !== '') {
				ids.push(`${this.id}-error`)
			}
			return ids.length > 0 ? ids.join(' ') : undefined
		},
	},
}
</script>

<style scoped>
.pq-field {
	margin-block-end: var(--utrecht-form-field-margin-block-end, var(--utrecht-space-block-md, 1rem));
	max-inline-size: 40rem;
}

.pq-field--small {
	max-inline-size: 16rem;
}

.pq-field--full {
	max-inline-size: none;
}

.pq-field .utrecht-textbox,
.pq-field .utrecht-select,
.pq-field .utrecht-textarea {
	inline-size: 100%;
}

.pq-field__required {
	color: var(--utrecht-form-field-invalid-color, inherit);
}
</style>
