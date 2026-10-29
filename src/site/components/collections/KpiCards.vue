<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	Figure cards from one row of a collection (contribution-record-page): per
	card a label, the figure with its unit, and the details beside it. A
	highlighted card with a figure above zero says "needs attention" in words,
	so the mark never rests on colour alone (WCAG 1.4.1).
-->
<template>
	<section
		class="pq-kpi"
		:class="{ 'pq-kpi--list': variant === 'list' }"
		:aria-labelledby="label ? headingId : undefined"
		data-testid="kpi-block">
		<component
			:is="`h${level}`"
			v-if="label"
			:id="headingId"
			class="utrecht-heading-3">
			{{ label }}
		</component>
		<p
			v-if="caption && row && captionValue"
			class="utrecht-paragraph pq-kpi__caption"
			data-testid="kpi-caption">
			{{ caption.label }} {{ captionValue }}
		</p>
		<p
			v-if="loading"
			class="utrecht-paragraph"
			role="status"
			data-testid="kpi-loading">
			{{ t('Loading…') }}
		</p>
		<p
			v-else-if="!row"
			class="utrecht-paragraph pq-kpi__empty"
			data-testid="kpi-empty">
			<em>{{ t('No figures yet.') }}</em>
		</p>
		<ul v-else class="pq-kpi__cards">
			<li
				v-for="card in cards"
				:key="card.field"
				class="pq-kpi__card"
				:class="{ 'pq-kpi__card--highlight': card.highlight }"
				:data-field="card.field"
				data-testid="kpi-card">
				<p class="pq-kpi__label">
					{{ card.label }}
				</p>
				<p class="pq-kpi__value">
					<span class="pq-kpi__number">{{
						figure(row, card.field, locale)
					}}</span>
					<span v-if="unitOf(card)" class="pq-kpi__unit">{{
						unitOf(card)
					}}</span>
				</p>
				<p v-if="detailsOf(card)" class="pq-kpi__details">
					{{ detailsOf(card) }}
				</p>
				<p
					v-if="needsAttention(card)"
					class="pq-kpi__flag"
					data-testid="kpi-attention">
					{{ t('Needs attention') }}
				</p>
			</li>
		</ul>
	</section>
</template>

<script>
import { countedWord, figure } from '../../../shared/recordPage.js'

let counter = 0

/**
 * @spec openspec/changes/contribution-record-page/specs/portal-contribution-contract/spec.md#requirement-a-kpi-block-must-show-figure-cards-from-one-row
 */
export default {
	name: 'KpiCards',

	props: {
		/**
		 * The block's cards: `{field, label, unit?, details?, highlight?}`. A
		 * unit, and a detail's label, is a string or `{one, other}`.
		 */
		cards: { type: Array, default: () => [] },
		/**
		 * `cards` (the default) draws a tile per figure; `list` draws the figures
		 * as rows, the label then the number in bold, as a side column does
		 * (PtStemgedrag "Voor gestemd 39").
		 *
		 * @spec openspec/changes/site-voting-record-board/specs/site-public-records/spec.md#requirement-a-public-record-reads-as-the-voting-record-board
		 */
		variant: { type: String, default: 'cards' },
		/** The row the cards read, or null when there is none yet. */
		row: { type: Object, default: null },
		/** Whether the row is still loading. */
		loading: { type: Boolean, default: false },
		/** The heading's level, 2 or 3, so the page outline stays intact. */
		level: { type: Number, default: 2 },
		/** The heading above the cards, '' for none. */
		label: { type: String, default: '' },
		/** The line under the heading: `{field, label}`, e.g. the school year shown. */
		caption: { type: Object, default: null },
		/** The translator. */
		t: { type: Function, required: true },
		/** The page language. */
		locale: { type: String, default: 'nl' },
	},

	data() {
		counter += 1
		return { headingId: `pq-kpi-${counter}` }
	},

	computed: {
		captionValue() {
			const value = this.caption ? this.row?.[this.caption.field] : ''
			return value === null || value === undefined ? '' : String(value)
		},
	},

	methods: {
		figure,

		/**
		 * The details line: "3 with permission, 2 without permission".
		 *
		 * @param {object} card The card.
		 * @return {string}
		 * @spec openspec/changes/contribution-record-page/specs/portal-contribution-contract/spec.md#requirement-a-kpi-block-must-show-figure-cards-from-one-row
		 */
		detailsOf(card) {
			return (card.details || [])
				.map((detail) =>
					`${figure(this.row, detail.field, this.locale)} ${countedWord(detail.label, this.row?.[detail.field])}`.trim(),
				)
				.join(', ')
		},

		/**
		 * The card's unit for its figure: "1 dag", "5 dagen".
		 *
		 * @param {object} card The card.
		 * @return {string}
		 * @spec openspec/changes/kpi-unit-singular-and-plural/specs/portal-contribution-contract/spec.md#requirement-a-figure-cards-unit-may-name-its-singular-and-plural
		 */
		unitOf(card) {
			return countedWord(card.unit, this.row?.[card.field])
		},

		/**
		 * Whether a highlighted card holds a figure above zero.
		 *
		 * @param {object} card The card.
		 * @return {boolean}
		 * @spec openspec/changes/contribution-record-page/specs/portal-contribution-contract/spec.md#requirement-a-kpi-block-must-show-figure-cards-from-one-row
		 */
		needsAttention(card) {
			return card.highlight === true && Number(this.row?.[card.field]) > 0
		},
	},
}
</script>

<style scoped>
.pq-kpi {
	margin-block-end: var(--utrecht-space-block-lg, 1.5rem);
}

.pq-kpi__cards {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr));
	gap: var(--utrecht-space-block-md, 1rem);
	margin: 0;
	padding: 0;
	list-style: none;
}

.pq-kpi__card {
	padding: var(--utrecht-space-block-md, 1rem);
	border: 1px solid var(--utrecht-color-grey-80, var(--color-border, #8a8a8a));
	border-radius: var(
		--nldesign-website-border-radius-large,
		var(--utrecht-border-radius-md, 8px)
	);
	background: var(--utrecht-color-white, var(--color-main-background, #fff));
	color: var(--utrecht-document-color, var(--color-main-text, #222));
}

/* The list variant: one row per figure, label left, number right in bold,
   no tile around it. */
.pq-kpi--list {
	margin-block-end: 0;
}

.pq-kpi--list .pq-kpi__cards {
	grid-template-columns: minmax(0, 1fr);
	gap: 0.5rem;
}

.pq-kpi--list .pq-kpi__card {
	display: grid;
	grid-template-columns: minmax(0, 1fr) max-content;
	column-gap: 1rem;
	padding: 0;
	border: 0;
	background: none;
}

.pq-kpi--list .pq-kpi__label {
	color: inherit;
}

.pq-kpi--list .pq-kpi__value {
	margin: 0;
	font-size: inherit;
	grid-column: 2;
	grid-row: 1;
}

.pq-kpi--list .pq-kpi__details {
	grid-column: 1 / -1;
}

.pq-kpi__card--highlight {
	border-width: 3px;
	border-color: var(
		--utrecht-feedback-danger-border-color,
		var(--color-error, #b3261e)
	);
}

.pq-kpi__label,
.pq-kpi__details,
.pq-kpi__flag {
	margin: 0;
}

/* The figure tiles of the Mijn omgeving design (site-mijn-omgeving-components
   T12, FigureTiles): the label and the details quiet, the figure loud. */
.pq-kpi__label,
.pq-kpi__details {
	color: var(
		--thematiq-website-text-muted,
		var(--nldesign-color-text-muted, var(--utrecht-document-color, CanvasText))
	);
}

.pq-kpi__details {
	font-size: 0.9375rem;
}

.pq-kpi__value {
	margin: 0.25rem 0;
	font-size: 2rem;
	font-weight: 700;
	line-height: 1.2;
}

.pq-kpi__unit {
	margin-inline-start: 0.25rem;
	font-size: 1rem;
	font-weight: 400;
}

.pq-kpi__flag {
	margin-block-start: 0.5rem;
	font-weight: 700;
}
</style>
