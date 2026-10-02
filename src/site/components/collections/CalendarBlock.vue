<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	A calendar of dated rows (contribution-record-page): what is coming as a
	list grouped by month, or one month as a grid with the same items listed
	below it. The list is the default because it reads well on a phone and
	with a screen reader; the grid is a second way in, not the only one.
-->
<template>
	<section
		class="pq-calendar"
		:aria-labelledby="label ? headingId : undefined"
		data-testid="calendar-block">
		<div class="pq-calendar__bar">
			<component
				:is="`h${level}`"
				v-if="label"
				:id="headingId"
				class="utrecht-heading-3">
				{{ label }}
			</component>
			<div class="pq-calendar__views" role="group" :aria-label="t('Show as')">
				<button
					type="button"
					class="utrecht-button utrecht-button--secondary-action"
					:aria-pressed="view === 'list' ? 'true' : 'false'"
					data-testid="calendar-view-list"
					@click="view = 'list'">
					{{ t('List') }}
				</button>
				<button
					type="button"
					class="utrecht-button utrecht-button--secondary-action"
					:aria-pressed="view === 'month' ? 'true' : 'false'"
					data-testid="calendar-view-month"
					@click="view = 'month'">
					{{ t('Month') }}
				</button>
			</div>
		</div>

		<p v-if="loading" class="utrecht-paragraph" role="status">
			{{ t('Loading…') }}
		</p>

		<template v-else-if="view === 'list'">
			<p
				v-if="upcoming.length === 0"
				class="utrecht-paragraph"
				data-testid="calendar-empty">
				<em>{{ t('Nothing planned from today.') }}</em>
			</p>
			<div
				v-for="group in upcomingByMonth"
				:key="group.key"
				class="pq-calendar__group">
				<component :is="`h${level + 1}`" class="utrecht-heading-4">
					{{ monthName(group.date) }}
				</component>
				<ul class="pq-calendar__list">
					<li
						v-for="item in group.items"
						:key="item.key"
						class="pq-calendar__item"
						data-testid="calendar-item">
						<time class="pq-calendar__when" :datetime="isoOf(item)">{{
							when(item)
						}}</time>
						<span class="pq-calendar__title">{{ item.title }}</span>
						<span v-if="item.kind" class="pq-calendar__kind">{{
							item.kind
						}}</span>
					</li>
				</ul>
			</div>
		</template>

		<template v-else>
			<div class="pq-calendar__nav">
				<button
					type="button"
					class="utrecht-button utrecht-button--subtle"
					data-testid="calendar-previous"
					@click="shift(-1)">
					{{ t('Previous month') }}
				</button>
				<component
					:is="`h${level + 1}`"
					class="utrecht-heading-4 pq-calendar__month-title"
					aria-live="polite">
					{{ monthName(cursor) }}
				</component>
				<button
					type="button"
					class="utrecht-button utrecht-button--subtle"
					data-testid="calendar-next"
					@click="shift(1)">
					{{ t('Next month') }}
				</button>
			</div>
			<div class="pq-calendar__scroll">
				<table class="pq-calendar__grid" data-testid="calendar-month">
					<caption class="sr-only">
						{{
							monthName(cursor)
						}}
					</caption>
					<thead>
						<tr>
							<th
								v-for="day in weekdays"
								:key="day.long"
								scope="col"
								:abbr="day.long">
								{{ day.short }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="(week, w) in weeks" :key="w">
							<td
								v-for="(day, d) in week"
								:key="d"
								:class="{
									'pq-calendar__cell--today': day && isToday(day),
									'pq-calendar__cell--busy':
										day && onDay(day).length > 0,
								}"
								:aria-current="
									day && isToday(day) ? 'date' : undefined
								">
								<template v-if="day">
									<span class="pq-calendar__date">{{
										day.getDate()
									}}</span>
									<ul
										v-if="onDay(day).length > 0"
										class="pq-calendar__day-items">
										<li
											v-for="item in onDay(day)"
											:key="item.key">
											{{ item.title }}
										</li>
									</ul>
								</template>
							</td>
						</tr>
					</tbody>
				</table>
			</div>
			<p
				v-if="inMonth.length === 0"
				class="utrecht-paragraph"
				data-testid="calendar-month-empty">
				<em>{{ t('Nothing planned this month.') }}</em>
			</p>
			<ul v-else class="pq-calendar__list">
				<li
					v-for="item in inMonth"
					:key="item.key"
					class="pq-calendar__item"
					data-testid="calendar-month-item">
					<time class="pq-calendar__when" :datetime="isoOf(item)">{{
						when(item)
					}}</time>
					<span class="pq-calendar__title">{{ item.title }}</span>
					<span v-if="item.kind" class="pq-calendar__kind">{{
						item.kind
					}}</span>
				</li>
			</ul>
		</template>
	</section>
</template>

<script>
import {
	dayKey,
	itemsByMonth,
	itemsOnDay,
	monthWeeks,
	upcomingItems,
} from '../../../shared/recordPage.js'

let counter = 0

/**
 * @spec openspec/changes/contribution-record-page/specs/portal-contribution-contract/spec.md#requirement-a-calendar-block-must-show-dated-rows-as-a-list-and-a-month
 */
export default {
	name: 'CalendarBlock',

	props: {
		/** The items, from calendarItems(): `{key, start, end, title, kind, allDay}`. */
		items: { type: Array, default: () => [] },
		/** Whether a source is still loading. */
		loading: { type: Boolean, default: false },
		/** The heading's level, 2 or 3, so the page outline stays intact. */
		level: { type: Number, default: 2 },
		/** The heading, '' for none. */
		label: { type: String, default: '' },
		/** The translator. */
		t: { type: Function, required: true },
		/** The page language. */
		locale: { type: String, default: 'nl' },
		/** Today; a test passes a fixed day. */
		today: { type: Date, default: () => new Date() },
		/** The view to open on, `list` or `month`. */
		initialView: { type: String, default: 'list' },
	},

	data() {
		counter += 1
		return {
			headingId: `pq-calendar-${counter}`,
			view: this.initialView === 'month' ? 'month' : 'list',
			cursor: new Date(this.today.getFullYear(), this.today.getMonth(), 1),
		}
	},

	computed: {
		upcoming() {
			return upcomingItems(this.items, this.today)
		},

		upcomingByMonth() {
			return itemsByMonth(this.upcoming)
		},

		weeks() {
			return monthWeeks(this.cursor.getFullYear(), this.cursor.getMonth())
		},

		inMonth() {
			const first = dayKey(this.cursor)
			const last = dayKey(
				new Date(this.cursor.getFullYear(), this.cursor.getMonth() + 1, 0),
			)
			return this.items.filter(
				(item) => dayKey(item.start) <= last && dayKey(item.end) >= first,
			)
		},

		weekdays() {
			// 5 January 2026 is a Monday.
			return Array.from({ length: 7 }, (_, i) => {
				const day = new Date(2026, 0, 5 + i)
				return {
					short: this.format(day, { weekday: 'short' }),
					long: this.format(day, { weekday: 'long' }),
				}
			})
		},
	},

	methods: {
		format(date, options) {
			try {
				return date.toLocaleDateString(this.locale || 'nl', options)
			} catch {
				return dayKey(date)
			}
		},

		monthName(date) {
			return this.format(date, { month: 'long', year: 'numeric' })
		},

		/**
		 * When an item is, as a person reads it: a day, a range of days, or a
		 * day with its times.
		 *
		 * @param {object} item The item.
		 * @return {string}
		 * @spec openspec/changes/contribution-record-page/specs/portal-contribution-contract/spec.md#requirement-a-calendar-block-must-show-dated-rows-as-a-list-and-a-month
		 */
		when(item) {
			const day = { weekday: 'short', day: 'numeric', month: 'short' }
			const sameDay = dayKey(item.start) === dayKey(item.end)
			if (item.allDay) {
				return sameDay
					? this.format(item.start, day)
					: this.t('{from} to {to}', {
							from: this.format(item.start, day),
							to: this.format(item.end, day),
						})
			}
			const time = (date) => {
				try {
					return date.toLocaleTimeString(this.locale || 'nl', {
						hour: '2-digit',
						minute: '2-digit',
					})
				} catch {
					return ''
				}
			}
			const from = `${this.format(item.start, day)} ${time(item.start)}`
			if (sameDay) {
				return item.end > item.start ? `${from}–${time(item.end)}` : from
			}
			return this.t('{from} to {to}', {
				from,
				to: `${this.format(item.end, day)} ${time(item.end)}`,
			})
		},

		isoOf(item) {
			return item.allDay ? dayKey(item.start) : item.start.toISOString()
		},

		onDay(day) {
			return itemsOnDay(this.items, day)
		},

		isToday(day) {
			return dayKey(day) === dayKey(this.today)
		},

		shift(months) {
			this.cursor = new Date(
				this.cursor.getFullYear(),
				this.cursor.getMonth() + months,
				1,
			)
		},
	},
}
</script>

<style scoped>
.pq-calendar {
	margin-block-end: var(--utrecht-space-block-lg, 1.5rem);
}

.pq-calendar__bar,
.pq-calendar__nav {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: var(--utrecht-space-block-sm, 0.5rem);
}

.pq-calendar__views {
	display: flex;
	gap: var(--utrecht-space-block-xs, 0.25rem);
}

.pq-calendar__views [aria-pressed='true'] {
	font-weight: 700;
	text-decoration: underline;
}

.pq-calendar__list {
	margin: 0 0 var(--utrecht-space-block-md, 1rem);
	padding: 0;
	list-style: none;
}

.pq-calendar__item {
	display: flex;
	flex-wrap: wrap;
	gap: 0.25rem 0.75rem;
	padding-block: 0.5rem;
	border-block-end: 1px solid
		var(--utrecht-color-grey-90, var(--color-border, #ccc));
}

.pq-calendar__when {
	min-inline-size: 11rem;
	font-weight: 700;
}

.pq-calendar__kind {
	font-style: italic;
}

.pq-calendar__scroll {
	overflow-x: auto;
}

.pq-calendar__grid {
	inline-size: 100%;
	border-collapse: collapse;
	table-layout: fixed;
	margin-block-end: var(--utrecht-space-block-md, 1rem);
}

.pq-calendar__grid th,
.pq-calendar__grid td {
	padding: 0.25rem;
	border: 1px solid var(--utrecht-color-grey-90, var(--color-border, #ccc));
	vertical-align: top;
	text-align: start;
}

.pq-calendar__grid td {
	block-size: 4rem;
}

.pq-calendar__cell--today .pq-calendar__date {
	font-weight: 700;
	text-decoration: underline;
}

.pq-calendar__day-items {
	margin: 0;
	padding: 0;
	list-style: none;
	font-size: 0.8rem;
	overflow-wrap: anywhere;
}

@media (max-width: 600px) {
	/* On a phone the grid shows which days are busy; the list below it says what. */
	.pq-calendar__day-items {
		display: none;
	}

	.pq-calendar__cell--busy .pq-calendar__date {
		font-weight: 700;
	}

	.pq-calendar__grid td {
		block-size: 2.5rem;
	}
}
</style>
