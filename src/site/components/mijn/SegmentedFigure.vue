<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	One row's figure as a segmented bar (site-school-blocks, a `kpi` block
	with `display: segmented`): "96 van 480 uur", a bar of approved, waiting
	and returned, and a legend that says each part in words with its number.
	The bar is decorative; the legend carries the meaning.
-->
<template>
	<section
		class="pq-segments"
		:aria-labelledby="label ? headingId : undefined"
		data-testid="mijn-segments">
		<component
			:is="`h${level}`"
			v-if="label"
			:id="headingId"
			class="utrecht-heading-3">
			{{ label }}
		</component>
		<Skeleton v-if="loading && !figure" :label="tr('Loading')" />
		<EmptyState v-else-if="!figure" :text="tr('Nothing here yet.')" />
		<div v-else class="pq-segments__card">
			<p class="pq-segments__total">
				<strong class="pq-segments__number">{{
					figure.segments[0] ? figure.segments[0].value : 0
				}}</strong>
				{{
					tr('of {total} {unit}', {
						total: figure.total,
						unit: block.unit || '',
					}).trim()
				}}
			</p>
			<span class="pq-segments__bar" aria-hidden="true">
				<span
					v-for="segment in figure.segments"
					:key="segment.field"
					:class="`pq-segments__part pq-segments__part--${segment.tone}`"
					:style="{ inlineSize: segment.width }" />
			</span>
			<ul class="pq-segments__legend">
				<li
					v-for="segment in figure.segments"
					:key="segment.field"
					class="pq-segments__item"
					data-testid="mijn-segment">
					<span
						:class="`pq-segments__dot pq-segments__part--${segment.tone}`"
						aria-hidden="true" />
					{{ segment.label }}: <strong>{{ segment.value }}</strong>
				</li>
			</ul>
		</div>
	</section>
</template>

<script>
import EmptyState from './EmptyState.vue'
import Skeleton from './Skeleton.vue'
import { segmentsOf } from './displays.js'
import { mijnTranslator } from './rows.js'

let counter = 0

/**
 * @spec openspec/changes/site-school-blocks/specs/portal-contribution-contract/spec.md#requirement-the-overview-blocks-may-take-the-school-displays
 */
export default {
	name: 'SegmentedFigure',

	components: { EmptyState, Skeleton },

	props: {
		/** The one row the figure reads. */
		row: { type: Object, default: null },
		/** The kpi block, with its segments. */
		block: { type: Object, required: true },
		/** The heading. */
		label: { type: String, default: '' },
		/** The heading level. */
		level: { type: Number, default: 2 },
		/** Whether the row is still loading. */
		loading: { type: Boolean, default: false },
		/** The site translator. */
		t: { type: Function, default: null },
		/** The page language. */
		locale: { type: String, default: 'nl' },
	},

	data() {
		counter += 1
		return { headingId: `pq-segments-${counter}` }
	},

	computed: {
		/**
		 * @return {Function} The translator.
		 * @spec openspec/changes/site-school-blocks/specs/portal-contribution-contract/spec.md#requirement-the-overview-blocks-may-take-the-school-displays
		 */
		tr() {
			return mijnTranslator(this.t, this.locale)
		},

		/**
		 * @return {object|null} The total and the segments.
		 * @spec openspec/changes/site-school-blocks/specs/portal-contribution-contract/spec.md#requirement-the-overview-blocks-may-take-the-school-displays
		 */
		figure() {
			return segmentsOf(this.row, this.block)
		},
	},
}
</script>

<style scoped>
.pq-segments {
	margin-block-end: var(--utrecht-space-block-lg, 1.5rem);
}

.pq-segments__card {
	padding: 1.5rem;
	border: 1px solid
		var(--nldesign-color-border-dark, var(--utrecht-color-grey-80, currentcolor));
	border-radius: var(
		--nldesign-website-border-radius-large,
		var(--utrecht-border-radius-md, 0.75rem)
	);
}

.pq-segments__total {
	margin: 0 0 0.75rem;
}

.pq-segments__number {
	font-size: 2rem;
	line-height: 1;
}

.pq-segments__bar {
	display: flex;
	block-size: 0.75rem;
	border-radius: 0.375rem;
	background: var(
		--nldesign-color-border,
		var(--utrecht-color-grey-90, transparent)
	);
	overflow: hidden;
}

.pq-segments__part {
	display: block;
	block-size: 100%;
}

.pq-segments__part--positive {
	background: var(
		--nldesign-color-primary,
		var(--utrecht-button-primary-action-background-color, currentcolor)
	);
}

.pq-segments__part--waiting {
	background: var(
		--nldesign-color-accent,
		var(--nldesign-color-warning, currentcolor)
	);
}

.pq-segments__part--warning {
	background: var(--nldesign-color-error, currentcolor);
}

.pq-segments__legend {
	display: flex;
	flex-wrap: wrap;
	gap: 0.5rem 1.5rem;
	margin: 0.75rem 0 0;
	padding: 0;
	list-style: none;
}

.pq-segments__item {
	display: inline-flex;
	align-items: center;
	gap: 0.5rem;
}

.pq-segments__dot {
	inline-size: 0.75rem;
	block-size: 0.75rem;
	border-radius: 50%;
}
</style>
