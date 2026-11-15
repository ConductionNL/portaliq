<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	A collection's rows as marks per subject (site-school-blocks,
	`display: chips`): the subject, each mark as a chip, and the average. A
	mark below the block's `lowBelow` gets the warning tint AND the word
	"onvoldoende" for assistive technology, so the tint is never the only
	signal (WCAG 1.4.1).
-->
<template>
	<section
		class="pq-chips"
		:aria-labelledby="label ? headingId : undefined"
		data-testid="mijn-chips">
		<component
			:is="`h${level}`"
			v-if="label"
			:id="headingId"
			class="utrecht-heading-3">
			{{ label }}
		</component>
		<Skeleton v-if="loading && entries.length === 0" :label="tr('Loading')" />
		<EmptyState
			v-else-if="entries.length === 0"
			:text="tr('Nothing here yet.')" />
		<ul v-else class="pq-chips__list">
			<li
				v-for="entry in entries"
				:key="entry.key"
				class="pq-chips__row"
				data-testid="mijn-chip-row">
				<span class="pq-chips__label">{{ entry.label }}</span>
				<span class="pq-chips__marks">
					<span
						v-for="(mark, index) in entry.marks"
						:key="index"
						class="pq-chips__mark"
						:class="{ 'pq-chips__mark--low': mark.low }">
						{{ mark.text
						}}<span v-if="mark.low" class="pq-chips__hidden">
							({{ tr('below the pass mark') }})</span
						>
					</span>
				</span>
				<span
					v-if="entry.average"
					class="pq-chips__average"
					:class="{ 'pq-chips__average--low': entry.averageLow }">
					<span class="pq-chips__hidden">{{ tr('Average') }}: </span
					>{{ entry.average }}
				</span>
			</li>
		</ul>
	</section>
</template>

<script>
import EmptyState from './EmptyState.vue'
import Skeleton from './Skeleton.vue'
import { chipRows } from './displays.js'
import { mijnTranslator } from './rows.js'

let counter = 0

/**
 * @spec openspec/changes/site-school-blocks/specs/portal-contribution-contract/spec.md#requirement-a-collection-block-may-draw-its-rows-as-dated-rows-bars-chips-or-richer-cards
 */
export default {
	name: 'MarkChips',

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
		return { headingId: `pq-chips-${counter}` }
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
		 * @return {Array<object>} The rows.
		 * @spec openspec/changes/site-school-blocks/specs/portal-contribution-contract/spec.md#requirement-a-collection-block-may-draw-its-rows-as-dated-rows-bars-chips-or-richer-cards
		 */
		entries() {
			return chipRows(this.rows, this.block, this.locale, this.collection)
		},
	},
}
</script>

<style scoped>
.pq-chips {
	margin-block-end: var(--utrecht-space-block-lg, 1.5rem);
}

.pq-chips__list {
	margin: 0;
	padding: 0;
	list-style: none;
	border-block-start: 1px solid
		var(--nldesign-color-border, var(--utrecht-color-grey-90, currentcolor));
}

.pq-chips__row {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 0.5rem 1rem;
	padding-block: 0.875rem;
	border-block-end: 1px solid
		var(--nldesign-color-border, var(--utrecht-color-grey-90, currentcolor));
}

.pq-chips__label {
	flex: 0 0 12rem;
	font-weight: 600;
}

.pq-chips__marks {
	flex: 1;
	display: flex;
	flex-wrap: wrap;
	gap: 0.375rem;
}

.pq-chips__mark {
	min-inline-size: 2.5rem;
	padding: 0.125rem 0.5rem;
	border-radius: var(--nldesign-website-border-radius, 0.375rem);
	background: var(
		--nldesign-color-background-hover,
		var(--utrecht-color-grey-90, transparent)
	);
	text-align: center;
}

.pq-chips__mark--low,
.pq-chips__average--low {
	background: var(
		--nldesign-component-status-badge-error-background-color,
		transparent
	);
	color: var(
		--nldesign-component-status-badge-error-color,
		var(--utrecht-document-color, CanvasText)
	);
}

.pq-chips__average {
	padding: 0.125rem 0.625rem;
	border-radius: var(--nldesign-website-border-radius, 0.375rem);
	font-weight: 700;
}

.pq-chips__hidden {
	position: absolute;
	inline-size: 1px;
	block-size: 1px;
	overflow: hidden;
	clip-path: inset(50%);
	white-space: nowrap;
}
</style>
