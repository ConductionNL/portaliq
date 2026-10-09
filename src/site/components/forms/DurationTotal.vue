<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<p
		:id="id"
		class="pq-duration-total"
		aria-live="polite"
		data-testid="duration-total">
		<span class="pq-duration-total__label">{{ label }}</span>
		<output class="pq-duration-total__value">
			{{ text }}
		</output>
	</p>
</template>

<script>
import { hoursText } from './dayEntry.js'

/**
 * A total the form works out itself, shown as a line and never typed: the
 * hours between two times minus a pause ("Dat is vandaag 8 uur"). It speaks
 * politely when it changes, so a screen reader hears the new total without
 * losing its place. While a time is missing it says so.
 *
 * @spec openspec/changes/action-form-day-entry/specs/site-forms/spec.md
 */
export default {
	name: 'DurationTotal',

	props: {
		/** The field's id. */
		id: { type: String, required: true },
		/** The words before the total ("Dat is vandaag"). */
		label: { type: String, default: '' },
		/** The hours as text, or '' while a time is missing. */
		modelValue: { type: [String, Number], default: '' },
		/** The page's language. */
		locale: { type: String, default: 'nl' },
		/** The translator. */
		t: { type: Function, required: true },
	},

	computed: {
		/**
		 * The total in words: "8 uur", or what is still missing.
		 *
		 * @return {string} The text.
		 */
		text() {
			const hours = hoursText(this.modelValue, this.locale)
			if (hours === '') {
				return this.t('Fill in the start and end time')
			}
			return this.t('{hours} hours', { hours })
		},
	},
}
</script>

<style scoped>
.pq-duration-total {
	align-items: baseline;
	background-color: var(--nldesign-color-primary-light, transparent);
	border-radius: var(--utrecht-form-control-border-radius, 0.25rem);
	display: flex;
	gap: var(--utrecht-space-inline-md, 1rem);
	justify-content: space-between;
	margin: 0;
	max-inline-size: 32rem;
	padding-block: var(--utrecht-space-block-md, 1rem);
	padding-inline: var(--utrecht-space-inline-md, 1rem);
}

.pq-duration-total__value {
	font-size: var(--utrecht-heading-3-font-size, 1.5rem);
	font-weight: var(--utrecht-typography-weight-scale-bold-font-weight, bold);
}
</style>
