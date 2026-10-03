<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	One case as a Den Haag case card (list form): ONE control whose name
	starts with the case title. Above the title the status in words and the
	reference, under it the case type. When the collection supplies them: the
	step position as text ("Stap 2 van 4") with a decorative bar, the answer
	date and whose turn it is. Colour never carries the status alone.

	With a route the card is a link with a real address; with `button` it
	opens the case on this page; otherwise it is text.
-->
<template>
	<li class="pq-case-card-item" data-testid="mijn-case-card">
		<component
			:is="tag"
			class="denhaag-case-card denhaag-case-card--list pq-case-card"
			:class="{ 'denhaag-case-card--archived': card.closed }"
			:href="tag === 'a' ? href : undefined"
			:type="tag === 'button' ? 'button' : undefined"
			@click="onClick">
			<span class="denhaag-case-card__wrapper">
				<span class="denhaag-case-card__title pq-case-card__title">{{
					card.title
				}}</span>
				<span class="denhaag-case-card__context pq-case-card__top">
					<DataBadge
						v-if="card.status"
						:text="card.status"
						:state="card.closed ? 'neutral' : 'success'" />
					<span v-if="card.reference" class="pq-case-card__reference">{{
						card.reference
					}}</span>
				</span>
				<span
					v-if="card.typeName"
					class="denhaag-case-card__subtitle pq-case-card__type"
					>{{ card.typeName }}</span
				>
				<span
					v-if="card.position || card.due"
					class="pq-case-card__progress">
					<span class="pq-case-card__progress-text">
						<span v-if="card.position">{{ card.position.text }}</span>
						<span v-if="card.due">{{ card.due }}</span>
					</span>
					<span
						v-if="card.position"
						class="pq-case-card__bar"
						aria-hidden="true"
						><span :style="{ inlineSize: barWidth }"
					/></span>
				</span>
				<span v-if="card.turn" class="pq-case-card__turn">{{
					card.turn
				}}</span>
				<span
					v-if="meta"
					class="denhaag-case-card__footer pq-case-card__meta"
					>{{ meta }}</span
				>
			</span>
		</component>
	</li>
</template>

<script>
import DataBadge from './DataBadge.vue'
import { siteHref } from './rows.js'

/**
 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-a-case-card-must-show-what-a-resident-needs-to-decide-whether-to-open-it-req-smo-002
 */
export default {
	name: 'CaseCard',

	components: { DataBadge },

	props: {
		/** What caseCard() in cases.js answers for the row. */
		card: { type: Object, required: true },
		/** One more line: the app, the mandate, the date; or ''. */
		meta: { type: String, default: '' },
		/** The in-site route the card opens, or ''. */
		route: { type: String, default: '' },
		/** Whether the card opens the case on this page (a button). */
		button: { type: Boolean, default: false },
	},

	emits: ['open'],

	computed: {
		/**
		 * @return {string} a, button or div.
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-a-case-card-must-show-what-a-resident-needs-to-decide-whether-to-open-it-req-smo-002
		 */
		tag() {
			if (this.route) {
				return 'a'
			}
			return this.button ? 'button' : 'div'
		},

		/**
		 * @return {string} The link's real address.
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-a-case-card-must-show-what-a-resident-needs-to-decide-whether-to-open-it-req-smo-002
		 */
		href() {
			return siteHref(this.route)
		},

		/**
		 * @return {string} The bar's width, from the step position.
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-a-case-card-must-show-what-a-resident-needs-to-decide-whether-to-open-it-req-smo-002
		 */
		barWidth() {
			const position = this.card.position
			return position && position.total > 0
				? `${Math.round((position.current / position.total) * 100)}%`
				: '0%'
		},
	},

	methods: {
		/**
		 * A plain click stays in the site; a click for a new tab is the browser's.
		 *
		 * @param {MouseEvent} event The click.
		 * @return {void}
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-a-case-card-must-show-what-a-resident-needs-to-decide-whether-to-open-it-req-smo-002
		 */
		onClick(event) {
			if (this.tag === 'div') {
				return
			}
			if (
				this.tag === 'a'
				&& (event?.ctrlKey || event?.metaKey || event?.shiftKey)
			) {
				return
			}
			event?.preventDefault?.()
			this.$emit('open', this.route)
		},
	},
}
</script>

<style>
/* The case card's look: the package's own CSS, nothing else of it. */
@import '@gemeente-denhaag/card/index.css';
</style>

<style scoped>
.pq-case-card-item {
	list-style: none;
}

.pq-case-card {
	display: block;
	inline-size: 100%;
	margin: 0;
	border: 0;
	background: none;
	color: var(--utrecht-document-color, inherit);
	font: inherit;
	text-align: start;
	text-decoration: none;
}

div.pq-case-card {
	cursor: default;
}

.pq-case-card:focus-visible {
	outline: var(--utrecht-focus-outline-width, 2px)
		var(--utrecht-focus-outline-style, solid)
		var(--utrecht-focus-outline-color, currentcolor);
	outline-offset: 2px;
}

.pq-case-card__top,
.pq-case-card__progress-text {
	display: flex;
	flex-wrap: wrap;
	justify-content: space-between;
	gap: 0.5rem;
}

.pq-case-card__title {
	display: block;
	font-weight: bold;
}

/* The status line shows above the title, as in DossiqOverview.dc.html, while
   the title comes first in the markup so the card's name starts with it. */
.pq-case-card__top {
	order: -1;
}

.pq-case-card__type,
.pq-case-card__turn,
.pq-case-card__meta {
	display: block;
}

.pq-case-card__progress {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
	font-size: 0.875em;
}

.pq-case-card__bar {
	display: block;
	block-size: 0.375rem;
	border-radius: 0.25rem;
	background-color: var(--utrecht-color-grey-90, #e6e6e6);
	overflow: hidden;
}

.pq-case-card__bar > span {
	display: block;
	block-size: 100%;
	background-color: var(
		--utrecht-button-primary-action-background-color,
		currentcolor
	);
}
</style>
