<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<div class="pq-choice-cards" data-testid="choice-cards">
		<label
			v-for="(option, index) in split.cards"
			:key="String(option.value)"
			class="pq-choice-card"
			:class="{ 'pq-choice-card--checked': isChecked(option) }">
			<input
				:id="index === 0 ? id : `${id}-${index}`"
				:aria-labelledby="`${id}-${index}-text`"
				class="utrecht-radio-button utrecht-radio-button--html-input"
				:class="{ 'utrecht-radio-button--invalid': invalid }"
				type="radio"
				:name="id"
				:value="String(option.value)"
				:checked="isChecked(option)"
				:disabled="disabled"
				:aria-required="required ? 'true' : undefined"
				:aria-invalid="invalid ? 'true' : undefined"
				:data-testid="`${id}-choice-${option.value}`"
				@change="choose(option)" />
			<span :id="`${id}-${index}-text`" class="pq-choice-card__label">{{
				option.label
			}}</span>
		</label>
		<label
			v-if="hasOther"
			class="pq-choice-card"
			:class="{ 'pq-choice-card--checked': otherOpen }">
			<input
				:id="split.cards.length === 0 ? id : `${id}-other`"
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
				:aria-controls="`${id}-rest`"
				:aria-expanded="otherOpen ? 'true' : 'false'"
				:data-testid="`${id}-choice-other`"
				@change="openOther" />
			<span :id="`${id}-other-text`" class="pq-choice-card__label">{{
				otherLabel
			}}</span>
		</label>
		<div v-if="hasOther && otherOpen" class="pq-choice-cards__rest">
			<label :for="`${id}-rest`" class="utrecht-form-label">
				{{ otherLabel }}
			</label>
			<select
				:id="`${id}-rest`"
				class="utrecht-select utrecht-select--html-select"
				:class="{ 'utrecht-select--invalid': invalid }"
				:value="inRest ? modelValue : ''"
				:disabled="disabled"
				:aria-required="required ? 'true' : undefined"
				:aria-invalid="invalid ? 'true' : undefined"
				:data-testid="`${id}-rest`"
				@change="$emit('update:modelValue', $event.target.value)">
				<option value="">{{ selectPlaceholder }}</option>
				<option
					v-for="option in split.rest"
					:key="String(option.value)"
					:value="String(option.value)">
					{{ option.label }}
				</option>
			</select>
		</div>
	</div>
</template>

<script>
import { choiceSplit } from './fields.js'

import '@utrecht/radio-button-css/dist/index.css'
import '@utrecht/select-css/dist/index.css'

/**
 * The NL Design System "Radio Group" as cards: one radio per option, each in
 * a card with its label, inside the fieldset FieldShell draws. It sends the
 * same value the select it replaces would.
 *
 * With `choiceOptions` only that subset shows as cards, plus one card with
 * the `otherLabel`; choosing it reveals the remaining options in a select.
 * The first radio carries the field's own id, so a summary link lands on it.
 *
 * @spec openspec/changes/site-multi-step-forms/specs/site-forms/spec.md#requirement-an-action-field-may-ask-for-choice-cards-or-named-days-req-smf-005
 */
export default {
	name: 'ChoiceCards',

	props: {
		/** The field's id: the radio group's name and the first radio's id. */
		id: { type: String, required: true },
		/** The field's options, `[{value, label}]`. */
		options: { type: Array, default: () => [] },
		/** The value. */
		modelValue: { type: String, default: '' },
		/** The option values to show as cards; empty shows every option. */
		choiceOptions: { type: Array, default: () => [] },
		/** The label of the card that reveals the other options. */
		otherLabel: { type: String, default: '' },
		/** Whether an answer is required. */
		required: { type: Boolean, default: false },
		/** Whether the field has an error. */
		invalid: { type: Boolean, default: false },
		/** Whether the cards are disabled. */
		disabled: { type: Boolean, default: false },
		/** The first, empty choice of the select behind the "other" card. */
		selectPlaceholder: { type: String, default: 'Maak een keuze' },
	},

	emits: ['update:modelValue'],

	data() {
		return { otherChosen: false }
	},

	computed: {
		/**
		 * The cards and the options behind the "other" card.
		 *
		 * @return {{cards: Array, rest: Array}} The split.
		 *
		 * @spec openspec/changes/site-multi-step-forms/specs/site-forms/spec.md#requirement-an-action-field-may-ask-for-choice-cards-or-named-days-req-smf-005
		 */
		split() {
			return choiceSplit(this.options, this.choiceOptions)
		},

		/**
		 * Whether there is an "other" card: a subset, a label and options left.
		 *
		 * @return {boolean} True to show it.
		 */
		hasOther() {
			return this.otherLabel !== '' && this.split.rest.length > 0
		},

		/**
		 * Whether the value is one of the options behind the "other" card.
		 *
		 * @return {boolean} True when it is.
		 */
		inRest() {
			return this.split.rest.some(
				(option) => String(option.value) === this.modelValue,
			)
		},

		/**
		 * Whether the "other" card is chosen and its select shows.
		 *
		 * @return {boolean} True when open.
		 */
		otherOpen() {
			return this.otherChosen || this.inRest
		},
	},

	methods: {
		/**
		 * Whether a card's option is the value.
		 *
		 * @param {object} option The option.
		 * @return {boolean} True when chosen.
		 */
		isChecked(option) {
			return !this.otherOpen && String(option.value) === this.modelValue
		},

		/**
		 * Choose a card.
		 *
		 * @param {object} option The option.
		 * @return {void}
		 *
		 * @spec openspec/changes/site-multi-step-forms/specs/site-forms/spec.md#requirement-an-action-field-may-ask-for-choice-cards-or-named-days-req-smf-005
		 */
		choose(option) {
			this.otherChosen = false
			this.$emit('update:modelValue', String(option.value))
		},

		/**
		 * Choose the "other" card: the select appears, and a card's value is
		 * cleared until one of the other options is picked.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/site-multi-step-forms/specs/site-forms/spec.md#requirement-an-action-field-may-ask-for-choice-cards-or-named-days-req-smf-005
		 */
		openOther() {
			this.otherChosen = true
			if (!this.inRest) {
				this.$emit('update:modelValue', '')
			}
		},
	},
}
</script>

<style scoped>
.pq-choice-cards {
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

.pq-choice-cards__rest {
	display: flex;
	flex-direction: column;
	gap: var(--utrecht-space-block-3xs, 0.25rem);
	margin-block-start: var(--utrecht-space-block-xs, 0.5rem);
	max-inline-size: 32rem;
}
</style>
