<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	A collection's rows as dated cards (site-school-blocks, `display: rows`):
	a date tile, a title ("Sami · Ziek"), a sub line, a quote, and on the
	right a status pill with a line under it ("Gezien door de leerkracht",
	"Juf Esra, vandaag 8.12 uur"). The pill says the status in words; its
	tone only adds weight. A row without a date shows no tile.
-->
<template>
	<section
		class="pq-date-rows"
		:aria-labelledby="label ? headingId : undefined"
		data-testid="mijn-date-rows">
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
		<ul v-else class="pq-date-rows__list">
			<li
				v-for="entry in entries"
				:key="entry.key"
				class="pq-date-rows__row"
				data-testid="mijn-date-row">
				<DateTile v-if="entry.date" :date="entry.date" :locale="locale" />
				<div class="pq-date-rows__text">
					<p v-if="entry.title" class="pq-date-rows__title">
						{{ entry.title }}
					</p>
					<p v-if="entry.subtitle" class="pq-date-rows__line">
						{{ entry.subtitle }}
					</p>
					<p v-if="entry.quote" class="pq-date-rows__line">
						“{{ entry.quote }}”
					</p>
				</div>
				<div
					v-if="entry.status || entry.statusNote"
					class="pq-date-rows__status">
					<DataBadge
						v-if="entry.status"
						:text="entry.status"
						:state="entry.tone" />
					<p v-if="entry.statusNote" class="pq-date-rows__note">
						{{ entry.statusNote }}
					</p>
				</div>
			</li>
		</ul>
	</section>
</template>

<script>
import DataBadge from './DataBadge.vue'
import DateTile from './DateTile.vue'
import EmptyState from './EmptyState.vue'
import Skeleton from './Skeleton.vue'
import { dateRows } from './displays.js'
import { mijnTranslator } from './rows.js'

let counter = 0

/**
 * @spec openspec/changes/site-school-blocks/specs/portal-contribution-contract/spec.md#requirement-a-collection-block-may-draw-its-rows-as-dated-rows-bars-chips-or-richer-cards
 */
export default {
	name: 'DateRows',

	components: { DataBadge, DateTile, EmptyState, Skeleton },

	props: {
		/** The rows, already windowed and sorted by the page. */
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
		return { headingId: `pq-date-rows-${counter}` }
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
		 * @return {Array<object>} The rows to draw.
		 * @spec openspec/changes/site-school-blocks/specs/portal-contribution-contract/spec.md#requirement-a-collection-block-may-draw-its-rows-as-dated-rows-bars-chips-or-richer-cards
		 */
		entries() {
			return dateRows(this.rows, this.block, this.collection)
		},
	},
}
</script>

<style scoped>
.pq-date-rows {
	margin-block-end: var(--utrecht-space-block-lg, 1.5rem);
}

.pq-date-rows__list {
	display: grid;
	gap: 0.75rem;
	margin: 0;
	padding: 0;
	list-style: none;
}

.pq-date-rows__row {
	display: flex;
	align-items: center;
	gap: 1.5rem;
	padding: 1.5rem;
	border: 1px solid
		var(--nldesign-color-border-dark, var(--utrecht-color-grey-80, currentcolor));
	border-radius: var(
		--nldesign-website-border-radius-large,
		var(--utrecht-border-radius-md, 0.75rem)
	);
	background: var(--utrecht-document-background-color, Canvas);
}

.pq-date-rows__text {
	flex: 1;
	min-inline-size: 0;
}

.pq-date-rows__title {
	margin: 0 0 0.25rem;
	font-weight: 700;
}

.pq-date-rows__line {
	margin: 0;
	color: var(
		--nldesign-color-text-muted,
		var(--utrecht-document-color, CanvasText)
	);
}

.pq-date-rows__status {
	flex: 0 1 16rem;
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: 0.375rem;
}

.pq-date-rows__note {
	margin: 0;
	font-size: 0.9375rem;
	color: var(
		--nldesign-color-text-muted,
		var(--utrecht-document-color, CanvasText)
	);
}

@media (max-width: 600px) {
	.pq-date-rows__row {
		flex-wrap: wrap;
		gap: 1rem;
		padding: 1rem;
	}

	.pq-date-rows__status {
		flex-basis: 100%;
	}
}
</style>
