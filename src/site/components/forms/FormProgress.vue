<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<nav class="pq-form-progress" :aria-label="label" data-testid="form-progress">
		<p
			class="utrecht-paragraph pq-form-progress__short"
			data-testid="form-progress-short">
			{{ shortText }}
		</p>
		<button
			type="button"
			class="utrecht-button utrecht-button--subtle pq-form-progress__toggle"
			:aria-expanded="open ? 'true' : 'false'"
			:aria-controls="`${idBase}-list`"
			data-testid="form-progress-toggle"
			@click="open = !open">
			{{ open ? hideLabel : showLabel }}
		</button>
		<ol
			:id="`${idBase}-list`"
			class="pq-form-progress__list"
			:class="{ 'pq-form-progress__list--open': open }"
			data-testid="form-progress-list">
			<li
				v-for="(step, index) in steps"
				:key="step.id || index"
				class="pq-form-progress__item"
				:class="`pq-form-progress__item--${stateOf(index)}`"
				:aria-current="index === current ? 'step' : undefined"
				:data-testid="`form-progress-step-${index}`">
				<span class="pq-form-progress__marker" aria-hidden="true">{{
					index + 1
				}}</span>
				<span class="pq-form-progress__title">{{ step.title }}</span>
				<span class="pq-form-progress__state">{{ stateText(index) }}</span>
			</li>
		</ol>
	</nav>
</template>

<script>
/**
 * The NL Design System "Progress List" for a form in steps: every step with
 * its state in words (done, current, to do), the current one with
 * `aria-current="step"`. On a phone it reads "Stap 2 van 4" and the list
 * sits behind a button; on a wider screen the list always shows.
 *
 * @spec openspec/changes/site-multi-step-forms/specs/portal-intake-form/spec.md#requirement-a-published-form-with-steps-must-be-filled-in-one-step-at-a-time-with-visible-progress-req-smf-010
 */
export default {
	name: 'FormProgress',

	props: {
		/** The steps, `[{id, title}]`. */
		steps: { type: Array, required: true },
		/** The current step's index. */
		current: { type: Number, required: true },
		/** A unique id base. */
		idBase: { type: String, default: 'pq-form-progress' },
		/** The landmark's name. */
		label: { type: String, default: 'Voortgang' },
		/** "Stap {n} van {m}". */
		shortPattern: { type: String, default: 'Stap {n} van {m}' },
		/** The button that shows the list on a phone. */
		showLabel: { type: String, default: 'Toon alle stappen' },
		/** The button that hides it again. */
		hideLabel: { type: String, default: 'Verberg de stappen' },
		/** The state words. */
		doneLabel: { type: String, default: 'Klaar' },
		currentLabel: { type: String, default: 'Huidige stap' },
		todoLabel: { type: String, default: 'Nog te doen' },
	},

	data() {
		return { open: false }
	},

	computed: {
		/**
		 * "Stap 2 van 4".
		 *
		 * @return {string} The short progress.
		 *
		 * @spec openspec/changes/site-multi-step-forms/specs/portal-intake-form/spec.md#requirement-a-published-form-with-steps-must-be-filled-in-one-step-at-a-time-with-visible-progress-req-smf-010
		 */
		shortText() {
			return this.shortPattern
				.split('{n}')
				.join(String(this.current + 1))
				.split('{m}')
				.join(String(this.steps.length))
		},
	},

	methods: {
		/**
		 * A step's state.
		 *
		 * @param {number} index The step's index.
		 * @return {string} done, current or todo.
		 *
		 * @spec openspec/changes/site-multi-step-forms/specs/portal-intake-form/spec.md#requirement-a-published-form-with-steps-must-be-filled-in-one-step-at-a-time-with-visible-progress-req-smf-010
		 */
		stateOf(index) {
			if (index < this.current) {
				return 'done'
			}
			return index === this.current ? 'current' : 'todo'
		},

		/**
		 * A step's state in words, so it never rests on colour alone.
		 *
		 * @param {number} index The step's index.
		 * @return {string} The words.
		 *
		 * @spec openspec/changes/site-multi-step-forms/specs/portal-intake-form/spec.md#requirement-a-published-form-with-steps-must-be-filled-in-one-step-at-a-time-with-visible-progress-req-smf-010
		 */
		stateText(index) {
			return {
				done: this.doneLabel,
				current: this.currentLabel,
				todo: this.todoLabel,
			}[this.stateOf(index)]
		},
	},
}
</script>

<style scoped>
.pq-form-progress {
	margin-block-end: var(--utrecht-space-block-md, 1rem);
}

.pq-form-progress__short {
	font-weight: var(--utrecht-typography-weight-scale-bold, bold);
	margin-block: 0;
}

.pq-form-progress__list {
	display: none;
	list-style: none;
	margin-block: var(--utrecht-space-block-xs, 0.5rem) 0;
	padding: 0;
}

.pq-form-progress__list--open {
	display: block;
}

.pq-form-progress__item {
	align-items: baseline;
	display: flex;
	flex-wrap: wrap;
	gap: var(--utrecht-space-inline-sm, 0.5rem);
	padding-block: var(--utrecht-space-block-3xs, 0.25rem);
}

.pq-form-progress__marker {
	border: 2px solid currentColor;
	border-radius: 50%;
	display: inline-grid;
	font-weight: var(--utrecht-typography-weight-scale-bold, bold);
	inline-size: 1.75rem;
	block-size: 1.75rem;
	place-items: center;
}

.pq-form-progress__item--current .pq-form-progress__title {
	font-weight: var(--utrecht-typography-weight-scale-bold, bold);
}

.pq-form-progress__item--current .pq-form-progress__marker {
	background: var(--utrecht-document-color, #222);
	color: var(--utrecht-document-background-color, #fff);
}

.pq-form-progress__state {
	font-size: 0.875em;
}

@media (min-width: 48rem) {
	.pq-form-progress__short,
	.pq-form-progress__toggle {
		display: none;
	}

	.pq-form-progress__list {
		display: block;
	}
}
</style>
