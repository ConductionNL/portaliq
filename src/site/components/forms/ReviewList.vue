<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<div class="pq-review" data-testid="review-list">
		<section
			v-for="section in sections"
			:key="section.index"
			class="pq-review__section"
			:data-testid="`review-step-${section.index}`">
			<div class="pq-review__head">
				<h3 class="utrecht-heading-3 pq-review__title">
					{{ section.title }}
				</h3>
				<button
					type="button"
					class="utrecht-button utrecht-button--subtle pq-review__edit"
					:aria-label="editAria(section)"
					:data-testid="`review-edit-${section.index}`"
					@click="$emit('edit', section.index)">
					{{ editLabel }}
				</button>
			</div>
			<dl class="pq-review__list">
				<div
					v-for="row in section.rows"
					:key="row.field"
					class="pq-review__row">
					<dt class="pq-review__question">{{ row.label }}</dt>
					<dd
						class="pq-review__answer"
						:data-testid="`review-answer-${row.field}`">
						{{ row.value !== '' ? row.value : emptyLabel }}
					</dd>
				</div>
			</dl>
		</section>
	</div>
</template>

<script>
/**
 * The NL Design System "Form Summary" before sending: every visible answer
 * under its question, grouped per step, with a "Wijzigen" button that
 * reopens that step. The button's name says which step ("Stap 2 wijzigen").
 *
 * @spec openspec/changes/site-multi-step-forms/specs/portal-intake-form/spec.md#requirement-a-form-with-steps-must-end-with-a-review-and-a-confirmation-req-smf-011
 */
export default {
	name: 'ReviewList',

	props: {
		/** `[{index, title, rows: [{field, label, value}]}]`, one per step. */
		sections: { type: Array, required: true },
		/** The edit button's words. */
		editLabel: { type: String, default: 'Wijzigen' },
		/** Its accessible name, with `{n}` and `{title}`. */
		editPattern: { type: String, default: 'Stap {n} wijzigen' },
		/** Shown for a question left empty. */
		emptyLabel: { type: String, default: 'Niet ingevuld' },
	},

	emits: ['edit'],

	methods: {
		/**
		 * The edit button's accessible name for one step.
		 *
		 * @param {object} section The step's section.
		 * @return {string} The name.
		 *
		 * @spec openspec/changes/site-multi-step-forms/specs/portal-intake-form/spec.md#requirement-a-form-with-steps-must-end-with-a-review-and-a-confirmation-req-smf-011
		 */
		editAria(section) {
			return this.editPattern
				.split('{n}')
				.join(String(section.index + 1))
				.split('{title}')
				.join(section.title)
		},
	},
}
</script>

<style scoped>
.pq-review__section {
	border-block-end: 1px solid var(--utrecht-document-color, #222);
	padding-block: var(--utrecht-space-block-md, 1rem);
}

.pq-review__head {
	align-items: baseline;
	display: flex;
	flex-wrap: wrap;
	gap: var(--utrecht-space-inline-md, 1rem);
	justify-content: space-between;
}

.pq-review__title {
	margin-block: 0;
}

.pq-review__list {
	margin-block: var(--utrecht-space-block-xs, 0.5rem) 0;
}

.pq-review__row {
	display: grid;
	gap: var(--utrecht-space-inline-sm, 0.5rem);
	grid-template-columns: minmax(10rem, 1fr) 2fr;
	padding-block: var(--utrecht-space-block-3xs, 0.25rem);
}

.pq-review__question {
	font-weight: var(--utrecht-typography-weight-scale-bold, bold);
}

.pq-review__answer {
	margin: 0;
	overflow-wrap: anywhere;
}

@media (max-width: 30rem) {
	.pq-review__row {
		grid-template-columns: 1fr;
	}
}
</style>
