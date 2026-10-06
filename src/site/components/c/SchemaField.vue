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
		:group="group"
		:errorTestid="`schema-field-error-${field}`"
		:data-testid="`schema-field-${field}`">
		<FileUpload
			v-if="input === 'file'"
			:id="id"
			:files="files"
			:fileKey="fileKey"
			:multiple="config.multiple === true"
			:accept="accept"
			:required="required"
			:invalid="error !== ''"
			:disabled="config.disabled === true"
			:labelledBy="`${id}-label`"
			:describedBy="shellDescribedBy"
			:buttonLabel="translate('Choose a file or photo')"
			:limitText="
				translate('Up to {size} MB per file', {
					size: config.maxSizeMb || defaultMaxSize,
				})
			"
			:removeLabel="translate('Remove {file}')"
			@pick="(picked) => $emit('pick', picked)" />
		<DateChoices
			v-else-if="input === 'date' && config.widget === 'dateChoices'"
			:id="id"
			:modelValue="modelValue"
			:count="config.dateChoices || 2"
			:locale="dayLocale"
			:todayWord="translate('Today')"
			:otherDayLabel="translate('Another day')"
			:hint="translate('For example 1 3 2026')"
			:required="required"
			:invalid="error !== ''"
			:disabled="config.disabled === true"
			:dayLabel="translate('Day')"
			:monthLabel="translate('Month')"
			:yearLabel="translate('Year')"
			@update:modelValue="(value) => $emit('update:modelValue', value)" />
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
		<CountStepper
			v-else-if="config.widget === 'count' && input !== 'select'"
			:id="id"
			:modelValue="modelValue"
			:min="countMin"
			:max="countMax"
			:unit="config.unit || {}"
			:priceLabel="config.priceLabel || ''"
			:labelledBy="`${id}-label`"
			:describedBy="shellDescribedBy"
			:required="required"
			:invalid="error !== ''"
			:disabled="config.disabled === true"
			:fewerLabel="translate('One less')"
			:moreLabel="translate('One more')"
			@update:modelValue="(value) => $emit('update:modelValue', value)" />
		<ChoiceCards
			v-else-if="input === 'select' && config.widget === 'choices'"
			:id="id"
			:options="options"
			:modelValue="modelValue"
			:choiceOptions="config.choiceOptions || []"
			:otherLabel="config.otherLabel || ''"
			:required="required"
			:invalid="error !== ''"
			:disabled="config.disabled === true"
			:selectPlaceholder="translate('Choose an option')"
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
			:aria-labelledby="`${id}-label`"
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
			:aria-labelledby="`${id}-label`"
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
			:aria-labelledby="`${id}-label`"
			:aria-describedby="describedBy"
			@input="$emit('update:modelValue', $event.target.value)" />
	</FieldShell>
</template>

<script>
import ChoiceCards from '../forms/ChoiceCards.vue'
import CountStepper from '../forms/CountStepper.vue'
import DateChoices from '../forms/DateChoices.vue'
import DateInputGroup from '../forms/DateInputGroup.vue'
import FieldShell from '../forms/FieldShell.vue'
import FileUpload from '../forms/FileUpload.vue'
import { DEFAULT_MAX_SIZE_MB } from '../../../shared/fileFieldSubmit.js'
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

	components: {
		ChoiceCards,
		CountStepper,
		DateChoices,
		DateInputGroup,
		FieldShell,
		FileUpload,
	},

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
		/** The site's language, for named days; else the page's `lang`. */
		locale: { type: String, default: '' },
	},

	emits: ['update:modelValue', 'pick'],

	computed: {
		translate() {
			return translatorOr(this.t)
		},

		required() {
			return this.config.required === true
		},

		/**
		 * `aria-required` for a required field, nothing for an optional one.
		 *
		 * @spec openspec/changes/site-multi-step-forms/specs/site-forms/spec.md#requirement-a-site-form-must-mark-the-fields-that-are-not-required-req-smf-001
		 */
		ariaRequired() {
			return this.required ? 'true' : undefined
		},

		size() {
			return ['small', 'medium', 'large', 'full'].includes(this.config.size)
				? this.config.size
				: 'medium'
		},

		/**
		 * The description: the field's own help, else a date's example.
		 *
		 * @spec openspec/changes/site-multi-step-forms/specs/site-forms/spec.md#requirement-a-date-field-must-be-asked-as-day-month-and-year-req-smf-003
		 */
		help() {
			if (typeof this.config.help === 'string' && this.config.help !== '') {
				return this.config.help
			}
			return this.input === 'date' && this.config.widget !== 'dateChoices'
				? this.translate('For example 1 3 2026')
				: ''
		},

		/**
		 * The lowest count of a count stepper.
		 *
		 * @return {number} The count.
		 *
		 * @spec openspec/changes/count-field/specs/site-forms/spec.md#requirement-an-action-field-may-ask-for-a-count-with-a-stepper
		 */
		countMin() {
			return Number.isInteger(this.config.min) ? this.config.min : 1
		},

		/**
		 * The highest count of a count stepper.
		 *
		 * @return {number} The count.
		 *
		 * @spec openspec/changes/count-field/specs/site-forms/spec.md#requirement-an-action-field-may-ask-for-a-count-with-a-stepper
		 */
		countMax() {
			return Number.isInteger(this.config.max) ? this.config.max : 99
		},

		accept() {
			return Array.isArray(this.config.accept)
				? this.config.accept.join(',')
				: ''
		},

		defaultMaxSize() {
			return DEFAULT_MAX_SIZE_MB
		},

		/**
		 * The ids of the field's description and error, for the input's
		 * `aria-describedby` (a file field adds its limit and list itself).
		 *
		 * @return {string} The ids, or ''.
		 *
		 * @spec openspec/changes/site-multi-step-forms/specs/site-forms/spec.md#requirement-a-failed-submit-must-show-an-error-summary-that-takes-focus-req-smf-002
		 */
		shellDescribedBy() {
			const ids = []
			if (this.help !== '') {
				ids.push(`${this.id}-help`)
			}
			if (this.error !== '') {
				ids.push(`${this.id}-error`)
			}
			return ids.join(' ')
		},

		/**
		 * The same ids, or undefined when there are none.
		 *
		 * @return {string|undefined} The ids.
		 *
		 * @spec openspec/changes/site-multi-step-forms/specs/site-forms/spec.md#requirement-a-failed-submit-must-show-an-error-summary-that-takes-focus-req-smf-002
		 */
		describedBy() {
			return this.shellDescribedBy || undefined
		},

		/**
		 * Whether the field is a fieldset: a date, or choice cards.
		 *
		 * @return {boolean} True for a group.
		 *
		 * @spec openspec/changes/site-multi-step-forms/specs/site-forms/spec.md#requirement-an-action-field-may-ask-for-choice-cards-or-named-days-req-smf-005
		 */
		group() {
			return (
				this.input === 'date'
				|| (this.input === 'select' && this.config.widget === 'choices')
			)
		},

		/**
		 * The language the named days are written in.
		 *
		 * @return {string} The locale.
		 *
		 * @spec openspec/changes/site-multi-step-forms/specs/site-forms/spec.md#requirement-an-action-field-may-ask-for-choice-cards-or-named-days-req-smf-005
		 */
		dayLocale() {
			if (this.locale !== '') {
				return this.locale
			}
			const lang =
				typeof document !== 'undefined' && document.documentElement
					? document.documentElement.lang
					: ''
			return lang || 'nl'
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
