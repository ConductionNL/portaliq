<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	A calendar block drawn as date tiles (site-school-blocks, a `calendar`
	block with `display: tiles`): what is coming from today, each item a tile,
	its title and the source's meta line ("Woensdag, Vera en Sami"). The same
	items the list and month views read; only the drawing differs.
-->
<template>
	<section
		class="pq-calendar-tiles"
		:aria-labelledby="label ? headingId : undefined"
		data-testid="mijn-calendar-tiles">
		<component
			:is="`h${level}`"
			v-if="label"
			:id="headingId"
			class="utrecht-heading-3">
			{{ label }}
		</component>
		<Skeleton v-if="loading && upcoming.length === 0" :label="tr('Loading')" />
		<EmptyState
			v-else-if="upcoming.length === 0"
			:text="tr('Nothing here yet.')" />
		<ul v-else class="pq-calendar-tiles__list">
			<li
				v-for="item in upcoming"
				:key="item.key"
				class="pq-calendar-tiles__row"
				data-testid="mijn-calendar-tile">
				<DateTile :date="dayOf(item.start)" :locale="locale" />
				<span class="pq-calendar-tiles__text">
					<strong>{{ item.title }}</strong>
					<span v-if="item.meta" class="pq-calendar-tiles__meta">{{
						item.meta
					}}</span>
				</span>
			</li>
		</ul>
	</section>
</template>

<script>
import DateTile from './DateTile.vue'
import EmptyState from './EmptyState.vue'
import Skeleton from './Skeleton.vue'
import { upcomingItems } from '../../../shared/recordPage.js'
import { mijnTranslator } from './rows.js'

let counter = 0

/** The most tiles one block shows. */
const MAX_TILES = 8

/**
 * @spec openspec/changes/site-school-blocks/specs/portal-contribution-contract/spec.md#requirement-the-overview-blocks-may-take-the-school-displays
 */
export default {
	name: 'CalendarTiles',

	components: { DateTile, EmptyState, Skeleton },

	props: {
		/** The items, from calendarItems(). */
		items: { type: Array, default: () => [] },
		/** How many, at most eight. */
		limit: { type: Number, default: MAX_TILES },
		/** Whether a source is still loading. */
		loading: { type: Boolean, default: false },
		/** The heading. */
		label: { type: String, default: '' },
		/** The heading level. */
		level: { type: Number, default: 2 },
		/** Today; a test passes a fixed day. */
		today: { type: Date, default: () => new Date() },
		/** The site translator. */
		t: { type: Function, default: null },
		/** The page language. */
		locale: { type: String, default: 'nl' },
	},

	data() {
		counter += 1
		return { headingId: `pq-calendar-tiles-${counter}` }
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
		 * @return {Array<object>} What is coming, at most the limit.
		 * @spec openspec/changes/site-school-blocks/specs/portal-contribution-contract/spec.md#requirement-the-overview-blocks-may-take-the-school-displays
		 */
		upcoming() {
			return upcomingItems(this.items, this.today).slice(
				0,
				Math.min(MAX_TILES, Math.max(1, this.limit)),
			)
		},
	},

	methods: {
		/**
		 * @param {Date} date A day.
		 * @return {string} It as `YYYY-MM-DD`, in local time.
		 * @spec openspec/changes/site-school-blocks/specs/portal-contribution-contract/spec.md#requirement-the-overview-blocks-may-take-the-school-displays
		 */
		dayOf(date) {
			const pad = (n) => String(n).padStart(2, '0')
			return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
		},
	},
}
</script>

<style scoped>
.pq-calendar-tiles {
	margin-block-end: var(--utrecht-space-block-lg, 1.5rem);
}

.pq-calendar-tiles__list {
	margin: 0;
	padding: 0;
	list-style: none;
}

.pq-calendar-tiles__row {
	display: flex;
	align-items: center;
	gap: 1rem;
	padding-block: 0.75rem;
	border-block-start: 1px solid
		var(--nldesign-color-border, var(--utrecht-color-grey-90, currentcolor));
}

.pq-calendar-tiles__text {
	display: flex;
	flex-direction: column;
	gap: 0.125rem;
}

.pq-calendar-tiles__meta {
	color: var(
		--thematiq-website-text-muted,
		var(--nldesign-color-text-muted, var(--utrecht-document-color, CanvasText))
	);
	font-size: 0.9375rem;
}
</style>
