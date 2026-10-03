<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<div class="pq-date-group" data-testid="date-group">
		<div
			v-for="part in partsList"
			:key="part.key"
			class="pq-date-group__part"
			:class="`pq-date-group__part--${part.key}`">
			<label :for="part.id" class="utrecht-form-label pq-date-group__label">
				{{ part.label }}
			</label>
			<input
				:id="part.id"
				class="utrecht-textbox utrecht-textbox--html-input pq-date-group__input"
				:class="{ 'utrecht-textbox--invalid': invalid }"
				type="text"
				inputmode="numeric"
				:autocomplete="part.autocomplete || undefined"
				:maxlength="part.key === 'year' ? 4 : 2"
				:value="parts[part.key]"
				:disabled="disabled"
				:aria-required="required ? 'true' : undefined"
				:aria-invalid="invalid ? 'true' : undefined"
				:data-testid="
					part.key === 'day' && testid ? testid : `${id}-${part.key}`
				"
				@input="update(part.key, $event.target.value)" />
		</div>
	</div>
</template>

<script>
import { dateParts, dateValue } from './fields.js'

import '@utrecht/textbox-css/dist/index.css'

/**
 * The NL Design System "Date Input Group": a date asked as three boxes, Dag,
 * Maand and Jaar, with a numeric keyboard on a phone. The parent wraps it in
 * a fieldset whose legend is the question (FieldShell with `group`).
 *
 * The Dag box carries the field's own id, so the label of a summary link and
 * a `#id` link land on the first input of the group. The value is the ISO
 * date the server already reads (`yyyy-mm-dd`), '' when empty, and the typed
 * parts as `d-m-y` when they are not a real date, which the form refuses with
 * `dateProblem()` before it sends.
 *
 * @spec openspec/changes/site-multi-step-forms/specs/site-forms/spec.md#requirement-a-date-field-must-be-asked-as-day-month-and-year-req-smf-003
 */
export default {
	name: 'DateInputGroup',

	props: {
		/** The field's id; the Dag box takes it, Maand and Jaar add `-month` and `-year`. */
		id: { type: String, required: true },
		/** The value: '', `yyyy-mm-dd`, or `d-m-y` as typed. */
		modelValue: { type: String, default: '' },
		/** Whether the date must be filled in. */
		required: { type: Boolean, default: false },
		/** Whether the field has an error. */
		invalid: { type: Boolean, default: false },
		/** Whether the boxes are disabled. */
		disabled: { type: Boolean, default: false },
		/** The Dag box's label. */
		dayLabel: { type: String, default: 'Dag' },
		/** The Maand box's label. */
		monthLabel: { type: String, default: 'Maand' },
		/** The Jaar box's label. */
		yearLabel: { type: String, default: 'Jaar' },
		/** The Dag box's `data-testid`, so a test or e2e finds the field by its old id. */
		testid: { type: String, default: '' },
		/** Autocomplete tokens for a birth date (`bday`), else none. */
		autocomplete: { type: String, default: '' },
	},

	emits: ['update:modelValue'],

	data() {
		return {
			parts: dateParts(this.modelValue),
			sent: this.modelValue,
		}
	},

	computed: {
		partsList() {
			const bday = this.autocomplete === 'bday'
			return [
				{
					key: 'day',
					id: this.id,
					label: this.dayLabel,
					autocomplete: bday ? 'bday-day' : '',
				},
				{
					key: 'month',
					id: `${this.id}-month`,
					label: this.monthLabel,
					autocomplete: bday ? 'bday-month' : '',
				},
				{
					key: 'year',
					id: `${this.id}-year`,
					label: this.yearLabel,
					autocomplete: bday ? 'bday-year' : '',
				},
			]
		},
	},

	watch: {
		/**
		 * Show a value set from outside, such as a cleared form or a prefill.
		 *
		 * @param {string} value The new value.
		 * @return {void}
		 */
		modelValue(value) {
			if (value !== this.sent) {
				this.parts = dateParts(value)
				this.sent = value
			}
		},
	},

	methods: {
		/**
		 * Keep one box's text and send the date the three boxes make.
		 *
		 * @param {string} key day, month or year.
		 * @param {string} text What the box holds.
		 * @return {void}
		 *
		 * @spec openspec/changes/site-multi-step-forms/specs/site-forms/spec.md#requirement-a-date-field-must-be-asked-as-day-month-and-year-req-smf-003
		 */
		update(key, text) {
			this.parts = { ...this.parts, [key]: String(text ?? '') }
			this.sent = dateValue(this.parts.day, this.parts.month, this.parts.year)
			this.$emit('update:modelValue', this.sent)
		},
	},
}
</script>

<style scoped>
.pq-date-group {
	display: flex;
	flex-wrap: wrap;
	gap: var(--utrecht-space-inline-md, 1rem);
}

.pq-date-group__part {
	display: flex;
	flex-direction: column;
	gap: var(--utrecht-space-block-3xs, 0.25rem);
}

.pq-date-group__label {
	font-weight: var(--utrecht-typography-weight-scale-normal, normal);
}

.pq-date-group__input {
	inline-size: 4.5rem;
}

.pq-date-group__part--year .pq-date-group__input {
	inline-size: 6.5rem;
}
</style>
