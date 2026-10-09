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
		<!-- The summary over the subjects: "6,7 gemiddeld over 8 vakken" and
		     the portal's sentence (mijn-lists-follow-the-boards). -->
		<div
			v-if="summary"
			class="pq-chips__summary"
			data-testid="mijn-chips-summary">
			<p class="pq-chips__summary-figure">
				<span class="pq-chips__summary-number">{{ summary.average }}</span>
				<span class="pq-chips__summary-label">{{ summaryLabel }}</span>
			</p>
			<p v-if="summaryText" class="pq-chips__summary-text">
				{{ summaryText }}
			</p>
		</div>
		<!-- The tabs over the marks, under the summary (mijn-lists-follow-the-boards). -->
		<slot name="tabs" />
		<Skeleton v-if="loading && entries.length === 0" :label="tr('Loading')" />
		<EmptyState
			v-else-if="entries.length === 0"
			:text="tr('Nothing here yet.')" />
		<ul v-else class="pq-chips__list">
			<li
				v-for="entry in entries"
				:key="entry.key"
				class="pq-chips__row"
				:class="{ 'pq-chips__row--link': entry.route !== '' }"
				data-testid="mijn-chip-row">
				<span class="pq-chips__who">
					<a
						v-if="entry.route"
						class="utrecht-link pq-chips__label pq-chips__link"
						:href="hrefOf(entry.route)"
						data-testid="mijn-chip-row-link"
						@click.prevent="$emit('navigate', entry.route)"
						>{{ entry.label }}</a
					>
					<span v-else class="pq-chips__label">{{ entry.label }}</span>
					<span v-if="entry.subtitle" class="pq-chips__subtitle">{{
						entry.subtitle
					}}</span>
				</span>
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
					<DataBadge
						v-if="entry.isNew"
						:text="tr('New')"
						state="success" />
				</span>
				<DataBadge
					v-if="entry.averageLow && block.lowBelow"
					class="pq-chips__low"
					:text="tr('Below {mark}', { mark: lowText })"
					state="warning" />
				<span
					v-if="entry.average"
					class="pq-chips__average"
					:class="{
						'pq-chips__average--low':
							entry.averageLow && !block.groupField,
						'pq-chips__average--big': Boolean(block.groupField),
					}">
					<span class="pq-chips__hidden">{{ tr('Average') }}: </span
					>{{ entry.average }}
				</span>
				<svg
					v-if="entry.route"
					class="pq-chips__chevron"
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
import EmptyState from './EmptyState.vue'
import Skeleton from './Skeleton.vue'
import { chipRows, markText, numberOf } from './displays.js'
import { chipSummary, groupedChipRows, rowRoute } from './lists.js'
import { mijnTranslator, siteHref } from './rows.js'

let counter = 0

/**
 * @spec openspec/changes/site-school-blocks/specs/portal-contribution-contract/spec.md#requirement-a-collection-block-may-draw-its-rows-as-dated-rows-bars-chips-or-richer-cards
 */
export default {
	name: 'MarkChips',

	components: { DataBadge, EmptyState, Skeleton },

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
		/** The route of the block's `rowPage`, or '' (mijn-lists-follow-the-boards). */
		rowPageRoute: { type: String, default: '' },
		/** Today, for the "Nieuw" mark; a test passes a fixed day. */
		today: { type: Date, default: undefined },
	},

	emits: ['navigate'],

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
			// One row per mark, grouped per subject (mijn-lists-follow-the-boards),
			// or one row per subject with its marks in a list, as before.
			const entries = this.block.groupField
				? groupedChipRows(this.rows, this.block, {
						collection: this.collection,
						locale: this.locale,
						today: this.today || new Date(),
					})
				: chipRows(this.rows, this.block, this.locale, this.collection)
			return entries.map((entry) => ({
				...entry,
				route: entry.row
					? rowRoute(entry.row, this.block, this.rowPageRoute)
					: '',
			}))
		},

		/**
		 * The summary over the subjects, when the block asks for one.
		 *
		 * @return {object|null}
		 * @spec openspec/changes/mijn-lists-follow-the-boards/specs/portal-contribution-contract/spec.md#requirement-marks-may-be-grouped-per-subject-with-their-average
		 */
		summary() {
			return this.block.summary === true && this.block.groupField
				? chipSummary(this.entries, this.locale)
				: null
		},

		/**
		 * @return {string} "gemiddeld over 8 vakken".
		 * @spec openspec/changes/mijn-lists-follow-the-boards/specs/portal-contribution-contract/spec.md#requirement-marks-may-be-grouped-per-subject-with-their-average
		 */
		summaryLabel() {
			return this.summary.count === 1
				? this.tr('on average over 1 subject')
				: this.tr('on average over {count} subjects', {
						count: this.summary.count,
					})
		},

		/**
		 * The portal's sentence under the summary, its `{pass}`, `{fail}`
		 * and `{count}` filled in.
		 *
		 * @return {string}
		 * @spec openspec/changes/mijn-lists-follow-the-boards/specs/portal-contribution-contract/spec.md#requirement-marks-may-be-grouped-per-subject-with-their-average
		 */
		summaryText() {
			const text = String(this.block.summaryText || '')
			return ['pass', 'fail', 'count'].reduce(
				(out, key) => out.split(`{${key}}`).join(String(this.summary[key])),
				text,
			)
		},

		/**
		 * @return {string} The pass mark in the page language: "5,5".
		 * @spec openspec/changes/mijn-lists-follow-the-boards/specs/portal-contribution-contract/spec.md#requirement-marks-may-be-grouped-per-subject-with-their-average
		 */
		lowText() {
			const low = numberOf(this.block.lowBelow)
			return low === null ? '' : markText(low, this.locale)
		},
	},

	methods: {
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

.pq-chips__who {
	display: flex;
	flex: 0 0 14rem;
	flex-direction: column;
	min-inline-size: 0;
}

.pq-chips__label {
	font-weight: 600;
}

.pq-chips__subtitle {
	font-size: 0.875rem;
	color: var(
		--thematiq-website-text-muted,
		var(--nldesign-color-text-muted, var(--utrecht-document-color, CanvasText))
	);
}

/* A subject that opens its own page: the name is the link, stretched over
   the row, and a chevron at the end (mijn-lists-follow-the-boards). */
.pq-chips__row--link {
	position: relative;
}

.pq-chips__link {
	font-size: 1.0625rem;
	font-weight: 500;
}

.pq-chips__link::after {
	content: '';
	position: absolute;
	inset: 0;
}

.pq-chips__row--link:focus-within {
	outline: 2px solid var(--pq-focus-color, CanvasText);
	outline-offset: 2px;
}

.pq-chips__chevron {
	flex: none;
	inline-size: 1.25rem;
	block-size: 1.25rem;
	color: var(--utrecht-link-color, LinkText);
}

/* The average as the board's large figure at the end of the row. */
.pq-chips__average--big {
	min-inline-size: 2.5em;
	padding: 0;
	font-size: 1.625rem;
	font-weight: 500;
	text-align: end;
	font-variant-numeric: tabular-nums;
}

.pq-chips__summary {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 12px 32px;
	margin-block-end: 24px;
	padding: 20px 22px;
	border-radius: var(--nldesign-website-border-radius-large, 8px);
	background: var(
		--thematiq-surface-color,
		var(
			--nldesign-component-content-surface-background-color,
			var(--nldesign-color-surface, Canvas)
		)
	);
}

.pq-chips__summary p {
	margin: 0;
}

.pq-chips__summary-figure {
	display: flex;
	align-items: baseline;
	gap: 12px;
}

.pq-chips__summary-number {
	font-size: 2.25rem;
	font-weight: 600;
}

.pq-chips__summary-label {
	max-inline-size: 7em;
	font-size: 0.9375rem;
	line-height: 1.3;
	color: var(
		--thematiq-website-text-muted,
		var(--nldesign-color-text-muted, var(--utrecht-document-color, CanvasText))
	);
}

.pq-chips__summary-text {
	flex: 1 1 20rem;
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
