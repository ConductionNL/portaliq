<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	A portal's public catalogue (portal-public-catalogue): a search field,
	facets with their counts, a sort, the results as cards (or with a date
	tile) and pages. "Nieuws en documenten", "Opleidingen", "Cursusaanbod".
	The server searches, filters and pages; the widget only draws what it is
	sent, so nothing it shows was filtered away on the visitor's side.
-->
<template>
	<section class="nl-catalogue" data-testid="nl-catalogue">
		<h2 v-if="heading" class="utrecht-heading-2 nl-catalogue__heading">
			{{ heading }}
		</h2>
		<p v-if="intro" class="utrecht-paragraph nl-catalogue__intro">
			{{ intro }}
		</p>

		<form
			v-if="showSearch"
			class="nl-catalogue__search"
			role="search"
			data-testid="nl-catalogue-search"
			@submit.prevent="search">
			<label class="utrecht-form-label" :for="`${uid}-q`">
				{{ searchLabel || w('searchIn') }}
			</label>
			<span class="nl-catalogue__search-row">
				<input
					:id="`${uid}-q`"
					v-model="draft"
					class="utrecht-textbox nl-catalogue__input"
					type="search"
					:placeholder="placeholder" />
				<button
					type="submit"
					class="utrecht-button utrecht-button--primary-action">
					{{ w('search') }}
				</button>
			</span>
		</form>

		<div
			class="nl-catalogue__body"
			:class="{ 'nl-catalogue__body--rail': hasFacets }">
			<aside
				v-if="hasFacets"
				class="nl-catalogue__facets"
				:aria-labelledby="`${uid}-filters`">
				<h3 :id="`${uid}-filters`" class="utrecht-heading-4">
					{{ w('filters') }}
				</h3>
				<fieldset
					v-for="facet in result.facets"
					:key="facet.label"
					class="nl-catalogue__facet"
					data-testid="nl-catalogue-facet">
					<legend class="nl-catalogue__legend">
						{{ facet.label }}
					</legend>
					<label
						v-for="option in facet.values"
						:key="option.value"
						class="nl-catalogue__option">
						<input
							type="checkbox"
							:checked="isChosen(facet.label, option.value)"
							@change="toggle(facet.label, option.value)" />
						{{ option.value }} ({{ option.count }})
					</label>
				</fieldset>
				<button
					v-if="hasFilters"
					type="button"
					class="utrecht-button utrecht-button--subtle nl-catalogue__clear"
					@click="clear">
					{{ w('clear') }}
				</button>
			</aside>

			<div class="nl-catalogue__results">
				<div class="nl-catalogue__bar">
					<h3
						class="utrecht-heading-3 nl-catalogue__count"
						aria-live="polite"
						data-testid="nl-catalogue-count">
						{{ count }}
					</h3>
					<label class="nl-catalogue__sort">
						{{ w('sort') }}
						<select
							v-model="sortBy"
							class="utrecht-select"
							@change="reload(1)">
							<option
								v-for="option in sorts"
								:key="option"
								:value="option">
								{{ w(option) }}
							</option>
						</select>
					</label>
				</div>

				<p
					v-if="loading && cards.length === 0"
					class="utrecht-paragraph"
					role="status">
					{{ w('loading') }}
				</p>
				<p
					v-else-if="failed"
					class="utrecht-paragraph"
					role="alert"
					data-testid="nl-catalogue-failed">
					{{ w('failed') }}
				</p>
				<p
					v-else-if="cards.length === 0"
					class="utrecht-paragraph"
					data-testid="nl-catalogue-none">
					{{ w('none') }}
				</p>
				<ul v-else class="nl-catalogue__list">
					<li
						v-for="card in cards"
						:key="card.key"
						class="nl-catalogue__card"
						data-testid="nl-catalogue-item">
						<DateTile
							v-if="display === 'dated' && card.date"
							:date="card.date.slice(0, 10)" />
						<span class="nl-catalogue__text">
							<span class="nl-catalogue__line">
								<span
									v-if="card.kind && display !== 'dated'"
									class="nl-catalogue__kind"
									>{{ card.kind }}</span
								>
								<time
									v-if="card.date && display !== 'dated'"
									:datetime="card.date"
									>{{ longDate(card.date) }}</time
								>
								<span
									v-for="part in card.meta.slice(0, 1)"
									:key="part"
									>{{ part }}</span
								>
							</span>
							<a
								v-if="card.link"
								class="utrecht-link nl-catalogue__title"
								:href="card.link.href"
								@click="open($event, card.link)"
								>{{ card.title }}</a
							>
							<strong v-else class="nl-catalogue__title">{{
								card.title
							}}</strong>
							<span
								v-if="card.summary"
								class="nl-catalogue__summary"
								>{{ card.summary }}</span
							>
							<span
								v-if="display === 'dated' && card.meta.length > 0"
								class="nl-catalogue__line">
								<span v-if="card.kind" class="nl-catalogue__kind">{{
									card.kind
								}}</span>
								<span v-for="part in card.meta" :key="part">{{
									part
								}}</span>
							</span>
						</span>
						<span
							v-if="card.badge || card.note"
							class="nl-catalogue__aside">
							<strong v-if="card.badge">{{ card.badge }}</strong>
							<span
								v-if="card.note"
								class="nl-catalogue__note"
								:class="`nl-catalogue__note--${card.tone}`"
								>{{ card.note }}</span
							>
						</span>
					</li>
				</ul>

				<nav
					v-if="result.pages > 1"
					class="nl-catalogue__pages"
					:aria-label="w('pages')">
					<button
						v-for="n in result.pages"
						:key="n"
						type="button"
						class="utrecht-button utrecht-button--secondary-action"
						:aria-current="n === result.page ? 'page' : undefined"
						:aria-label="w('page', { page: n })"
						@click="reload(n)">
						{{ n }}
					</button>
					<button
						v-if="result.page < result.pages"
						type="button"
						class="utrecht-button utrecht-button--secondary-action"
						@click="reload(result.page + 1)">
						{{ w('next') }}
					</button>
				</nav>
			</div>
		</div>
	</section>
</template>

<script>
import DateTile from '../../components/mijn/DateTile.vue'
import { longDate } from '../../components/mijn/dates.js'
import { staysInSite } from '../../components/mijn/links.js'
import { fetchCatalogue } from '../../lib/publicCatalogue.js'
import { pageLocale } from '../../pages/inbox/translate.js'
import {
	countText,
	initialQuery,
	resultCard,
	SORTS,
	toggleFilter,
	word,
} from './catalogue.js'

import '@utrecht/heading-2-css/dist/index.css'
import '@utrecht/heading-3-css/dist/index.css'
import '@utrecht/link-css/dist/index.css'
import '@utrecht/paragraph-css/dist/index.css'

let counter = 0

/**
 * @spec openspec/changes/portal-public-catalogue/specs/portaliq-cms/spec.md#requirement-a-catalogue-block-searches-and-filters-the-portals-public-catalogue
 */
export default {
	name: 'NlCatalogue',

	components: { DateTile },

	props: {
		/** The portal, handed in by the host. */
		portal: { type: String, default: '' },
		/** The heading. */
		heading: { type: String, default: '' },
		/** The line under the heading. */
		intro: { type: String, default: '' },
		/** Only these item types (`news`, `course`, `programme`, `event`); none for all. */
		types: { type: Array, default: () => [] },
		/** `cards`, or `dated` for a date tile before each result. */
		display: { type: String, default: 'cards' },
		/** Results per page, 5 to 20. */
		pageSize: { type: [Number, String], default: 10 },
		/** The sort it opens with. */
		sort: { type: String, default: 'relevance' },
		/** The count without a search, "{count} cursussen". */
		countLabel: { type: String, default: '' },
		/** The search field's label. */
		searchLabel: { type: String, default: '' },
		/** The search field's example. */
		placeholder: { type: String, default: '' },
		/** Whether the search field shows. */
		showSearch: { type: Boolean, default: true },
		/** The route of the news article page, for a news result. */
		newsRoute: { type: String, default: '/nieuws' },
	},

	emits: ['navigate'],

	data() {
		counter += 1
		const q =
			typeof window === 'undefined' ? '' : initialQuery(window.location.search)
		return {
			uid: `nl-catalogue-${counter}`,
			q,
			draft: q,
			filters: {},
			sortBy: SORTS.includes(this.sort) ? this.sort : 'relevance',
			result: { items: [], total: 0, page: 1, pages: 1, facets: [] },
			loading: false,
			failed: false,
		}
	},

	computed: {
		/**
		 * @return {string} `nl` or `en`.
		 * @spec openspec/changes/portal-public-catalogue/specs/portaliq-cms/spec.md#requirement-a-catalogue-block-searches-and-filters-the-portals-public-catalogue
		 */
		lang() {
			return pageLocale()
		},

		/**
		 * @return {Array<string>} The sorts of the menu.
		 * @spec openspec/changes/portal-public-catalogue/specs/portaliq-cms/spec.md#requirement-a-catalogue-block-searches-and-filters-the-portals-public-catalogue
		 */
		sorts() {
			return SORTS
		},

		/**
		 * @return {Array<object>} The result cards.
		 * @spec openspec/changes/portal-public-catalogue/specs/portaliq-cms/spec.md#requirement-a-catalogue-block-searches-and-filters-the-portals-public-catalogue
		 */
		cards() {
			return this.result.items.map((item) =>
				resultCard(item, { lang: this.lang, newsRoute: this.newsRoute }),
			)
		},

		/**
		 * @return {string} The count in words.
		 * @spec openspec/changes/portal-public-catalogue/specs/portaliq-cms/spec.md#requirement-a-catalogue-block-searches-and-filters-the-portals-public-catalogue
		 */
		count() {
			return countText(this.lang, this.result.total, this.q, this.countLabel)
		},

		/**
		 * @return {boolean} Whether there are facets to choose from.
		 * @spec openspec/changes/portal-public-catalogue/specs/portaliq-cms/spec.md#requirement-a-catalogue-block-searches-and-filters-the-portals-public-catalogue
		 */
		hasFacets() {
			return this.result.facets.length > 0
		},

		/**
		 * @return {boolean} Whether a facet is chosen.
		 * @spec openspec/changes/portal-public-catalogue/specs/portaliq-cms/spec.md#requirement-a-catalogue-block-searches-and-filters-the-portals-public-catalogue
		 */
		hasFilters() {
			return Object.keys(this.filters).length > 0
		},
	},

	mounted() {
		this.reload(1)
	},

	methods: {
		/**
		 * @param {string} key A word's key.
		 * @param {object} [vars] Its placeholders.
		 * @return {string} The word.
		 * @spec openspec/changes/portal-public-catalogue/specs/portaliq-cms/spec.md#requirement-a-catalogue-block-searches-and-filters-the-portals-public-catalogue
		 */
		w(key, vars) {
			return word(this.lang, key, vars)
		},

		/**
		 * @param {string} value A day or moment.
		 * @return {string} It as "30 september 2026".
		 * @spec openspec/changes/portal-public-catalogue/specs/portaliq-cms/spec.md#requirement-a-catalogue-block-searches-and-filters-the-portals-public-catalogue
		 */
		longDate(value) {
			return longDate(String(value).slice(0, 10), this.lang)
		},

		/**
		 * Ask the server for one page.
		 *
		 * @param {number} page The page.
		 * @return {Promise<void>}
		 * @spec openspec/changes/portal-public-catalogue/specs/portaliq-cms/spec.md#requirement-a-catalogue-block-searches-and-filters-the-portals-public-catalogue
		 */
		async reload(page) {
			this.loading = true
			this.failed = false
			try {
				this.result = await fetchCatalogue(this.portal, {
					q: this.q,
					types: this.types,
					filters: this.filters,
					sort: this.sortBy,
					page,
					limit: Math.min(20, Math.max(5, Number(this.pageSize) || 10)),
				})
			} catch {
				// Unavailable is not "nothing found".
				this.failed = true
				this.result = { items: [], total: 0, page: 1, pages: 1, facets: [] }
			}
			this.loading = false
		},

		/**
		 * Search for the typed words, from the first page.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/portal-public-catalogue/specs/portaliq-cms/spec.md#requirement-a-catalogue-block-searches-and-filters-the-portals-public-catalogue
		 */
		search() {
			this.q = this.draft.trim()
			return this.reload(1)
		},

		/**
		 * @param {string} label A facet.
		 * @param {string} value A value.
		 * @return {boolean} Whether it is chosen.
		 * @spec openspec/changes/portal-public-catalogue/specs/portaliq-cms/spec.md#requirement-a-catalogue-block-searches-and-filters-the-portals-public-catalogue
		 */
		isChosen(label, value) {
			return (this.filters[label] || []).includes(value)
		},

		/**
		 * Turn one facet value on or off and read the first page again.
		 *
		 * @param {string} label The facet.
		 * @param {string} value The value.
		 * @return {Promise<void>}
		 * @spec openspec/changes/portal-public-catalogue/specs/portaliq-cms/spec.md#requirement-a-catalogue-block-searches-and-filters-the-portals-public-catalogue
		 */
		toggle(label, value) {
			this.filters = toggleFilter(this.filters, label, value)
			return this.reload(1)
		},

		/**
		 * Clear every facet choice.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/portal-public-catalogue/specs/portaliq-cms/spec.md#requirement-a-catalogue-block-searches-and-filters-the-portals-public-catalogue
		 */
		clear() {
			this.filters = {}
			return this.reload(1)
		},

		/**
		 * @param {MouseEvent} event The click.
		 * @param {object} link The link.
		 * @return {void}
		 * @spec openspec/changes/portal-public-catalogue/specs/portaliq-cms/spec.md#requirement-a-catalogue-block-searches-and-filters-the-portals-public-catalogue
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
.nl-catalogue__intro {
	max-inline-size: 48rem;
}

.nl-catalogue__search {
	display: grid;
	gap: 0.375rem;
	max-inline-size: 48rem;
	margin-block: 1.5rem;
}

.nl-catalogue__search-row {
	display: flex;
}

.nl-catalogue__input {
	flex: 1;
	min-inline-size: 0;
}

.nl-catalogue__body--rail {
	display: grid;
	grid-template-columns: minmax(12rem, 16rem) 1fr;
	gap: 2.5rem;
}

.nl-catalogue__facet {
	margin: 0 0 1.25rem;
	padding: 0;
	border: 0;
}

.nl-catalogue__legend {
	margin-block-end: 0.5rem;
	font-weight: 700;
}

.nl-catalogue__option {
	display: flex;
	gap: 0.5rem;
	align-items: center;
	padding-block: 0.25rem;
}

.nl-catalogue__bar {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: 0.75rem;
	margin-block-end: 1rem;
}

.nl-catalogue__count {
	margin: 0;
}

.nl-catalogue__sort {
	display: flex;
	gap: 0.5rem;
	align-items: center;
}

.nl-catalogue__list {
	display: grid;
	gap: 0.75rem;
	margin: 0;
	padding: 0;
	list-style: none;
}

.nl-catalogue__card {
	display: flex;
	gap: 1.25rem;
	align-items: flex-start;
	padding: 1.25rem 1.5rem;
	border: 1px solid
		var(--nldesign-color-border, var(--utrecht-color-grey-80, currentcolor));
	border-radius: var(
		--nldesign-website-border-radius-large,
		var(--utrecht-border-radius-md, 0.75rem)
	);
	background: var(--utrecht-document-background-color, Canvas);
}

.nl-catalogue__text {
	display: flex;
	flex: 1;
	flex-direction: column;
	gap: 0.375rem;
	min-inline-size: 0;
}

.nl-catalogue__line {
	display: flex;
	flex-wrap: wrap;
	gap: 0.25rem 0.75rem;
	align-items: center;
	color: var(
		--thematiq-website-text-muted,
		var(--nldesign-color-text-muted, var(--utrecht-document-color, CanvasText))
	);
	font-size: 0.9375rem;
}

.nl-catalogue__kind {
	padding: 0.125rem 0.625rem;
	border-radius: 999px;
	background: var(--nldesign-color-primary-light, var(--utrecht-color-grey-90));
	color: var(--utrecht-document-color, CanvasText);
	font-weight: 700;
}

.nl-catalogue__title {
	font-size: 1.25rem;
	font-weight: 700;
}

.nl-catalogue__aside {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
	min-inline-size: 8rem;
}

.nl-catalogue__note {
	font-weight: 700;
}

.nl-catalogue__note--positive {
	color: var(--nldesign-color-success, var(--utrecht-document-color, CanvasText));
}

.nl-catalogue__note--warning {
	color: var(
		--nldesign-color-warning-text,
		var(--utrecht-document-color, CanvasText)
	);
}

.nl-catalogue__pages {
	display: flex;
	flex-wrap: wrap;
	gap: 0.5rem;
	margin-block-start: 1.25rem;
}

.nl-catalogue__pages [aria-current='page'] {
	font-weight: 700;
	text-decoration: underline;
}

@media (max-width: 768px) {
	.nl-catalogue__body--rail {
		grid-template-columns: 1fr;
		gap: 1rem;
	}

	.nl-catalogue__card {
		flex-wrap: wrap;
	}
}
</style>
