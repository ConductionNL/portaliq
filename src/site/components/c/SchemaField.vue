<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<FieldShell
		:id="id"
		class="pq-field"
		:class="`pq-field--${size}`"
		:label="label"
		:required="required"
		:optionalLabel="translate('(optional)')"
		:help="help"
		:error="error"
		:group="input === 'date'"
		:errorTestid="`schema-field-error-${field}`"
		:data-testid="`schema-field-${field}`">
		<template v-if="input === 'file'">
			<input
				:id="id"
				:key="fileKey"
				type="file"
				class="pq-field__file"
				:multiple="config.multiple === true"
				:accept="accept || undefined"
				:disabled="config.disabled === true"
				:aria-required="ariaRequired"
				:aria-invalid="error !== '' ? 'true' : undefined"
				:aria-describedby="describedBy"
				@change="$emit('pick', $event.target.files)" />
			<p
				v-if="files.length > 0"
				:id="`${id}-picked`"
				class="utrecht-form-field-description">
				{{
					translate('Selected: {files}', {
						files: files.map((f) => f.name).join(', '),
					})
				}}
			</p>
			<p :id="`${id}-limit`" class="utrecht-form-field-description">
				{{
					translate('Up to {size} MB per file', {
						size: config.maxSizeMb || defaultMaxSize,
					})
				}}
			</p>
		</template>
		<DateInputGroup
			v-else-if="input === 'date'"
			:id="id"
			:modelValue="modelValue"
			:required="required"
			:invalid="error !== ''"
			:disabled="config.disabled === true"
			:dayLabel="translate('Day')"
			:monthLabel="translate('Month')"
			:yearLabel="translate('Year')"
			@update:modelValue="(value) => $emit('update:modelValue', value)" />
		<select
			v-else-if="input === 'select'"
			:id="id"
			class="utrecht-select utrecht-select--html-select"
			:class="{ 'utrecht-select--invalid': error !== '' }"
			:value="modelValue"
			:disabled="config.disabled === true"
			:aria-required="ariaRequired"
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
			:aria-required="ariaRequired"
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
			:aria-required="ariaRequired"
			:aria-invalid="error !== '' ? 'true' : undefined"
			:aria-describedby="describedBy"
			@input="$emit('update:modelValue', $event.target.value)" />
	</FieldShell>
</template>

<script>
import { DEFAULT_MAX_SIZE_MB } from '../../../shared/fileFieldSubmit.js'
import DateInputGroup from '../forms/DateInputGroup.vue'
import FieldShell from '../forms/FieldShell.vue'
import { translatorOr } from './forms.js'

import '@utrecht/select-css/dist/index.css'
import '@utrecht/textarea-css/dist/index.css'

/**
 * One labelled field of a schema form: a text box, a date or number input, a
 * select, a textarea or a file picker, with its help text and inline error tied
 * to the input for a screen reader. A field the resident may leave empty reads
 * "(niet verplicht)"; a required one has `aria-required` and no mark. A date
 * is asked as day, month and year in a fieldset (DateInputGroup).
 *
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-schema-form-must-render-only-whitelisted-fields-req-srp-022
 * @spec openspec/changes/site-multi-step-forms/specs/site-forms/spec.md#requirement-a-site-form-must-mark-the-fields-that-are-not-required-req-smf-001
 * @spec openspec/changes/site-multi-step-forms/specs/site-forms/spec.md#requirement-a-date-field-must-be-asked-as-day-month-and-year-req-smf-003
 */
export default {
	name: 'SchemaField',

	components: { DateInputGroup, FieldShell },

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

		ariaRequired() {
			return this.required ? 'true' : undefined
		},

		size() {
			return ['small', 'medium', 'large', 'full'].includes(this.config.size)
				? this.config.size
				: 'medium'
		},

		help() {
			if (typeof this.config.help === 'string' && this.config.help !== '') {
				return this.config.help
			}
			return this.input === 'date'
				? this.translate('For example 1 3 2026')
				: ''
		},

		accept() {
			return Array.isArray(this.config.accept)
				? this.config.accept.join(',')
				: ''
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
	margin-block-end: var(
		--utrecht-form-field-margin-block-end,
		var(--utrecht-space-block-md, 1rem)
	);
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
</style>
