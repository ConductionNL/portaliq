<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<div class="pq-count" data-testid="count-stepper">
		<div class="pq-count__row">
			<button
				type="button"
				class="utrecht-button utrecht-button--secondary-action pq-count__step"
				:disabled="disabled || current <= low"
				:aria-label="fewerLabel"
				:aria-controls="id"
				:data-testid="`${id}-fewer`"
				@click="step(-1)">
				<span aria-hidden="true">−</span>
			</button>
			<input
				:id="id"
				type="number"
				inputmode="numeric"
				class="utrecht-textbox utrecht-textbox--html-input pq-count__input"
				:class="{ 'utrecht-textbox--invalid': invalid }"
				:value="modelValue"
				:min="low"
				:max="high"
				:disabled="disabled"
				:aria-required="required ? 'true' : undefined"
				:aria-invalid="invalid ? 'true' : undefined"
				:aria-labelledby="labelledBy"
				:aria-describedby="describedBy"
				@change="set($event.target.value)" />
			<button
				type="button"
				class="utrecht-button utrecht-button--secondary-action pq-count__step"
				:disabled="disabled || current >= high"
				:aria-label="moreLabel"
				:aria-controls="id"
				:data-testid="`${id}-more`"
				@click="step(1)">
				<span aria-hidden="true">+</span>
			</button>
		</div>
		<p
			v-if="line !== ''"
			:id="`${id}-line`"
			class="pq-count__line"
			aria-live="polite"
			:data-testid="`${id}-line`">
			{{ line }}
		</p>
	</div>
</template>

<script>
import { countLine, countValue } from './fields.js'

/**
 * A count asked with a stepper (school-design warmtepompacademie, Artikel:
 * "Aantal deelnemers" with − and +, and "3 deelnemers × [PRIJS]" under it).
 * The value sent is the whole number, as a typed number would be; the
 * buttons and the line are presentation only.
 *
 * @spec openspec/changes/count-field/specs/site-forms/spec.md#requirement-an-action-field-may-ask-for-a-count-with-a-stepper
 */
export default {
	name: 'CountStepper',

	props: {
		/** The input's id; the field label points at it. */
		id: { type: String, required: true },
		/** The value, a whole number as a string, or ''. */
		modelValue: { type: String, default: '' },
		/** The lowest count. */
		min: { type: Number, default: 1 },
		/** The highest count. */
		max: { type: Number, default: 99 },
		/** The unit in its two forms, `{one, other}`. */
		unit: { type: Object, default: () => ({}) },
		/** The price per unit as authored text, or ''. */
		priceLabel: { type: String, default: '' },
		/** The id of the field label. */
		labelledBy: { type: String, default: '' },
		/** The ids of the description and error, or ''. */
		describedBy: { type: String, default: '' },
		/** Whether the field is required. */
		required: { type: Boolean, default: false },
		/** Whether the field has an error. */
		invalid: { type: Boolean, default: false },
		/** Whether the field is disabled. */
		disabled: { type: Boolean, default: false },
		/** The accessible name of the − button. */
		fewerLabel: { type: String, default: 'One less' },
		/** The accessible name of the + button. */
		moreLabel: { type: String, default: 'One more' },
	},

	emits: ['update:modelValue'],

	computed: {
		low() {
			return this.min
		},

		high() {
			return this.max >= this.min ? this.max : this.min
		},

		current() {
			return Number.parseInt(
				countValue(this.modelValue, this.low, this.high),
				10,
			)
		},

		line() {
			return countLine(this.current, this.unit, this.priceLabel)
		},
	},

	mounted() {
		// A stepper always holds a count: the form sends `min` when nothing was chosen.
		if (this.modelValue === '') {
			this.$emit('update:modelValue', String(this.low))
		}
	},

	methods: {
		step(delta) {
			this.set(this.current + delta)
		},

		set(value) {
			this.$emit('update:modelValue', countValue(value, this.low, this.high))
		},
	},
}
</script>

<style scoped>
.pq-count__row {
	display: flex;
	gap: var(--utrecht-space-inline-sm, 0.5rem);
	align-items: stretch;
}

.pq-count__input {
	inline-size: 5rem;
	text-align: center;
}

.pq-count__step {
	min-inline-size: 2.75rem;
}

.pq-count__line {
	margin-block: var(--utrecht-space-block-xs, 0.25rem) 0;
	color: var(--utrecht-form-field-description-color, inherit);
}
</style>
