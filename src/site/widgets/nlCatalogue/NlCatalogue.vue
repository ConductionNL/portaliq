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
			v-if="showSearch && !railSearch"
			class="nl-catalogue__search"
			role="search"
			data-testid="nl-catalogue-search"
			@submit.prevent="search">
			<label
				class="utrecht-form-label"
				:class="{ 'nl-catalogue__hidden': labelHidden }"
				:for="`${uid}-q`">
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
					class="utrecht-button utrecht-button--primary-action nl-catalogue__submit">
					{{ w('search') }}
				</button>
			</span>
		</form>

		<div
			class="nl-catalogue__body"
			:class="{ 'nl-catalogue__body--rail': hasRail }">
			<aside
				v-if="hasRail"
				class="nl-catalogue__facets"
				:aria-labelledby="hasFacets ? `${uid}-filters` : undefined"
				:aria-label="hasFacets ? undefined : searchLabel || w('searchIn')">
				<!-- THE SEARCH IN THE RAIL (board Cursusaanbod): the field and
				     a button with only the magnifier, above the facets. -->
				<form
					v-if="showSearch && railSearch"
					class="nl-catalogue__search nl-catalogue__search--rail"
					role="search"
					data-testid="nl-catalogue-search"
					@submit.prevent="search">
					<label
						class="utrecht-form-label"
						:class="{ 'nl-catalogue__hidden': labelHidden }"
						:for="`${uid}-q`">
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
							class="utrecht-button utrecht-button--primary-action nl-catalogue__submit nl-catalogue__submit--icon">
							<svg
								viewBox="0 0 24 24"
								aria-hidden="true"
								focusable="false">
								<path :d="MAGNIFIER" fill="currentColor" />
							</svg>
							<span class="nl-catalogue__hidden">{{
								w('search')
							}}</span>
						</button>
					</span>
				</form>
				<h3
					v-if="hasFacets && !railSearch"
					:id="`${uid}-filters`"
					class="utrecht-heading-4 nl-catalogue__filters">
					{{ w('filters') }}
				</h3>
				<h3
					v-else-if="hasFacets"
					:id="`${uid}-filters`"
					class="nl-catalogue__hidden">
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
					<select
						v-if="controlOf(facet.label) === 'select'"
						class="utrecht-select nl-catalogue__control"
						:aria-label="facet.label"
						@change="pick(facet.label, $event.target.value)">
						<option value="">{{ w('all') }}</option>
						<option
							v-for="option in facet.values"
							:key="option.value"
							:value="option.value"
							:selected="isChosen(facet.label, option.value)">
							{{ option.value }} ({{ option.count }})
						</option>
					</select>
					<template v-else>
						<label
							v-for="option in facet.values"
							:key="option.value"
							class="nl-catalogue__option">
							<input
								v-if="controlOf(facet.label) === 'radio'"
								type="radio"
								:name="`${uid}-${facet.label}`"
								:checked="isChosen(facet.label, option.value)"
								@change="pick(facet.label, option.value)" />
							<input
								v-else
								type="checkbox"
								:checked="isChosen(facet.label, option.value)"
								@change="toggle(facet.label, option.value)" />
							{{ option.value }} ({{ option.count }})
						</label>
					</template>
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
							class="utrecht-select nl-catalogue__control nl-catalogue__control--sort"
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
							:date="card.date.slice(0, 10)"
							:locale="contentLocale() || lang" />
						<span class="nl-catalogue__text">
							<span
								v-if="display !== 'dated' && cardStyle !== 'meta'"
								class="nl-catalogue__line">
								<span v-if="card.kind" class="nl-catalogue__kind">{{
									card.kind
								}}</span>
								<time v-if="card.date" :datetime="card.date">{{
									longDate(card.date)
								}}</time>
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
								v-if="cardStyle === 'meta' && card.meta.length > 0"
								class="nl-catalogue__line nl-catalogue__line--meta">
								<span class="nl-catalogue__pill">{{
									metaOf(card).pill
								}}</span>
								<span
									v-for="part in metaOf(card).rest"
									:key="part"
									>{{ part }}</span
								>
							</span>
							<span
								v-else-if="
									display === 'dated' && card.meta.length > 0
								"
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
						<svg
							v-if="card.link"
							class="nl-catalogue__chevron"
							viewBox="0 0 24 24"
							aria-hidden="true"
							focusable="false">
							<path :d="CHEVRON" fill="currentColor" />
						</svg>
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
						class="utrecht-button utrecht-button--secondary-action nl-catalogue__page"
						:aria-current="n === result.page ? 'page' : undefined"
						:aria-label="w('page', { page: n })"
						@click="reload(n)">
						{{ n }}
					</button>
					<button
						v-if="result.page < result.pages"
						type="button"
						class="utrecht-button utrecht-button--secondary-action nl-catalogue__page nl-catalogue__page--next"
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
	chooseOne,
	countText,
	facetControl,
	facetsByOf,
	initialQuery,
	metaLine,
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

/** The magnifier of the search button in the rail (Material "magnify"). */
const MAGNIFIER =
	'M9.5,3A6.5,6.5 0 0,1 16,9.5C16,11.11 15.41,12.59 14.44,13.73L14.71,14H15.5L20.5,19L19,20.5L14,15.5V14.71L13.73,14.44C12.59,15.41 11.11,16 9.5,16A6.5,6.5 0 0,1 3,9.5A6.5,6.5 0 0,1 9.5,3M9.5,5C7,5 5,7 5,9.5C5,12 7,14 9.5,14C12,14 14,12 14,9.5C14,7 12,5 9.5,5Z'

/** The chevron at the end of a linked result (Material "chevron-right"). */
const CHEVRON = 'M8.59,16.58L13.17,12L8.59,7.41L10,6L16,12L10,18L8.59,16.58Z'

/**
 * @spec openspec/changes/portal-public-catalogue/specs/portaliq-cms/spec.md#requirement-a-catalogue-block-searches-and-filters-the-portals-public-catalogue
 */
export default {
	name: 'NlCatalogue',

	components: { DateTile },

	inject: {
		/**
		 * The language of the page's content (site-dates-in-content-language);
		 * empty outside the site shell, so the document's language applies.
		 */
		contentLocale: { from: 'siteContentLocale', default: () => () => '' },
	},

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
		/** A facet by item kind under this label ("Soort"); empty for none. */
		kindFacet: { type: String, default: '' },
		/** A facet by a news item's audience under this label ("Voor wie"); empty for none. */
		audienceFacet: { type: String, default: '' },
		/** Facet label to `checkbox` (default), `radio` or `select`. */
		facetDisplay: { type: Object, default: () => ({}) },
		/** Keep the field's label for screen readers only. */
		labelHidden: { type: Boolean, default: false },
		/** `top` (above results and facets) or `rail` (above the facets, beside the results). */
		searchPlacement: { type: String, default: 'top' },
		/** `kind` (a kind label above the title) or `meta` (the first meta part as a label under the summary). */
		cardStyle: { type: String, default: 'kind' },
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
			MAGNIFIER,
			CHEVRON,
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
		 * @return {boolean} Whether the search sits in the rail.
		 * @spec openspec/changes/site-catalogue-follows-the-school-boards/specs/portaliq-cms/spec.md#requirement-the-catalogue-block-reads-like-the-search-boards
		 */
		railSearch() {
			return this.searchPlacement === 'rail'
		},

		/**
		 * @return {boolean} Whether the block has a column beside the results.
		 * @spec openspec/changes/site-catalogue-follows-the-school-boards/specs/portaliq-cms/spec.md#requirement-the-catalogue-block-reads-like-the-search-boards
		 */
		hasRail() {
			return this.hasFacets || (this.showSearch && this.railSearch)
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
			return longDate(
				String(value).slice(0, 10),
				this.contentLocale() || this.lang,
			)
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
					facetsBy: facetsByOf({
						kindFacet: this.kindFacet,
						audienceFacet: this.audienceFacet,
						lang: this.lang,
					}),
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
		 * @param {string} label A facet.
		 * @return {string} How it is chosen from.
		 * @spec openspec/changes/site-catalogue-follows-the-school-boards/specs/portaliq-cms/spec.md#requirement-the-catalogue-block-reads-like-the-search-boards
		 */
		controlOf(label) {
			return facetControl(this.facetDisplay, label)
		},

		/**
		 * Choose one value of a facet (a radio or a menu) and read the first
		 * page again; '' clears the facet.
		 *
		 * @param {string} label The facet.
		 * @param {string} value The value.
		 * @return {Promise<void>}
		 * @spec openspec/changes/site-catalogue-follows-the-school-boards/specs/portaliq-cms/spec.md#requirement-the-catalogue-block-reads-like-the-search-boards
		 */
		pick(label, value) {
			this.filters = chooseOne(this.filters, label, value)
			return this.reload(1)
		},

		/**
		 * @param {object} card A result card.
		 * @return {{pill: string, rest: Array<string>}} Its meta line in the `meta` style.
		 * @spec openspec/changes/site-catalogue-follows-the-school-boards/specs/portaliq-cms/spec.md#requirement-the-catalogue-block-reads-like-the-search-boards
		 */
		metaOf(card) {
			return metaLine(card)
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
	max-inline-size: 33rem;
	margin-block: 1.5rem;
}

.nl-catalogue__search--rail {
	margin-block: 0 1.5rem;
}

.nl-catalogue__search-row {
	display: flex;
}

/* THE FIELD AND ITS BUTTON AS ONE (boards Zoeken): the field's strong edge,
   the button against it at the same height. */
.nl-catalogue__input.utrecht-textbox {
	flex: 1;
	min-inline-size: 0;
	max-inline-size: none;
	block-size: 3rem;
	padding-inline: 0.875rem;
	border: 2px solid
		var(--nldesign-color-border-dark, var(--utrecht-document-color, CanvasText));
	border-start-end-radius: 0;
	border-end-end-radius: 0;
	font-size: 1.0625rem;
}

.nl-catalogue__submit.utrecht-button {
	flex: none;
	block-size: 3rem;
	min-block-size: 3rem;
	padding-block: 0;
	padding-inline: 1.375rem;
	border-start-start-radius: 0;
	border-end-start-radius: 0;
	font-weight: 600;
}

.nl-catalogue__submit--icon.utrecht-button {
	inline-size: 3rem;
	padding-inline: 0;
	justify-content: center;
}

.nl-catalogue__submit--icon svg {
	inline-size: 1.25rem;
	block-size: 1.25rem;
}

/* A label for screen readers only. */
.nl-catalogue__hidden {
	position: absolute;
	inline-size: 1px;
	block-size: 1px;
	overflow: hidden;
	clip-path: inset(50%);
	white-space: nowrap;
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

.nl-catalogue__filters {
	margin-block: 0 1.25rem;
	font-size: 1.375rem;
}

.nl-catalogue__legend {
	margin-block-end: 0.5rem;
	font-weight: 700;
}

.nl-catalogue__option {
	display: flex;
	gap: 0.625rem;
	align-items: center;
	padding-block: 0.3125rem;
}

.nl-catalogue__option input {
	flex: none;
	inline-size: 1.125rem;
	block-size: 1.125rem;
	margin: 0;
	accent-color: var(--nldesign-color-primary, LinkText);
}

/* THE MENUS (sort and a facet chosen from a menu): a bordered box at the
   height of the board's, its arrow drawn by the browser. */
.nl-catalogue__control.utrecht-select {
	block-size: 2.75rem;
	padding-block: 0;
	padding-inline: 0.75rem;
	border: 1px solid
		var(--nldesign-color-border-dark, var(--utrecht-document-color, CanvasText));
	border-radius: var(--nldesign-website-border-radius, 0.375rem);
	background-color: var(--utrecht-document-background-color, Canvas);
	color: inherit;
	font: inherit;
}

.nl-catalogue__facet .nl-catalogue__control.utrecht-select {
	inline-size: 100%;
}

.nl-catalogue__clear.utrecht-button {
	padding-inline: 0;
	color: var(--utrecht-link-color, LinkText);
	text-decoration: underline;
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
	align-items: center;
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

.nl-catalogue__line--meta > span + span::before {
	content: '·';
	margin-inline-end: 0.75rem;
}

.nl-catalogue__line--meta > .nl-catalogue__pill + span::before {
	content: none;
}

.nl-catalogue__pill {
	padding: 0.0625rem 0.5rem;
	border-radius: var(--nldesign-website-border-radius, 0.25rem);
	background: var(--nldesign-color-primary-light, var(--utrecht-color-grey-90));
	color: var(--utrecht-document-color, CanvasText);
	font-weight: 700;
}

.nl-catalogue__chevron {
	flex: none;
	inline-size: 1.5rem;
	block-size: 1.5rem;
	color: var(--nldesign-color-primary, LinkText);
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

/* THE PAGES (boards Zoeken): small boxes, the current one filled in the
   primary colour, the others and "Volgende" outlined and underlined. */
.nl-catalogue__pages .nl-catalogue__page.utrecht-button {
	min-inline-size: 2.5rem;
	block-size: 2.5rem;
	min-block-size: 2.5rem;
	padding-block: 0;
	padding-inline: 0.75rem;
	border: 1px solid
		var(--nldesign-color-border, var(--utrecht-document-color, CanvasText));
	border-radius: var(--nldesign-website-border-radius, 0.25rem);
	background-color: var(--utrecht-document-background-color, Canvas);
	color: var(--nldesign-color-primary, LinkText);
	font-weight: 600;
	text-decoration: underline;
}

.nl-catalogue__pages .nl-catalogue__page.utrecht-button[aria-current='page'] {
	border-color: var(--nldesign-color-primary, CanvasText);
	background-color: var(--nldesign-color-primary, CanvasText);
	color: var(--nldesign-color-primary-text, Canvas);
	text-decoration: none;
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
