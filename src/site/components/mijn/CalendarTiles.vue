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
				:class="{ 'pq-calendar-tiles__row--today': isToday(item) }"
				:aria-current="isToday(item) ? 'date' : undefined"
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
		/**
		 * The block's range: with `month`, the tiles start at this week's
		 * Monday, not at today (month-keeps-this-week).
		 */
		range: { type: String, default: '' },
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
		 * The first day the tiles show: today, or with the month range this
		 * week's Monday, so a Thursday visitor still sees Monday to Wednesday
		 * (month-keeps-this-week). The block's range has already kept the
		 * items inside this month.
		 *
		 * @return {Date} The day.
		 * @spec openspec/changes/month-keeps-this-week/specs/site-mijn-omgeving/spec.md#requirement-this-month-keeps-this-weeks-past-days
		 */
		fromDay() {
			const day = new Date(
				this.today.getFullYear(),
				this.today.getMonth(),
				this.today.getDate(),
			)
			if (this.range !== 'month') {
				return day
			}
			const back = (day.getDay() + 6) % 7
			return new Date(day.getFullYear(), day.getMonth(), day.getDate() - back)
		},

		/**
		 * @return {Array<object>} What is coming, at most the limit.
		 * @spec openspec/changes/site-school-blocks/specs/portal-contribution-contract/spec.md#requirement-the-overview-blocks-may-take-the-school-displays
		 */
		upcoming() {
			return upcomingItems(this.items, this.fromDay).slice(
				0,
				Math.min(MAX_TILES, Math.max(1, this.limit)),
			)
		},
	},

	methods: {
		/**
		 * Whether an item runs today: its tile is marked as today.
		 *
		 * @param {{start: Date, end: Date}} item An item.
		 * @return {boolean} True on today.
		 * @spec openspec/changes/month-keeps-this-week/specs/site-mijn-omgeving/spec.md#requirement-this-month-keeps-this-weeks-past-days
		 */
		isToday(item) {
			const key = this.dayOf(this.today)
			return this.dayOf(item.start) <= key && this.dayOf(item.end) >= key
		},

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

/* Today's tile: the set's accent line at its start (month-keeps-this-week). */
.pq-calendar-tiles__row--today {
	box-shadow: inset 4px 0 0
		var(--thematiq-accent-color, var(--nldesign-color-primary, currentcolor));
	padding-inline-start: 0.75rem;
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
