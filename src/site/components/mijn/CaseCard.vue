<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	One case as a Den Haag case card, the default (folder) appearance: the
	card body is Den Haag's own `__background`, a finished case takes its
	`--archived` colours. On top the status in words and the reference, then
	the title and the case type; when the collection supplies them, the step
	position as text ("Stap 2 van 4") with a decorative bar, the answer date
	and whose turn it is. Colour never carries the status alone.

	The title is the card's ONE control, so the control's name is the case
	title. It stretches over the whole card (as Den Haag's own action link
	does), so the card is one target. With a route it is a link with a real
	address; with `button` it opens the case on this page; otherwise it is
	text.
-->
<template>
	<li class="pq-case-card-item" data-testid="mijn-case-card">
		<div
			class="denhaag-case-card pq-case-card"
			:class="{ 'denhaag-case-card--archived': card.closed }">
			<div class="denhaag-case-card__wrapper">
				<span class="denhaag-case-card__background" aria-hidden="true" />
				<div class="denhaag-case-card__context pq-case-card__top">
					<DataBadge
						v-if="card.status"
						:text="card.status"
						:state="card.closed ? 'neutral' : 'success'" />
					<span v-if="card.reference" class="pq-case-card__reference">{{
						card.reference
					}}</span>
				</div>
				<div>
					<p class="denhaag-case-card__title pq-case-card__title">
						<a
							v-if="tag === 'a'"
							class="pq-case-card__link"
							:href="href"
							@click="onClick"
							>{{ card.title }}</a
						><button
							v-else-if="tag === 'button'"
							class="pq-case-card__link"
							type="button"
							@click="onClick">
							{{ card.title }}</button
						><span v-else>{{ card.title }}</span>
					</p>
					<p
						v-if="card.typeName"
						class="denhaag-case-card__subtitle pq-case-card__type">
						{{ card.typeName }}
					</p>
				</div>
				<div v-if="card.position || card.due" class="pq-case-card__progress">
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
				</div>
				<p v-if="card.turn" class="pq-case-card__turn">{{ card.turn }}</p>
				<div
					v-if="mandate || meta"
					class="denhaag-case-card__footer pq-case-card__meta">
					<span
						v-if="mandate"
						class="pq-case-card__mandate"
						data-testid="mijn-case-card-mandate"
						>{{ mandate }}</span
					>
					<span v-if="meta">{{ meta }}</span>
				</div>
			</div>
		</div>
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
		/** For whom the case is read, when under a mandate; or ''. */
		mandate: { type: String, default: '' },
		/** One more line: the app and the date; or ''. */
		meta: { type: String, default: '' },
		/** The in-site route the card opens, or ''. */
		route: { type: String, default: '' },
		/** Whether the card opens the case on this page (a button). */
		button: { type: Boolean, default: false },
	},

	emits: ['open'],

	computed: {
		/**
		 * @return {string} a, button or span.
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-a-case-card-must-show-what-a-resident-needs-to-decide-whether-to-open-it-req-smo-002
		 */
		tag() {
			if (this.route) {
				return 'a'
			}
			return this.button ? 'button' : 'span'
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
			if (this.tag === 'span') {
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

/* The card grows with its content: Den Haag's fixed height (the
   --denhaag-case-card-height token, 240px) is kept as the least height. */
.pq-case-card {
	block-size: auto;
	min-block-size: var(--denhaag-case-card-height, 15rem);
}

.pq-case-card .denhaag-case-card__wrapper {
	gap: var(--utrecht-space-block-sm, 0.5rem);
}

.pq-case-card__top,
.pq-case-card__progress-text {
	display: flex;
	flex-wrap: wrap;
	justify-content: space-between;
	align-items: center;
	gap: 0.5rem;
}

.pq-case-card__title,
.pq-case-card__type,
.pq-case-card__turn {
	margin: 0;
}

.pq-case-card__title {
	color: var(--denhaag-case-card-title-color, inherit);
	font-family: var(--denhaag-case-card-title-font-family, inherit);
	font-size: var(--denhaag-case-card-title-font-size, 1.25rem);
	font-weight: var(--denhaag-case-card-title-font-weight, bold);
	line-height: var(--denhaag-case-card-title-line-height, 1.4);
}

.pq-case-card__type {
	color: var(--denhaag-case-card-subtitle-color, inherit);
}

/* The title's control stretches over the card, as Den Haag's action link. */
.pq-case-card__link {
	margin: 0;
	padding: 0;
	border: 0;
	background: none;
	color: inherit;
	font: inherit;
	text-align: start;
	text-decoration: underline;
	cursor: pointer;
}

.pq-case-card__link::after {
	content: '';
	position: absolute;
	inset: 0;
}

.pq-case-card__link:focus-visible {
	outline: var(--utrecht-focus-outline-width, 2px)
		var(--utrecht-focus-outline-style, solid)
		var(--utrecht-focus-outline-color, currentcolor);
	outline-offset: 2px;
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
	background-color: var(--denhaag-case-card-paper-color, #fff);
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

.pq-case-card__meta {
	flex-wrap: wrap;
	justify-content: flex-start;
	gap: 0.5rem 1rem;
	font-size: 0.875em;
	color: var(--denhaag-case-card-context-color, inherit);
}
</style>
