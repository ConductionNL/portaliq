<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	A calendar block drawn as a timetable (calendar-timetable-display, a
	`calendar` block with `display: timetable`): one day, each item a row with
	its start and end time, the breaks between items, a change as a pill in
	words and a cancelled item struck through with its pill. With `range: week`
	the days of the week sit above it as tiles. The same items the list and the
	month read; only the drawing differs.
-->
<template>
	<section
		class="pq-timetable"
		:aria-labelledby="label ? headingId : undefined"
		data-testid="mijn-timetable">
		<component
			:is="`h${level}`"
			v-if="label"
			:id="headingId"
			class="utrecht-heading-3">
			{{ label }}
		</component>
		<p
			v-if="summaryText"
			class="pq-timetable__summary"
			data-testid="mijn-timetable-summary">
			{{ summaryText }}
		</p>

		<div
			v-if="showDays"
			class="pq-timetable__days"
			role="group"
			:aria-label="tr('Choose a day')">
			<button
				v-for="day in days"
				:key="day.key"
				type="button"
				class="pq-timetable__day"
				:aria-pressed="day.key === chosen ? 'true' : 'false'"
				:aria-label="dayLabel(day)"
				data-testid="mijn-timetable-day"
				@click="chosen = day.key">
				<span class="pq-timetable__day-name" aria-hidden="true">{{
					format(day.date, { weekday: 'short' })
				}}</span>
				<span class="pq-timetable__day-number" aria-hidden="true">{{
					day.date.getDate()
				}}</span>
			</button>
		</div>

		<Skeleton v-if="loading && rows.length === 0" :label="tr('Loading')" />
		<EmptyState
			v-else-if="rows.length === 0"
			:text="tr('Nothing on the timetable this day.')" />
		<ol v-else class="pq-timetable__list">
			<template v-for="row in rows" :key="row.key">
				<li
					v-if="row.type === 'break'"
					class="pq-timetable__break"
					data-testid="mijn-timetable-break">
					<span class="pq-timetable__time">
						<time :datetime="row.from.toISOString()">{{
							clock(row.from)
						}}</time>
					</span>
					<span class="pq-timetable__break-text">{{
						tr('Break, {minutes} minutes', { minutes: row.minutes })
					}}</span>
				</li>
				<li
					v-else
					class="pq-timetable__row"
					:class="{
						'pq-timetable__row--first': row.first && firstLabel,
						'pq-timetable__row--cancelled': row.item.cancelled,
					}"
					data-testid="mijn-timetable-row">
					<span class="pq-timetable__time">
						<time
							class="pq-timetable__start"
							:datetime="row.item.start.toISOString()">
							{{ clock(row.item.start) }}
						</time>
						<time
							class="pq-timetable__end"
							:datetime="row.item.end.toISOString()">
							{{ clock(row.item.end) }}
						</time>
					</span>
					<span class="pq-timetable__card">
						<span
							v-if="row.first && firstLabel"
							class="pq-timetable__eyebrow"
							data-testid="mijn-timetable-first">
							{{ firstLabel }}
						</span>
						<span class="pq-timetable__head">
							<span class="pq-timetable__number" aria-hidden="true">{{
								row.number
							}}</span>
							<component
								:is="row.item.cancelled ? 's' : 'strong'"
								class="pq-timetable__title">
								{{ row.item.title }}
							</component>
							<DataBadge
								v-if="pillOf(row.item)"
								:text="pillOf(row.item)"
								:state="
									row.item.cancelled ? 'neutral' : 'warning'
								" />
						</span>
						<span v-if="row.item.meta" class="pq-timetable__meta">{{
							row.item.meta
						}}</span>
						<span v-if="row.item.note" class="pq-timetable__note">{{
							row.item.note
						}}</span>
					</span>
				</li>
			</template>
		</ol>
	</section>
</template>

<script>
import DataBadge from './DataBadge.vue'
import EmptyState from './EmptyState.vue'
import Skeleton from './Skeleton.vue'
import { dayKey } from '../../../shared/recordPage.js'
import { mijnTranslator } from './rows.js'
import { clockOf, dayRows, daySummary, openingDay, weekDays } from './timetable.js'

let counter = 0

/**
 * @spec openspec/changes/calendar-timetable-display/specs/portal-contribution-contract/spec.md#requirement-a-calendar-block-may-draw-a-day-as-a-timetable
 */
export default {
	name: 'TimetableDay',

	components: { DataBadge, EmptyState, Skeleton },

	props: {
		/** The items, from calendarItems(), already narrowed to the range. */
		items: { type: Array, default: () => [] },
		/** `day` shows today; `week` adds the days of the week as tiles. */
		range: { type: String, default: 'day' },
		/** The small label over the first item of the day, '' for none. */
		firstLabel: { type: String, default: '' },
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
		return {
			headingId: `pq-timetable-${counter}`,
			chosen: '',
		}
	},

	computed: {
		/**
		 * @return {Function} The translator.
		 * @spec openspec/changes/calendar-timetable-display/specs/portal-contribution-contract/spec.md#requirement-a-calendar-block-may-draw-a-day-as-a-timetable
		 */
		tr() {
			return mijnTranslator(this.t, this.locale)
		},

		/**
		 * @return {boolean} Whether the week's days sit above the day.
		 * @spec openspec/changes/calendar-timetable-display/specs/portal-contribution-contract/spec.md#requirement-a-calendar-block-may-draw-a-day-as-a-timetable
		 */
		showDays() {
			return this.range === 'week'
		},

		/**
		 * @return {Array<object>} The day tiles of the week.
		 * @spec openspec/changes/calendar-timetable-display/specs/portal-contribution-contract/spec.md#requirement-a-calendar-block-may-draw-a-day-as-a-timetable
		 */
		days() {
			return weekDays(this.items, this.today)
		},

		/**
		 * @return {string} The day shown: the chosen tile, else the opening day.
		 * @spec openspec/changes/calendar-timetable-display/specs/portal-contribution-contract/spec.md#requirement-a-calendar-block-may-draw-a-day-as-a-timetable
		 */
		shownDay() {
			if (!this.showDays) {
				return dayKey(this.today)
			}
			return this.chosen || openingDay(this.days, this.today)
		},

		/**
		 * @return {Array<object>} The rows and breaks of the shown day.
		 * @spec openspec/changes/calendar-timetable-display/specs/portal-contribution-contract/spec.md#requirement-a-calendar-block-may-draw-a-day-as-a-timetable
		 */
		rows() {
			return dayRows(this.items, this.shownDay)
		},

		/**
		 * @return {string} "7 lesuren · 2 wijzigingen · uit om 14.20 uur".
		 * @spec openspec/changes/calendar-timetable-display/specs/portal-contribution-contract/spec.md#requirement-a-calendar-block-may-draw-a-day-as-a-timetable
		 */
		summaryText() {
			const sum = daySummary(this.rows)
			if (sum.count === 0) {
				return ''
			}
			const parts = [
				sum.count === 1
					? this.tr('1 lesson')
					: this.tr('{count} lessons', { count: sum.count }),
			]
			if (sum.changes > 0) {
				parts.push(
					sum.changes === 1
						? this.tr('1 change')
						: this.tr('{count} changes', { count: sum.changes }),
				)
			}
			if (sum.endsAt) {
				parts.push(
					this.tr('done at {time}', { time: this.clock(sum.endsAt) }),
				)
			}
			return parts.join(' · ')
		},
	},

	watch: {
		/**
		 * New items (another record, a reload) open on their own day again.
		 *
		 * @spec openspec/changes/calendar-timetable-display/specs/portal-contribution-contract/spec.md#requirement-a-calendar-block-may-draw-a-day-as-a-timetable
		 */
		items() {
			this.chosen = ''
		},
	},

	methods: {
		/**
		 * @param {Date} date A moment.
		 * @param {object} options Intl date options.
		 * @return {string} The date in the page language.
		 * @spec openspec/changes/calendar-timetable-display/specs/portal-contribution-contract/spec.md#requirement-a-calendar-block-may-draw-a-day-as-a-timetable
		 */
		format(date, options) {
			try {
				return date.toLocaleDateString(this.locale || 'nl', options)
			} catch {
				return dayKey(date)
			}
		},

		/**
		 * @param {Date} date A moment.
		 * @return {string} "08.30".
		 * @spec openspec/changes/calendar-timetable-display/specs/portal-contribution-contract/spec.md#requirement-a-calendar-block-may-draw-a-day-as-a-timetable
		 */
		clock(date) {
			return clockOf(date, this.locale)
		},

		/**
		 * @param {object} day A tile.
		 * @return {string} "maandag 5 oktober, 7 lesuren", for a screen reader.
		 * @spec openspec/changes/calendar-timetable-display/specs/portal-contribution-contract/spec.md#requirement-a-calendar-block-may-draw-a-day-as-a-timetable
		 */
		dayLabel(day) {
			const date = this.format(day.date, {
				weekday: 'long',
				day: 'numeric',
				month: 'long',
			})
			const count =
				day.count === 1
					? this.tr('1 lesson')
					: this.tr('{count} lessons', { count: day.count })
			return `${date}, ${count}`
		},

		/**
		 * @param {object} item An item.
		 * @return {string} Its pill: "Vervalt" when cancelled, else the word of its change.
		 * @spec openspec/changes/calendar-timetable-display/specs/portal-contribution-contract/spec.md#requirement-a-calendar-block-may-draw-a-day-as-a-timetable
		 */
		pillOf(item) {
			return item.cancelled ? this.tr('Cancelled') : item.status || ''
		},
	},
}
</script>

<style scoped>
.pq-timetable {
	margin-block-end: var(--utrecht-space-block-lg, 1.5rem);
}

.pq-timetable__summary {
	margin: 0 0 1rem;
	color: var(
		--nldesign-color-text-muted,
		var(--utrecht-document-color, CanvasText)
	);
}

.pq-timetable__days {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(3.5rem, 1fr));
	gap: 0.5rem;
	margin-block-end: 1.25rem;
}

.pq-timetable__day {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 0.125rem;
	padding: 0.5rem 0.25rem;
	border: 0;
	border-block-end: 3px solid transparent;
	border-radius: var(--utrecht-border-radius-md, 0.5rem);
	background: var(--nldesign-color-primary-light, var(--utrecht-color-grey-90));
	color: var(--utrecht-document-color, CanvasText);
	font: inherit;
	cursor: pointer;
}

.pq-timetable__day[aria-pressed='true'] {
	border-block-end-color: var(
		--nldesign-color-accent,
		var(--utrecht-document-color, CanvasText)
	);
	background: var(
		--nldesign-color-accent-light,
		var(--nldesign-color-primary-light, transparent)
	);
	font-weight: 700;
}

.pq-timetable__day:focus-visible {
	outline: var(--utrecht-focus-outline-width, 2px)
		var(--utrecht-focus-outline-style, solid)
		var(--utrecht-focus-outline-color, currentcolor);
	outline-offset: 2px;
}

.pq-timetable__day-number {
	font-size: 1.375rem;
}

.pq-timetable__list {
	display: grid;
	gap: 0.75rem;
	margin: 0;
	padding: 0;
	list-style: none;
}

.pq-timetable__row,
.pq-timetable__break {
	display: grid;
	grid-template-columns: 4rem 1fr;
	gap: 0.75rem;
	align-items: start;
}

.pq-timetable__time {
	display: flex;
	flex-direction: column;
	padding-block-start: 0.75rem;
}

.pq-timetable__start {
	font-weight: 700;
}

.pq-timetable__end {
	color: var(
		--nldesign-color-text-muted,
		var(--utrecht-document-color, CanvasText)
	);
	font-size: 0.875rem;
}

.pq-timetable__card {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
	padding: 0.75rem 1rem;
	border: 1px solid
		var(--nldesign-color-border-dark, var(--utrecht-color-grey-80, currentcolor));
	border-radius: var(
		--nldesign-website-border-radius-large,
		var(--utrecht-border-radius-md, 0.75rem)
	);
	background: var(--utrecht-document-background-color, Canvas);
}

.pq-timetable__row--first .pq-timetable__card {
	border-color: var(
		--nldesign-color-accent,
		var(--nldesign-color-border, currentcolor)
	);
	background: var(
		--nldesign-color-accent-light,
		var(--nldesign-color-primary-light, transparent)
	);
}

.pq-timetable__row--cancelled .pq-timetable__card {
	border-style: dashed;
}

.pq-timetable__eyebrow {
	color: var(
		--nldesign-color-accent-text,
		var(--utrecht-document-color, CanvasText)
	);
	font-size: 0.8125rem;
	font-weight: 700;
	letter-spacing: 0.06em;
	text-transform: uppercase;
}

.pq-timetable__head {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 0.5rem;
}

.pq-timetable__number {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	inline-size: 1.75rem;
	block-size: 1.75rem;
	border-radius: 50%;
	background: var(--nldesign-color-primary-light, var(--utrecht-color-grey-90));
	font-size: 0.875rem;
	font-weight: 700;
}

.pq-timetable__title {
	flex: 1;
	font-size: 1.0625rem;
	font-weight: 700;
}

.pq-timetable__meta,
.pq-timetable__note {
	color: var(
		--nldesign-color-text-muted,
		var(--utrecht-document-color, CanvasText)
	);
}

.pq-timetable__break {
	align-items: center;
	color: var(
		--nldesign-color-text-muted,
		var(--utrecht-document-color, CanvasText)
	);
	font-size: 0.875rem;
}

.pq-timetable__break .pq-timetable__time {
	padding-block-start: 0;
}

.pq-timetable__break-text {
	display: flex;
	align-items: center;
	gap: 0.75rem;
}

.pq-timetable__break-text::before,
.pq-timetable__break-text::after {
	content: '';
	flex: 1;
	border-block-start: 1px solid
		var(--nldesign-color-border, var(--utrecht-color-grey-90, currentcolor));
}

@media (max-width: 600px) {
	.pq-timetable__row,
	.pq-timetable__break {
		grid-template-columns: 3.5rem 1fr;
	}

	/* On a phone the times on the left already order the day. */
	.pq-timetable__number {
		display: none;
	}
}
</style>
