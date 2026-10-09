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
		<ul
			v-else
			class="pq-date-rows__list"
			:class="{ 'pq-date-rows__list--lines': block.rowStyle === 'lines' }">
			<li
				v-for="entry in entries"
				:key="entry.key"
				class="pq-date-rows__row"
				:class="{ 'pq-date-rows__row--link': entry.route !== '' }"
				data-testid="mijn-date-row">
				<DateTile
					v-if="showsTile(entry)"
					:date="entry.date"
					:locale="locale" />
				<div class="pq-date-rows__text">
					<!-- The small line above the title: "Vandaag · Nederlands"
					     (mijn-lists-follow-the-boards). -->
					<p v-if="entry.eyebrow" class="pq-date-rows__eyebrow">
						{{ entry.eyebrow }}
					</p>
					<p v-if="entry.title" class="pq-date-rows__title">
						<!-- The whole row opens the row's own page; the link is
						     the title, so it reads once (mijn-lists-follow-the-boards). -->
						<a
							v-if="entry.route"
							class="utrecht-link pq-date-rows__link"
							:href="hrefOf(entry.route)"
							data-testid="mijn-date-row-link"
							@click.prevent="$emit('navigate', entry.route)"
							>{{ entry.title }}</a
						>
						<template v-else>{{ entry.title }}</template>
						<DataBadge
							v-if="entry.isNew"
							class="pq-date-rows__new"
							:text="tr('New')"
							state="success" />
					</p>
					<p v-if="lineOf(entry)" class="pq-date-rows__line">
						{{ lineOf(entry) }}
					</p>
					<p v-if="entry.quote" class="pq-date-rows__line">
						“{{ entry.quote }}”
					</p>
				</div>
				<!-- "Geldig tot 30 november 2026" at the end of the row. -->
				<p
					v-if="block.dateDisplay === 'end' && entry.endDate"
					class="pq-date-rows__end">
					<template v-if="block.dateLabel">{{ `${block.dateLabel} ` }}</template
					><strong>{{ entry.endDate }}</strong>
				</p>
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
				<span
					v-if="entry.value"
					class="pq-date-rows__value"
					data-testid="mijn-date-row-value"
					>{{ entry.value }}</span
				>
				<svg
					v-if="entry.route"
					class="pq-date-rows__chevron"
					viewBox="0 0 24 24"
					aria-hidden="true"
					focusable="false">
					<path
						d="M9 6l6 6-6 6"
						fill="none"
						stroke="currentColor"
						stroke-width="2.2"
						stroke-linecap="round"
						stroke-linejoin="round" />
				</svg>
			</li>
		</ul>
	</section>
</template>

<script>
import DataBadge from './DataBadge.vue'
import DateTile from './DateTile.vue'
import EmptyState from './EmptyState.vue'
import Skeleton from './Skeleton.vue'
import { formatMoment } from '../collections/cells.js'
import { dateRows } from './displays.js'
import { rowExtras, rowRoute } from './lists.js'
import { mijnTranslator, siteHref } from './rows.js'

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
		/** The route of the block's `rowPage`, or '' (mijn-lists-follow-the-boards). */
		rowPageRoute: { type: String, default: '' },
		/** Today, for "Vandaag" and the "Nieuw" mark; a test passes a fixed day. */
		today: { type: Date, default: undefined },
	},

	emits: ['navigate'],

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
			const context = {
				collection: this.collection,
				locale: this.locale,
				today: this.today || new Date(),
				tr: this.tr,
			}
			return dateRows(this.rows, this.block, this.collection).map((entry) => ({
				...entry,
				...rowExtras(entry.row, this.block, context),
				route: rowRoute(entry.row, this.block, this.rowPageRoute),
				endDate:
					this.block.dateDisplay === 'end' && entry.date
						? formatMoment(entry.date, false, this.locale)
						: '',
			}))
		},
	},

	methods: {
		/**
		 * Whether a row shows its date as a tile: it has one and the block
		 * writes dates no other way.
		 *
		 * @param {object} entry The row.
		 * @return {boolean}
		 * @spec openspec/changes/mijn-lists-follow-the-boards/specs/portal-contribution-contract/spec.md#requirement-rows-may-read-as-the-boards-lists
		 */
		showsTile(entry) {
			return (
				Boolean(entry.date)
				&& (!this.block.dateDisplay || this.block.dateDisplay === 'tile')
			)
		},

		/**
		 * The sub line, with the day in words after it when the block puts
		 * the date there: "Leestoets · vrijdag 2 oktober".
		 *
		 * @param {object} entry The row.
		 * @return {string}
		 * @spec openspec/changes/mijn-lists-follow-the-boards/specs/portal-contribution-contract/spec.md#requirement-rows-may-read-as-the-boards-lists
		 */
		lineOf(entry) {
			return [
				entry.subtitle,
				this.block.dateDisplay === 'line' ? entry.dateWords : '',
			]
				.filter((part) => part)
				.join(' · ')
		},

		/**
		 * @param {string} route A site route.
		 * @return {string} Its address.
		 */
		hrefOf(route) {
			return siteHref(route)
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

/* A row that opens its own page: the title is the link, stretched over the
   whole row, and a chevron at the end (mijn-lists-follow-the-boards). */
.pq-date-rows__row--link {
	position: relative;
}

.pq-date-rows__link::after {
	content: '';
	position: absolute;
	inset: 0;
}

.pq-date-rows__link:focus-visible {
	outline: none;
}

.pq-date-rows__row--link:focus-within {
	outline: 2px solid var(--pq-focus-color, CanvasText);
	outline-offset: 2px;
}

.pq-date-rows__chevron {
	flex: none;
	inline-size: 1.25rem;
	block-size: 1.25rem;
	color: var(--utrecht-link-color, LinkText);
}

.pq-date-rows__eyebrow {
	margin: 0 0 0.125rem;
	font-size: 0.875rem;
	color: var(
		--thematiq-website-text-muted,
		var(--nldesign-color-text-muted, var(--utrecht-document-color, CanvasText))
	);
}

.pq-date-rows__new {
	margin-inline-start: 0.5rem;
	vertical-align: middle;
}

.pq-date-rows__value {
	flex: none;
	font-size: 1.5rem;
	font-weight: 600;
	font-variant-numeric: tabular-nums;
}

.pq-date-rows__end {
	flex: none;
	margin: 0;
	font-size: 0.9375rem;
}

/* Rows as lines: the boards' plain lists, a rule between rows. */
.pq-date-rows__list--lines {
	gap: 0;
	border-block-start: 1px solid
		var(--nldesign-color-border, var(--utrecht-color-grey-90, currentcolor));
}

.pq-date-rows__list--lines .pq-date-rows__row {
	padding: 0.75rem 0;
	border: 0;
	border-block-end: 1px solid
		var(--nldesign-color-border, var(--utrecht-color-grey-90, currentcolor));
	border-radius: 0;
	background: transparent;
}

.pq-date-rows__title {
	margin: 0 0 0.25rem;
	font-weight: 700;
}

.pq-date-rows__line {
	margin: 0;
	color: var(
		--thematiq-website-text-muted,
		var(--nldesign-color-text-muted, var(--utrecht-document-color, CanvasText))
	);
}

.pq-date-rows__status {
	flex: 0 1 auto;
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: 0.375rem;
}

.pq-date-rows__note {
	margin: 0;
	font-size: 0.9375rem;
	color: var(
		--thematiq-website-text-muted,
		var(--nldesign-color-text-muted, var(--utrecht-document-color, CanvasText))
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
