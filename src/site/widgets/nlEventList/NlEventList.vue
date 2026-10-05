<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	A dated list: what is coming up, each date as a tile or as a label
	(site-school-blocks). "Deze maand op school", "Agenda", "Eerstvolgende
	cursusdagen". Items are authored on the page today; a later wave lets a
	contributing app fill the same rows.

	A date tile is decorative for a screen reader, which reads the full date
	instead; a note ("Nog 1 plek") says its meaning in words, its tone only
	adds weight.
-->
<template>
	<section
		class="nl-event-list"
		:class="{ 'nl-event-list--framed': framed }"
		data-testid="nl-event-list">
		<h2 v-if="heading" class="utrecht-heading-3 nl-event-list__heading">
			{{ heading }}
		</h2>
		<p v-if="subtitle" class="utrecht-paragraph nl-event-list__subtitle">
			{{ subtitle }}
		</p>
		<ul
			v-if="rows.length > 0"
			class="nl-event-list__rows"
			:class="`nl-event-list__rows--${mode}`">
			<li
				v-for="row in rows"
				:key="row.key"
				class="nl-event-list__row"
				data-testid="nl-event-row">
				<DateTile v-if="mode === 'tiles'" :date="row.date" />
				<span v-else class="nl-event-list__label">{{ row.label }}</span>
				<span class="nl-event-list__text">
					<a
						v-if="row.link"
						class="utrecht-link nl-event-list__title"
						:href="row.link.href"
						@click="open($event, row.link)"
						>{{ row.title }}</a
					>
					<strong v-else class="nl-event-list__title">{{
						row.title
					}}</strong>
					<span v-if="row.meta" class="nl-event-list__meta">{{
						row.meta
					}}</span>
					<span
						v-if="row.note"
						class="nl-event-list__note"
						:class="`nl-event-list__note--${row.tone}`">
						{{ row.note }}
					</span>
				</span>
				<svg
					v-if="row.link && mode === 'tiles'"
					class="nl-event-list__arrow"
					viewBox="0 0 24 24"
					aria-hidden="true"
					focusable="false">
					<path d="M9 5l7 7-7 7" />
				</svg>
			</li>
		</ul>
		<p v-else class="utrecht-paragraph" data-testid="nl-event-list-empty">
			{{ emptyLabel }}
		</p>
		<p v-if="more" class="utrecht-paragraph nl-event-list__more">
			<a class="utrecht-link" :href="more.href" @click="open($event, more)">{{
				moreLabel
			}}</a>
		</p>
	</section>
</template>

<script>
import DateTile from '../../components/mijn/DateTile.vue'
import { authoredLink, staysInSite } from '../../components/mijn/links.js'
import { eventRows } from './events.js'

import '@utrecht/heading-3-css/dist/index.css'
import '@utrecht/link-css/dist/index.css'
import '@utrecht/paragraph-css/dist/index.css'

/**
 * @spec openspec/changes/site-school-blocks/specs/portaliq-cms/spec.md#requirement-a-dated-list-shows-each-date-as-a-tile-or-a-label
 */
export default {
	name: 'NlEventList',

	components: { DateTile },

	props: {
		/** The heading. */
		heading: { type: String, default: '' },
		/** A line under the heading. */
		subtitle: { type: String, default: '' },
		/** The items: `{date, endDate?, dateLabel?, title, href?, meta?, note?, noteTone?}`. */
		items: { type: Array, default: () => [] },
		/** `tiles` or `labels`. */
		display: { type: String, default: 'tiles' },
		/** Leave out what is over. */
		upcomingOnly: { type: Boolean, default: true },
		/** The most rows, 1 to 20. */
		limit: { type: [Number, String], default: 6 },
		/** The link under the list. */
		moreLabel: { type: String, default: '' },
		/** Its address. */
		moreHref: { type: String, default: '' },
		/** Draw the list as a card. */
		framed: { type: Boolean, default: true },
		/** What is said when nothing is coming up. */
		emptyLabel: { type: String, default: 'Er staat niets gepland.' },
	},

	emits: ['navigate'],

	computed: {
		/**
		 * @return {string} `tiles` or `labels`.
		 * @spec openspec/changes/site-school-blocks/specs/portaliq-cms/spec.md#requirement-a-dated-list-shows-each-date-as-a-tile-or-a-label
		 */
		mode() {
			return this.display === 'labels' ? 'labels' : 'tiles'
		},

		/**
		 * @return {Array<object>} The rows.
		 * @spec openspec/changes/site-school-blocks/specs/portaliq-cms/spec.md#requirement-a-dated-list-shows-each-date-as-a-tile-or-a-label
		 */
		rows() {
			return eventRows(this.items, {
				upcomingOnly: this.upcomingOnly,
				limit: this.limit,
			})
		},

		/**
		 * @return {object|null} The link under the list.
		 * @spec openspec/changes/site-school-blocks/specs/portaliq-cms/spec.md#requirement-a-dated-list-shows-each-date-as-a-tile-or-a-label
		 */
		more() {
			return this.moreLabel.trim() ? authoredLink(this.moreHref) : null
		},
	},

	methods: {
		/**
		 * @param {MouseEvent} event The click.
		 * @param {object} link The link.
		 * @return {void}
		 * @spec openspec/changes/site-school-blocks/specs/portaliq-cms/spec.md#requirement-a-dated-list-shows-each-date-as-a-tile-or-a-label
		 */
		open(event, link) {
			if (staysInSite(event, link)) {
				event.preventDefault()
				this.$emit('navigate', link.route)
			}
		},
	},
}
</script>

<style scoped>
.nl-event-list {
	display: flex;
	flex-direction: column;
	gap: 0.375rem;
}

.nl-event-list--framed {
	padding: 1.75rem 2rem;
	border: 1px solid
		var(--nldesign-color-border-dark, var(--utrecht-color-grey-80, currentcolor));
	border-radius: var(
		--nldesign-website-border-radius-large,
		var(--utrecht-border-radius-md, 0.75rem)
	);
	background: var(--utrecht-document-background-color, Canvas);
}

.nl-event-list__heading {
	margin: 0 0 0.5rem;
}

.nl-event-list__subtitle {
	margin: -0.5rem 0 0.5rem;
	color: var(
		--nldesign-color-text-muted,
		var(--utrecht-document-color, CanvasText)
	);
	font-size: 0.9375rem;
}

.nl-event-list__rows {
	margin: 0;
	padding: 0;
	list-style: none;
}

.nl-event-list__row {
	display: flex;
	align-items: center;
	gap: 1rem;
	padding-block: 0.75rem;
	border-block-start: 1px solid
		var(--nldesign-color-border, var(--utrecht-color-grey-90, currentcolor));
}

.nl-event-list__rows--labels .nl-event-list__row {
	align-items: baseline;
}

.nl-event-list__label {
	flex: 0 0 6.25rem;
	font-weight: 700;
}

.nl-event-list__text {
	flex: 1;
	display: flex;
	flex-direction: column;
	gap: 0.125rem;
	min-inline-size: 0;
}

.nl-event-list__title {
	font-weight: 600;
}

.nl-event-list__rows--labels .nl-event-list__title {
	font-weight: 400;
}

.nl-event-list__meta {
	color: var(
		--nldesign-color-text-muted,
		var(--utrecht-document-color, CanvasText)
	);
	font-size: 0.9375rem;
}

.nl-event-list__note {
	font-size: 0.9375rem;
	font-weight: 700;
}

.nl-event-list__note--positive {
	color: var(
		--nldesign-component-status-badge-success-color,
		var(--utrecht-document-color, CanvasText)
	);
}

.nl-event-list__note--warning {
	color: var(
		--nldesign-component-status-badge-warning-color,
		var(--utrecht-document-color, CanvasText)
	);
}

.nl-event-list__arrow {
	flex: none;
	inline-size: 1.125rem;
	block-size: 1.125rem;
	fill: none;
	stroke: var(--nldesign-color-primary, var(--utrecht-link-color, currentcolor));
	stroke-width: 2.6;
	stroke-linecap: round;
}

.nl-event-list__more {
	margin: 0.625rem 0 0;
	font-weight: 600;
}
</style>
