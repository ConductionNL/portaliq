<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<div class="pq-check-cards" data-testid="check-cards">
		<label
			v-for="(option, index) in visible"
			:key="String(option.value)"
			class="pq-check-card"
			:class="{ 'pq-check-card--checked': isChecked(option) }">
			<input
				:id="index === 0 ? id : `${id}-${index}`"
				:aria-labelledby="`${id}-${index}-text`"
				class="utrecht-checkbox utrecht-checkbox--html-input"
				:class="{ 'utrecht-checkbox--invalid': invalid }"
				type="checkbox"
				:name="id"
				:value="String(option.value)"
				:checked="isChecked(option)"
				:disabled="disabled"
				:aria-invalid="invalid ? 'true' : undefined"
				:data-testid="`${id}-check-${option.value}`"
				@change="toggle(option)" />
			<span :id="`${id}-${index}-text`" class="pq-check-card__text">
				<span v-if="option.note" class="pq-check-card__note">{{
					option.note
				}}</span>
				<span class="pq-check-card__label">{{ option.label }}</span>
			</span>
		</label>
		<button
			v-if="hidden > 0"
			type="button"
			class="utrecht-button utrecht-button--subtle-action pq-check-cards__more"
			:data-testid="`${id}-show-all`"
			@click="all = true">
			{{ showAllLabel }}
		</button>
	</div>
</template>

<script>
import { checkedValues, toggledValues } from './dayEntry.js'

import '@utrecht/checkbox-css/dist/index.css'

/**
 * The NL Design System "Checkbox Group" as cards: one checkbox per option,
 * each in a card with its label and, when the option has one, a short line
 * above it (a work process code). More than one card may be ticked; the value
 * is the list of ticked values, in the order of the options.
 *
 * With `shown`, only that many cards show until "show all" is pressed; a card
 * that is ticked always shows.
 *
 * @spec openspec/changes/action-form-day-entry/specs/site-forms/spec.md
 */
export default {
	name: 'CheckCards',

	props: {
		/** The field's id: the group's name and the first checkbox's id. */
		id: { type: String, required: true },
		/** The options, `[{value, label, note?}]`. */
		options: { type: Array, default: () => [] },
		/** The ticked values, or '' before anything is ticked. */
		modelValue: { type: [Array, String], default: () => [] },
		/** How many cards show before "show all"; 0 shows every card. */
		shown: { type: Number, default: 0 },
		/** The words of the "show all" button, with the number in them. */
		showAllLabel: { type: String, default: '' },
		/** Whether the field has an error. */
		invalid: { type: Boolean, default: false },
		/** Whether the cards are disabled. */
		disabled: { type: Boolean, default: false },
	},

	emits: ['update:modelValue'],

	data() {
		return { all: false }
	},

	computed: {
		/**
		 * The cards that show: all of them, or the first `shown` plus every
		 * ticked one.
		 *
		 * @return {Array<object>} The options.
		 */
		visible() {
			if (this.all || this.shown <= 0) {
				return this.options
			}
			const chosen = checkedValues(this.modelValue)
			return this.options.filter(
				(option, index) =>
					index < this.shown || chosen.includes(String(option.value)),
			)
		},

		/**
		 * How many cards "show all" would add.
		 *
		 * @return {number} The count.
		 */
		hidden() {
			return this.options.length - this.visible.length
		},
	},

	methods: {
		/**
		 * Whether a card is ticked.
		 *
		 * @param {object} option The option.
		 * @return {boolean} True when ticked.
		 */
		isChecked(option) {
			return checkedValues(this.modelValue).includes(String(option.value))
		},

		/**
		 * Tick or untick one card.
		 *
		 * @param {object} option The option.
		 * @return {void}
		 */
		toggle(option) {
			this.$emit(
				'update:modelValue',
				toggledValues(this.modelValue, this.options, option.value),
			)
		},
	},
}
</script>

<style scoped>
.pq-check-cards {
	display: flex;
	flex-direction: column;
	gap: var(--utrecht-space-block-xs, 0.5rem);
}

.pq-check-card {
	align-items: flex-start;
	border: var(--utrecht-form-control-border-width, 1px) solid
		var(--utrecht-form-control-border-color, currentColor);
	border-radius: var(--utrecht-form-control-border-radius, 0.25rem);
	cursor: pointer;
	display: flex;
	gap: var(--utrecht-space-inline-sm, 0.75rem);
	max-inline-size: 32rem;
	padding-block: var(--utrecht-space-block-sm, 0.75rem);
	padding-inline: var(--utrecht-space-inline-md, 1rem);
}

.pq-check-card--checked {
	background-color: var(--nldesign-color-primary-light, transparent);
	border-color: var(--utrecht-focus-outline-color, currentColor);
	border-width: 2px;
}

.pq-check-card:focus-within {
	outline: var(--utrecht-focus-outline-width, 2px)
		var(--utrecht-focus-outline-style, solid)
		var(--utrecht-focus-outline-color, currentColor);
	outline-offset: 2px;
}

.pq-check-card__text {
	display: flex;
	flex-direction: column;
	gap: var(--utrecht-space-block-3xs, 0.125rem);
}

.pq-check-card__note {
	font-family: var(--utrecht-code-font-family, monospace);
	font-size: var(--utrecht-typography-scale-sm-font-size, 0.875rem);
}

.pq-check-card--checked .pq-check-card__label {
	font-weight: var(--utrecht-typography-weight-scale-bold-font-weight, bold);
}

.pq-check-cards__more {
	align-self: flex-start;
}
</style>
