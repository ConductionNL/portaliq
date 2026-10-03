<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<div class="pq-date-choices" data-testid="date-choices">
		<label
			v-for="(day, index) in days"
			:key="day.value"
			class="pq-choice-card"
			:class="{
				'pq-choice-card--checked': !otherOpen && modelValue === day.value,
			}">
			<input
				:id="index === 0 ? id : `${id}-${index}`"
				:aria-labelledby="`${id}-${index}-text`"
				class="utrecht-radio-button utrecht-radio-button--html-input"
				:class="{ 'utrecht-radio-button--invalid': invalid }"
				type="radio"
				:name="id"
				:value="day.value"
				:checked="!otherOpen && modelValue === day.value"
				:disabled="disabled"
				:aria-required="required ? 'true' : undefined"
				:aria-invalid="invalid ? 'true' : undefined"
				:data-testid="`${id}-day-${index}`"
				@change="choose(day.value)" />
			<span :id="`${id}-${index}-text`" class="pq-choice-card__label">{{
				day.label
			}}</span>
		</label>
		<label
			class="pq-choice-card"
			:class="{ 'pq-choice-card--checked': otherOpen }">
			<input
				:id="`${id}-other`"
				:aria-labelledby="`${id}-other-text`"
				class="utrecht-radio-button utrecht-radio-button--html-input"
				:class="{ 'utrecht-radio-button--invalid': invalid }"
				type="radio"
				:name="id"
				value=""
				:checked="otherOpen"
				:disabled="disabled"
				:aria-required="required ? 'true' : undefined"
				:aria-invalid="invalid ? 'true' : undefined"
				:aria-controls="`${id}-date`"
				:aria-expanded="otherOpen ? 'true' : 'false'"
				:data-testid="`${id}-day-other`"
				@change="openOther" />
			<span :id="`${id}-other-text`" class="pq-choice-card__label">{{
				otherDayLabel
			}}</span>
		</label>
		<div v-if="otherOpen" class="pq-date-choices__other">
			<p :id="`${id}-date-hint`" class="utrecht-form-field-description">
				{{ hint }}
			</p>
			<DateInputGroup
				:id="`${id}-date`"
				:modelValue="modelValue"
				:required="required"
				:invalid="invalid"
				:disabled="disabled"
				:dayLabel="dayLabel"
				:monthLabel="monthLabel"
				:yearLabel="yearLabel"
				@update:modelValue="(value) => $emit('update:modelValue', value)" />
		</div>
	</div>
</template>

<script>
import DateInputGroup from './DateInputGroup.vue'
import { namedDays } from './fields.js'

import '@utrecht/radio-button-css/dist/index.css'

/**
 * A date asked as a few named days: today and the next working days, named
 * in the site's language ("Vandaag, vrijdag 2 oktober", "Maandag 5
 * oktober"), plus "Een andere dag", which opens the day, month and year
 * group. The value is the same `yyyy-mm-dd` the date group sends.
 *
 * @spec openspec/changes/site-multi-step-forms/specs/site-forms/spec.md#requirement-an-action-field-may-ask-for-choice-cards-or-named-days-req-smf-005
 */
export default {
	name: 'DateChoices',

	components: { DateInputGroup },

	props: {
		/** The field's id: the radio group's name and the first radio's id. */
		id: { type: String, required: true },
		/** The value: '', `yyyy-mm-dd`, or a date being typed. */
		modelValue: { type: String, default: '' },
		/** How many named days, 1 to 5. */
		count: { type: Number, default: 2 },
		/** The site's language, for the day names. */
		locale: { type: String, default: 'nl' },
		/** The moment the form opened; a test pins it. */
		now: { type: Date, default: () => new Date() },
		/** The word for today. */
		todayWord: { type: String, default: 'Vandaag' },
		/** The card that opens the date group. */
		otherDayLabel: { type: String, default: 'Een andere dag' },
		/** The example under the date group. */
		hint: { type: String, default: 'Bijvoorbeeld 1 3 2026' },
		/** Whether an answer is required. */
		required: { type: Boolean, default: false },
		/** Whether the field has an error. */
		invalid: { type: Boolean, default: false },
		/** Whether the field is disabled. */
		disabled: { type: Boolean, default: false },
		/** The Dag box's label. */
		dayLabel: { type: String, default: 'Dag' },
		/** The Maand box's label. */
		monthLabel: { type: String, default: 'Maand' },
		/** The Jaar box's label. */
		yearLabel: { type: String, default: 'Jaar' },
	},

	emits: ['update:modelValue'],

	data() {
		return { otherChosen: false }
	},

	computed: {
		/**
		 * The named days, today first.
		 *
		 * @return {Array<{value: string, label: string}>} The days.
		 *
		 * @spec openspec/changes/site-multi-step-forms/specs/site-forms/spec.md#requirement-an-action-field-may-ask-for-choice-cards-or-named-days-req-smf-005
		 */
		days() {
			return namedDays(this.now, this.count, this.locale, this.todayWord)
		},

		/**
		 * The values of the named days.
		 *
		 * @return {string[]} The ISO dates.
		 */
		namedValues() {
			return this.days.map((day) => day.value)
		},

		/**
		 * Whether "Een andere dag" is chosen: picked, or a value that is no
		 * named day.
		 *
		 * @return {boolean} True when the date group shows.
		 */
		otherOpen() {
			return (
				this.otherChosen
				|| (this.modelValue !== ''
					&& !this.namedValues.includes(this.modelValue))
			)
		},
	},

	methods: {
		/**
		 * Choose a named day.
		 *
		 * @param {string} value The day's ISO date.
		 * @return {void}
		 *
		 * @spec openspec/changes/site-multi-step-forms/specs/site-forms/spec.md#requirement-an-action-field-may-ask-for-choice-cards-or-named-days-req-smf-005
		 */
		choose(value) {
			this.otherChosen = false
			this.$emit('update:modelValue', value)
		},

		/**
		 * Choose "Een andere dag": the date group opens, empty.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/site-multi-step-forms/specs/site-forms/spec.md#requirement-an-action-field-may-ask-for-choice-cards-or-named-days-req-smf-005
		 */
		openOther() {
			this.otherChosen = true
			if (this.namedValues.includes(this.modelValue)) {
				this.$emit('update:modelValue', '')
			}
		},
	},
}
</script>

<style scoped>
.pq-date-choices {
	display: flex;
	flex-direction: column;
	gap: var(--utrecht-space-block-xs, 0.5rem);
}

.pq-choice-card {
	align-items: center;
	border: 1px solid
		var(--utrecht-form-control-border-color, var(--utrecht-document-color, #333));
	border-radius: var(--utrecht-form-control-border-radius, 0.25rem);
	cursor: pointer;
	display: flex;
	gap: var(--utrecht-space-inline-sm, 0.75rem);
	max-inline-size: 32rem;
	padding-block: var(--utrecht-space-block-sm, 0.75rem);
	padding-inline: var(--utrecht-space-inline-md, 1rem);
}

.pq-choice-card--checked {
	border-color: var(--utrecht-focus-outline-color, currentColor);
	border-width: 2px;
}

.pq-choice-card:focus-within {
	outline: var(--utrecht-focus-outline-width, 2px)
		var(--utrecht-focus-outline-style, solid)
		var(--utrecht-focus-outline-color, currentColor);
	outline-offset: 2px;
}

.pq-date-choices__other {
	display: flex;
	flex-direction: column;
	gap: var(--utrecht-space-block-3xs, 0.25rem);
	margin-block-start: var(--utrecht-space-block-xs, 0.5rem);
}
</style>
