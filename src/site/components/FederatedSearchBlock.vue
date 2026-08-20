<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<section class="pq-search" data-testid="federated-search">
		<!--
			OPTIONAL, and empty by default.

			The host renderer already prints the PAGE's title above this block,
			and a block that always emits its own heading produced two `h2`s
			saying almost the same thing — "Zoeken" then "Zoeken in
			publicaties". For a screen-reader user that is two section headings
			where the page has one section.

			An author who places this block among others on a grid passes a
			`title` and gets one; a page that exists to BE the search does not.
		-->
		<h2 v-if="title" class="utrecht-heading-2">{{ title }}</h2>

		<!--
			THE BOX IS THE SHARED ONE, and deliberately not a second
			implementation of it.

			`CnSiteSearch` already carries the class names captured from the
			running NL Design System reference — `ac-search-box`,
			`utrecht-textbox--html-input`, `utrecht-button--primary-action` —
			and the note in that file records what happens without the last of
			them: the submit renders as the SUBTLE variant, transparent instead
			of a filled button. Re-typing that markup here would be a second
			place for it to drift out of step with the reference.

			It emits `search` and fetches nothing, which is the whole seam: the
			library owns what the control looks like, this block owns where the
			query goes.

			`labelVisible` because on a public portal the prompt is the only
			instruction a visitor gets.
		-->
		<CnSiteSearch
			:label="inputLabel"
			:placeholder="placeholder"
			:submitLabel="submitLabel"
			:value="query"
			inputId="pq-federated-search"
			:labelVisible="true"
			data-testid="federated-search-form"
			@search="onSearch" />

		<!--
			ONE LIVE REGION FOR THE RESULT COUNT, and it is `polite`.

			Without it a sighted visitor sees the list change and a screen-reader
			visitor hears nothing at all: the submit moves no focus, so there is
			no announcement to attach to. `aria-live` on a node that is always
			present (rather than one that appears with the results) is what makes
			the update announce — a region inserted at the same moment as its
			text is frequently missed.
		-->
		<p
			class="utrecht-paragraph pq-search__status"
			aria-live="polite"
			data-testid="federated-search-status">
			{{ status }}
		</p>

		<!--
			THE FACET COLUMN IS ONLY RESERVED WHEN THERE IS ONE.

			A fixed `180px 3fr` grid puts the results into the FIRST track when
			the facet `<aside>` does not render — measured: one child, columns
			`180px 514px`, results 180px wide with 514px of empty space beside
			them. Every result then wrapped into a narrow ribbon.

			Facets are absent whenever the API returns no buckets, which today
			includes every federated search, so this was the DEFAULT state and
			not an edge case.
		-->
		<div
			class="pq-search__layout"
			:class="{ 'pq-search__layout--faceted': facetBuckets.length > 0 }">
			<!--
				FACETS ARE RENDERED ONLY WHEN THE API RETURNED BUCKETS.

				An empty facet column that is always present reads as "no filters
				match", which is a different claim from "this deployment does not
				facet".
			-->
			<aside
				v-if="facetBuckets.length"
				class="pq-search__facets"
				data-testid="federated-search-facets">
				<h3 class="utrecht-heading-3">{{ facetLabel }}</h3>
				<ul class="pq-search__facet-list">
					<li v-for="bucket in facetBuckets" :key="bucket.value">
						<label
							class="utrecht-form-label utrecht-form-label--checkbox">
							<input
								type="checkbox"
								class="utrecht-checkbox"
								:value="bucket.value"
								:checked="selectedFacets.includes(bucket.value)"
								:data-testid="`federated-search-facet-${bucket.value}`"
								@change="toggleFacet(bucket.value)" />
							{{ bucket.label }}
							<span class="pq-search__facet-count"
								>({{ bucket.count }})</span
							>
						</label>
					</li>
				</ul>
			</aside>

			<div class="pq-search__results">
				<p
					v-if="error"
					class="utrecht-alert utrecht-alert--error"
					role="alert"
					data-testid="federated-search-error">
					{{ errorLabel }}
				</p>

				<ol
					v-else
					class="pq-search__list"
					data-testid="federated-search-results">
					<li
						v-for="result in results"
						:key="result.key"
						class="utrecht-card pq-search__result"
						data-testid="federated-search-result">
						<!--
							NOT `utrecht-card__heading`, and not `heading-3`.

							`.utrecht-card__heading` carries `order: 2` in the
							bundled card CSS — that component puts its label
							above its title by design — so a search result
							rendered its summary and source ABOVE its own title.
							Measured: heading order 2 inside a
							`flex-direction: column` card.

							`heading-4` for size while the element stays an
							`h3`: the document outline needs the level, and the
							RODS 3xl step is 40px, which is a page title rather
							than a row in a list of eleven.
						-->
						<h3 class="utrecht-heading-4 pq-search__result-title">
							<a
								v-if="result.href"
								class="utrecht-link"
								:href="result.href"
								rel="noopener noreferrer"
								target="_blank">
								{{ result.title }}
							</a>
							<template v-else>{{ result.title }}</template>
						</h3>

						<p v-if="result.summary" class="utrecht-paragraph">
							{{ result.summary }}
						</p>

						<!--
							THE SOURCE IS SHOWN ON EVERY RESULT, and this is the
							only place federation is visible to a visitor.

							A federated list that does not say where a row came
							from is indistinguishable from a local one, which
							makes the whole feature unverifiable from the page —
							including for the person checking whether federation
							is working at all.
						-->
						<p
							class="pq-search__source"
							data-testid="federated-search-source">
							<span class="sr-only">{{ sourceLabel }}: </span>
							{{ result.directory }}
						</p>
					</li>
				</ol>
			</div>
		</div>

		<!--
			PAGINATION IS A NAV OF LINKS, not buttons that only work with JS.
			Each href carries the full query, so a page is addressable and
			shareable — which is what `?_page=2` means on the reference portal
			this mirrors.
		-->
		<nav
			v-if="pages > 1"
			class="ams-pagination"
			:aria-label="paginationLabel"
			data-testid="federated-search-pagination">
			<ul class="ams-pagination__list">
				<li v-for="entry in pageWindow" :key="entry">
					<a
						class="ams-pagination__button"
						:href="hrefForPage(entry)"
						:aria-current="entry === page ? 'page' : undefined"
						:data-testid="`federated-search-page-${entry}`"
						@click.prevent="goToPage(entry)">
						{{ entry }}
					</a>
				</li>
			</ul>
		</nav>
	</section>
</template>

<script>
/**
 * Public, federated publication search.
 *
 * WHERE THE RESULTS COME FROM, AND WHY NOT FROM HERE
 * -------------------------------------------------
 * This block queries OpenCatalogi's `/api/federation/publications`, which is
 * `@PublicPage` and returns local AND federated rows in one envelope. Portaliq
 * runs NO search of its own and applies NO visibility rule of its own:
 * OpenCatalogi calls OpenRegister with `_rbac: true`, so what an anonymous
 * visitor may see is decided once, by the schema's authorization block, in the
 * app that owns the data.
 *
 * That is also why this block does not go through OpenRegister's object search
 * directly. Doing so would put a second visibility decision in a second app,
 * and the two would drift silently and in the unsafe direction.
 *
 * NO `@nextcloud/*` IMPORTS. This component mounts at a public origin with no
 * Nextcloud session; `fetch` is used directly rather than `@nextcloud/axios`,
 * which would attach a CSRF token that does not exist here.
 *
 * WHAT IS DELIBERATELY NOT OFFERED
 * --------------------------------
 * A filter on the source directory. Every row carries `@self.directory` and
 * the API will happily accept `@self[directory]=opencatalogi.nl` — and answer
 * `total: 0`, on a corpus where all 711 rows have that field set. Measured on
 * 2026-08-20. A control that silently empties the page is worse than no
 * control, so the directory is shown per result and cannot be filtered on
 * until the API supports it.
 *
 * @spec openspec/changes/portal-federated-search/specs/portal-federated-search/spec.md#requirement-an-anonymous-visitor-must-be-able-to-search-federated-publications
 */
import { CnSiteSearch } from '@conduction/nextcloud-vue/public'
import {
	buildRequestUrl,
	pageWindow,
	toBuckets,
	toResult,
} from '../lib/federatedSearch.js'

export default {
	name: 'FederatedSearchBlock',

	components: { CnSiteSearch },

	props: {
		/**
		 * Heading above the search form.
		 *
		 * Empty by default so the block does not duplicate the page title the
		 * host renderer already prints. See the template.
		 */
		title: {
			type: String,
			default: '',
		},

		/** Visible label on the search input. */
		inputLabel: {
			type: String,
			default: 'Zoek in publicaties',
		},

		/** Placeholder text inside the search input. */
		placeholder: {
			type: String,
			default: 'Zoekterm',
		},

		/** Label on the submit button. */
		submitLabel: {
			type: String,
			default: 'Zoeken',
		},

		/** Heading above the facet column. */
		facetLabel: {
			type: String,
			default: 'Thema',
		},

		/** Screen-reader prefix for a result's source directory. */
		sourceLabel: {
			type: String,
			default: 'Bron',
		},

		/** Accessible name for the pagination landmark. */
		paginationLabel: {
			type: String,
			default: 'Paginering',
		},

		/** Message shown when the search backend cannot be reached. */
		errorLabel: {
			type: String,
			default: 'De zoekresultaten konden niet worden geladen.',
		},

		/**
		 * The endpoint to search.
		 *
		 * Relative by default, so a portal served from the same Nextcloud finds
		 * OpenCatalogi without configuration, and an operator can point it at
		 * another instance without a code change.
		 */
		endpoint: {
			type: String,
			default: '/index.php/apps/opencatalogi/api/federation/publications',
		},

		/** Results per page. */
		pageSize: {
			type: Number,
			default: 10,
		},

		/**
		 * The object field to facet on.
		 *
		 * `themes` is what OpenCatalogi's `publication` schema DECLARES, and a
		 * declared property is the only kind that can be faceted: an
		 * undeclared field is stored, returned on the object, and invisible to
		 * search — so faceting on one yields zero buckets and the column
		 * silently does not render.
		 *
		 * That is not hypothetical. Seeding `categories` onto publications
		 * produced rows that showed their categories in the API response and
		 * still faceted to nothing, because `categories` belongs to the
		 * publiccode/software schema and not to this one. A catalogue serving
		 * that corpus should pass `facetField: "categories"`.
		 */
		facetField: {
			type: String,
			default: 'themes',
		},
	},

	data() {
		return {
			// What was actually searched for, as opposed to what is currently
			// typed. `CnSiteSearch` keeps the typed term to itself and hands it
			// over on submit, so the list never thrashes per keystroke and the
			// URL only ever reflects a real, shareable search.
			query: '',
			page: 1,
			selectedFacets: [],
			results: [],
			facetBuckets: [],
			total: 0,
			pages: 1,
			loading: false,
			error: false,
			// Monotonic request id. A slow first request that resolves AFTER a
			// fast second one would otherwise overwrite newer results with
			// older ones — a race that shows up as "the page ignored my
			// search" and is invisible on a fast connection.
			sequence: 0,
		}
	},

	computed: {
		/**
		 * The line announced to assistive tech and read by everyone else.
		 *
		 * @return {string} The status line.
		 */
		status() {
			if (this.loading === true) {
				return 'Bezig met zoeken…'
			}

			if (this.error === true) {
				return this.errorLabel
			}

			if (this.total === 0) {
				return 'Geen resultaten gevonden.'
			}

			// Dutch has no "1 resultaten". The singular is worth the branch
			// because this string is read out by a screen reader on every
			// search, and a portal that cannot count to one in its own
			// language is the first thing a citizen notices about it.
			if (this.total === 1) {
				return '1 resultaat gevonden.'
			}

			return `${this.total} resultaten gevonden.`
		},

		/**
		 * The page numbers to offer.
		 *
		 * Windowed around the current page: 356 pages of links is not a
		 * navigation aid, it is a wall.
		 *
		 * @return {Array<number>} Page numbers.
		 */
		pageWindow() {
			return pageWindow(this.page, this.pages)
		},
	},

	mounted() {
		this.readLocation()
		this.search()
		window.addEventListener('popstate', this.onPopState)
	},

	beforeUnmount() {
		window.removeEventListener('popstate', this.onPopState)
	},

	methods: {
		/**
		 * Adopt search state from the browser location.
		 *
		 * `_search` and `_page` are the API's own parameter names, reused in
		 * the URL so a link a visitor shares and a request a developer curls
		 * describe the same search.
		 *
		 * @return {void}
		 */
		readLocation() {
			const params = new URLSearchParams(window.location.search)

			// `query` alone: the box takes its displayed term from the `value`
			// prop and watches it, so a back/forward that changes the query
			// refills the field without this component reaching into it.
			this.query = params.get('_search') || ''
			this.page = Math.max(1, parseInt(params.get('_page'), 10) || 1)

			const facets = params.get('_facets')
			this.selectedFacets = facets ? facets.split(',').filter(Boolean) : []
		},

		/**
		 * Write the current search into the browser location.
		 *
		 * `route` is left alone: it belongs to the host renderer, and rewriting
		 * it here would navigate the portal away from the page this block is
		 * on.
		 *
		 * @param {boolean} push Whether to add a history entry.
		 * @return {void}
		 */
		writeLocation(push) {
			const url = new URL(window.location.href)

			const assign = (key, value) => {
				if (value) {
					url.searchParams.set(key, value)
				} else {
					url.searchParams.delete(key)
				}
			}

			assign('_search', this.query)
			assign('_page', this.page > 1 ? String(this.page) : '')
			assign('_facets', this.selectedFacets.join(','))

			if (push === true) {
				window.history.pushState({}, '', url)
			} else {
				window.history.replaceState({}, '', url)
			}
		},

		/**
		 * Handle browser back/forward.
		 *
		 * @return {void}
		 */
		onPopState() {
			this.readLocation()
			this.search()
		},

		/**
		 * The URL for the request behind the current state.
		 *
		 * @return {string} The request URL.
		 */
		requestUrl() {
			return buildRequestUrl({
				endpoint: this.endpoint,
				origin: window.location.origin,
				pageSize: this.pageSize,
				page: this.page,
				query: this.query,
				facetField: this.facetField,
				selectedFacets: this.selectedFacets,
			})
		},

		/**
		 * Run the search behind the current state.
		 *
		 * @return {Promise<void>} Resolves when the state has been updated.
		 */
		async search() {
			this.sequence++
			const mine = this.sequence
			this.loading = true
			this.error = false

			try {
				const response = await fetch(this.requestUrl(), {
					headers: { Accept: 'application/json' },
				})

				if (response.ok === false) {
					throw new Error(`HTTP ${response.status}`)
				}

				const body = await response.json()

				// A superseded request must not land. Checked AFTER the await,
				// because that is where the overtaking happens.
				if (mine !== this.sequence) {
					return
				}

				this.results = (body.results || []).map((row) => toResult(row))
				this.total = body.total || 0
				this.pages = Math.max(1, body.pages || 1)
				this.facetBuckets = toBuckets(body.facets, this.facetField)
			} catch {
				// The reason is deliberately not surfaced to the visitor: this
				// runs at a public origin and a raw fetch/parse message would
				// leak the shape of the backend to anyone who unplugs it. The
				// visitor gets `errorLabel`; an operator gets the failed request
				// in their own network log.
				if (mine !== this.sequence) {
					return
				}

				this.error = true
				this.results = []
				this.total = 0
				this.pages = 1
				this.facetBuckets = []
			} finally {
				if (mine === this.sequence) {
					this.loading = false
				}
			}
		},

		/**
		 * Run the term the search box handed over.
		 *
		 * @param {string} term The submitted term.
		 * @return {void}
		 */
		onSearch(term) {
			this.query = term || ''
			// A new query invalidates the page number. Staying on page 7 of a
			// previous search shows an empty list for a search that has
			// results, which reads as "nothing found".
			this.page = 1
			this.writeLocation(true)
			this.search()
		},

		/**
		 * Add or remove a facet value.
		 *
		 * @param {string} value The bucket value.
		 * @return {void}
		 */
		toggleFacet(value) {
			if (this.selectedFacets.includes(value) === true) {
				this.selectedFacets = this.selectedFacets.filter(
					(entry) => entry !== value,
				)
			} else {
				this.selectedFacets = [...this.selectedFacets, value]
			}

			this.page = 1
			this.writeLocation(true)
			this.search()
		},

		/**
		 * The shareable href for a page number.
		 *
		 * @param {number} entry The page number.
		 * @return {string} The href.
		 */
		hrefForPage(entry) {
			const url = new URL(window.location.href)

			if (entry > 1) {
				url.searchParams.set('_page', String(entry))
			} else {
				url.searchParams.delete('_page')
			}

			return url.toString()
		},

		/**
		 * Move to a page.
		 *
		 * @param {number} entry The page number.
		 * @return {void}
		 */
		goToPage(entry) {
			this.page = entry
			this.writeLocation(true)
			this.search()
		},
	},
}
</script>

<style scoped>
.pq-search__row {
	display: flex;
	gap: 8px;
	align-items: flex-start;
	margin-block-start: 4px;
}

.pq-search__row .utrecht-textbox {
	flex: 1 1 auto;
	min-width: 0;
}

.pq-search__layout {
	display: block;
}

.pq-search__layout--faceted {
	display: grid;
	grid-template-columns: minmax(180px, 1fr) 3fr;
	gap: 24px;
	align-items: start;
}

.pq-search__facet-list,
.pq-search__list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.pq-search__result {
	/* The card family is themed by the token set; only spacing between cards
	   belongs to this block. */
	margin-block-end: 16px;
	padding: 16px;
}

.pq-search__result-title {
	/* The card lays its children out with `order`, so the title states its own
	   position rather than relying on source order. */
	order: 0;
	margin-block: 0 4px;
}

.pq-search__source {
	color: var(--nldesign-color-text-muted, #65757b);
	font-size: 0.875rem;
	margin: 0;
}

.pq-search__facet-count {
	color: var(--nldesign-color-text-muted, #65757b);
}

@media (max-width: 768px) {
	/* One column on a phone: a 180px facet rail beside results leaves neither
	   enough room to read, and this is a public, mobile-visited surface. */
	.pq-search__layout {
		display: block;
	}

	.pq-search__facets {
		margin-block-end: 16px;
	}
}
</style>
