<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	A collection's rows as bars against a maximum (site-school-blocks,
	`display: bars`): "Rekenen ▬▬▬▬ 7,9". The figure is text; the bar is
	decorative. Under the bars, one note from the first row ("Van de
	leerkracht"), and a caption in the head from the first row too.
-->
<template>
	<section
		class="pq-bars"
		:aria-labelledby="label ? headingId : undefined"
		data-testid="mijn-bars">
		<component
			:is="`h${level}`"
			v-if="label"
			:id="headingId"
			class="utrecht-heading-3">
			{{ label }}
		</component>
		<Skeleton v-if="loading && bars.length === 0" :label="tr('Loading')" />
		<EmptyState v-else-if="bars.length === 0" :text="tr('Nothing here yet.')" />
		<div v-else class="pq-bars__card">
			<p v-if="caption" class="pq-bars__caption">{{ caption }}</p>
			<ul class="pq-bars__list">
				<li
					v-for="bar in bars"
					:key="bar.key"
					class="pq-bars__row"
					data-testid="mijn-bar">
					<span class="pq-bars__label">{{ bar.label }}</span>
					<span class="pq-bars__track" aria-hidden="true"
						><span :style="{ inlineSize: bar.width }"
					/></span>
					<span class="pq-bars__value">{{ bar.text }}</span>
				</li>
			</ul>
			<div v-if="note" class="pq-bars__note">
				<p v-if="block.noteLabel" class="pq-bars__note-label">
					{{ block.noteLabel }}
				</p>
				<p class="pq-bars__note-text">{{ note }}</p>
			</div>
		</div>
	</section>
</template>

<script>
import EmptyState from './EmptyState.vue'
import Skeleton from './Skeleton.vue'
import { barRows, textOf } from './displays.js'
import { mijnTranslator } from './rows.js'

let counter = 0

/**
 * @spec openspec/changes/site-school-blocks/specs/portal-contribution-contract/spec.md#requirement-a-collection-block-may-draw-its-rows-as-dated-rows-bars-chips-or-richer-cards
 */
export default {
	name: 'GradeBars',

	components: { EmptyState, Skeleton },

	props: {
		/** The rows. */
		rows: { type: Array, required: true },
		/** The block, with its display keys. */
		block: { type: Object, required: true },
		/** The collection, for its value labels. */
		collection: { type: Object, default: null },
		/** The heading. */
		label: { type: String, default: '' },
		/** The heading level. */
		level: { type: Number, default: 2 },
		/** Whether the rows are still loading. */
		loading: { type: Boolean, default: false },
		/** The site translator. */
		t: { type: Function, default: null },
		/** The page language. */
		locale: { type: String, default: 'nl' },
	},

	data() {
		counter += 1
		return { headingId: `pq-bars-${counter}` }
	},

	computed: {
		/**
		 * @return {Function} The translator.
		 * @spec openspec/changes/site-school-blocks/specs/portal-contribution-contract/spec.md#requirement-a-collection-block-may-draw-its-rows-as-dated-rows-bars-chips-or-richer-cards
		 */
		tr() {
			return mijnTranslator(this.t, this.locale)
		},

		/**
		 * @return {Array<object>} The bars.
		 * @spec openspec/changes/site-school-blocks/specs/portal-contribution-contract/spec.md#requirement-a-collection-block-may-draw-its-rows-as-dated-rows-bars-chips-or-richer-cards
		 */
		bars() {
			return barRows(this.rows, this.block, this.locale, this.collection)
		},

		/**
		 * @return {string} The first row's note.
		 * @spec openspec/changes/site-school-blocks/specs/portal-contribution-contract/spec.md#requirement-a-collection-block-may-draw-its-rows-as-dated-rows-bars-chips-or-richer-cards
		 */
		note() {
			return textOf(this.rows[0], this.block.noteField, this.collection)
		},

		/**
		 * @return {string} The first row's caption.
		 * @spec openspec/changes/site-school-blocks/specs/portal-contribution-contract/spec.md#requirement-a-collection-block-may-draw-its-rows-as-dated-rows-bars-chips-or-richer-cards
		 */
		caption() {
			return textOf(this.rows[0], this.block.captionField, this.collection)
		},
	},
}
</script>

<style scoped>
.pq-bars {
	margin-block-end: var(--utrecht-space-block-lg, 1.5rem);
}

.pq-bars__card {
	padding: 0 1.5rem 1.5rem;
	border: 1px solid
		var(--nldesign-color-border-dark, var(--utrecht-color-grey-80, currentcolor));
	border-radius: var(
		--nldesign-website-border-radius-large,
		var(--utrecht-border-radius-md, 0.75rem)
	);
	overflow: hidden;
}

.pq-bars__caption {
	margin: 0 -1.5rem 0.5rem;
	padding: 1rem 1.5rem;
	background: var(
		--nldesign-color-background-hover,
		var(--utrecht-color-grey-90, transparent)
	);
	color: var(
		--nldesign-color-text-muted,
		var(--utrecht-document-color, CanvasText)
	);
}

.pq-bars__list {
	margin: 0;
	padding: 0;
	list-style: none;
}

.pq-bars__row {
	display: grid;
	grid-template-columns: minmax(8rem, 1fr) minmax(6rem, 1.4fr) 3rem;
	align-items: center;
	gap: 1rem;
	padding-block: 0.75rem;
	border-block-end: 1px solid
		var(--nldesign-color-border, var(--utrecht-color-grey-90, currentcolor));
}

.pq-bars__track {
	display: block;
	block-size: 0.5rem;
	border-radius: 0.25rem;
	background: var(
		--nldesign-color-border,
		var(--utrecht-color-grey-90, transparent)
	);
	overflow: hidden;
}

.pq-bars__track > span {
	display: block;
	block-size: 100%;
	background: var(
		--nldesign-color-primary,
		var(--utrecht-button-primary-action-background-color, currentcolor)
	);
}

.pq-bars__value {
	font-weight: 700;
	text-align: end;
}

.pq-bars__note {
	margin-block-start: 1rem;
}

.pq-bars__note-label {
	margin: 0 0 0.25rem;
	font-size: 0.8125rem;
	font-weight: 700;
	letter-spacing: 0.06em;
	text-transform: uppercase;
}

.pq-bars__note-text {
	margin: 0;
}
</style>
