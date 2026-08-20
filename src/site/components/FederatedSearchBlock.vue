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
			<div class="pq-search__results">
				<p
					v-if="error"
					class="utrecht-alert utrecht-alert--error"
					role="alert"
					data-testid="federated-search-error">
					{{ errorLabel }}
				</p>

				<!--
					THE COUNT AND THE SORT SIT TOGETHER ABOVE THE LIST, as on
					the reference: an `h2` reading "N resultaten" on the left
					and the sort control on the right.

					The heading is not decoration. It is the only place the
					result count is a HEADING rather than a sentence, which is
					what lets a screen-reader user jump to the results with
					their heading navigation instead of tabbing past the form.
				-->
				<div v-if="!error" class="pq-search__results-header">
					<h2
						class="utrecht-heading-2 pq-search__count"
						data-testid="federated-search-count">
						{{ countLabel }}
					</h2>

					<div v-if="total > 0" class="pq-search__sort">
						<label
							class="utrecht-form-label"
							for="pq-federated-search-sort">
							{{ sortLabel }}
						</label>
						<select
							id="pq-federated-search-sort"
							class="utrecht-select"
							:value="sort"
							data-testid="federated-search-sort"
							@change="onSort($event.target.value)">
							<!--
								NO "MEEST RELEVANT" OPTION.

								The reference offers one. Nothing in this API
								implements relevance ordering, so it would be a
								control that changes the URL and not the list —
								the same class of defect as a filter that
								silently returns nothing. Every option below was
								checked against the live endpoint and returns a
								different first row.
							-->
							<option
								v-for="option in sortOptions"
								:key="option.value"
								:value="option.value">
								{{ option.label }}
							</option>
						</select>
					</div>
				</div>

				<ol
					v-if="!error"
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
						<!--
							THE TITLE IS TEXT, NOT A LINK, and that follows the
							reference rather than diverging from it.

							Measured on opencatalogi.nl: the card heading is a
							`<span>` at rgb(0, 0, 0), weight 400, with no
							underline; the only link in the card is
							"Lees meer over <title>". Rendering the title as a
							link as well produced a green underlined heading
							that matched nothing.

							It is also the better accessible shape: a link
							styled to look exactly like body text has no
							affordance, whereas "Lees meer over X" announces
							both what it does and which publication it does it
							to.
						-->
						<h3 class="utrecht-heading-3 pq-search__result-title">
							<span>{{ result.title }}</span>
						</h3>

						<p v-if="result.summary" class="utrecht-paragraph">
							{{ result.summary }}
						</p>

						<!--
							THE METADATA ROW, as on the reference: small bold
							items on the left, "Lees meer over X" on the right.

							THE SOURCE IS ONE OF THOSE ITEMS, and it is the only
							place federation is visible to a visitor. A federated
							list that does not say where a row came from is
							indistinguishable from a local one, which makes the
							feature unverifiable from the page — including for
							the person checking whether it works at all.
						-->
						<div class="pq-search__meta">
							<div class="pq-search__meta-items">
								<p
									v-if="result.date"
									class="utrecht-paragraph utrecht-paragraph--small pq-search__meta-item">
									<small>{{ result.date }}</small>
								</p>

								<!--
									Rendered only when a type is actually
									known. This envelope returns `@self.schema`
									as a numeric id and no title, so without an
									authored `typeLabel` the chip would read
									"17".
								-->
								<p
									v-if="result.type || typeLabel"
									class="utrecht-paragraph utrecht-paragraph--small pq-search__meta-item">
									<small>{{ result.type || typeLabel }}</small>
								</p>

								<p
									class="utrecht-paragraph utrecht-paragraph--small pq-search__meta-item"
									data-testid="federated-search-source">
									<small>
										<span class="sr-only"
											>{{ sourceLabel }}:
										</span>
										{{ result.directory }}
									</small>
								</p>
							</div>

							<!--
								The accessible name names the publication, so a
								screen-reader user listing links hears eleven
								distinct ones instead of eleven "Lees meer".
							-->
							<a
								class="utrecht-link pq-search__more"
								:href="detailHref(result)"
								@click.prevent="openDetail(result)">
								{{ moreLabel
								}}<span class="sr-only">
									over {{ result.title }}</span
								>
							</a>
						</div>
					</li>
				</ol>

				<!--
					PAGINATION IS A NAV OF LINKS, not buttons.

					The reference uses buttons; these are anchors carrying the
					full query. That is a deliberate, invisible divergence: it
					renders identically and additionally survives middle-click,
					"open in new tab" and a failed bundle — a paginated public
					register is exactly the kind of page people deep-link into.

					`1 2 3 4 5 … 36` including the gap marker, matching the
					reference measured at page 1 of 36.
				-->
				<nav
					v-if="pages > 1"
					class="ams-pagination"
					:aria-label="paginationLabel"
					data-testid="federated-search-pagination">
					<ul class="ams-pagination__list">
						<li
							v-for="(entry, index) in paginationRow"
							:key="`${entry}-${index}`">
							<span
								v-if="entry === 'gap'"
								class="pq-search__page-gap"
								aria-hidden="true">
								…
							</span>
							<a
								v-else
								class="ams-pagination__button"
								:class="{
									'ams-pagination__button--current':
										entry === page,
								}"
								:href="hrefForPage(entry)"
								:aria-current="entry === page ? 'page' : undefined"
								:aria-label="
									entry === page
										? `Pagina ${entry}`
										: `Ga naar pagina ${entry}`
								"
								:data-testid="`federated-search-page-${entry}`"
								@click.prevent="goToPage(entry)">
								{{ entry }}
							</a>
						</li>

						<li v-if="page < pages">
							<a
								class="ams-pagination__button"
								:href="hrefForPage(page + 1)"
								aria-label="Volgende pagina"
								data-testid="federated-search-page-next"
								@click.prevent="goToPage(page + 1)">
								›
							</a>
						</li>
					</ul>
				</nav>
			</div>

			<!--
				FILTERS COME AFTER THE RESULTS IN THE DOM and before them on
				screen, which is the reference's own arrangement: grid places
				the column, source order decides what a screen reader reaches
				first — and that is the results, which is what the visitor
				asked for.

				Rendered only when the API returned buckets. An empty filter
				column that is always present reads as "no filters match",
				which is a different claim from "this deployment does not
				facet".
			-->
			<aside
				v-if="facetBuckets.length"
				class="pq-search__facets"
				data-testid="federated-search-facets">
				<h2 class="utrecht-heading-2">{{ facetLabel }}</h2>
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
		</div>
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
	paginationItems,
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
			default: 'Filters',
		},

		/** Label beside the sort control. */
		sortLabel: {
			type: String,
			default: 'Sorteren',
		},

		/** Text of the per-result link through to the detail page. */
		moreLabel: {
			type: String,
			default: 'Lees meer',
		},

		/**
		 * A type name for every row, when the corpus has exactly one type.
		 *
		 * The API returns `@self.schema` as a numeric id and no title, so the
		 * component cannot derive "Publicatie" or "Publiccode" for itself. A
		 * page whose catalogue holds one kind of thing can say so here; a
		 * mixed catalogue leaves it empty and the chip is omitted rather than
		 * mislabelling half the rows.
		 */
		typeLabel: {
			type: String,
			default: '',
		},

		/**
		 * In-site route of the publication detail page.
		 *
		 * The id is appended. Kept a prop because the route is the portal's
		 * own content structure, not something this block gets to decide.
		 */
		detailRoute: {
			type: String,
			default: '/publicatie',
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

	emits: ['navigate'],

	data() {
		return {
			// What was actually searched for, as opposed to what is currently
			// typed. `CnSiteSearch` keeps the typed term to itself and hands it
			// over on submit, so the list never thrashes per keystroke and the
			// URL only ever reflects a real, shareable search.
			query: '',
			page: 1,
			// `field:DIRECTION`, matching what `buildRequestUrl` expects.
			// Empty means "no _order at all", which is the backend's own
			// default rather than a fifth ordering invented here.
			sort: '',
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
				return 'Zoekresultaten geladen. Geen resultaten gevonden.'
			}

			// Dutch has no "1 resultaten". The singular is worth the branch
			// because this string is read out by a screen reader on every
			// search, and a portal that cannot count to one in its own
			// language is the first thing a citizen notices about it.
			//
			// "Zoekresultaten", with the `ta`. The reference announces
			// "Zoekresulten"; a typo is not a pixel and is not worth matching.
			if (this.total === 1) {
				return 'Zoekresultaten geladen. 1 resultaat gevonden.'
			}

			return `Zoekresultaten geladen. ${this.total} resultaten gevonden.`
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

		/**
		 * Page numbers plus gap markers, as the reference renders them.
		 *
		 * @return {Array<number|string>} The pagination row.
		 */
		paginationRow() {
			return paginationItems(this.page, this.pages)
		},

		/**
		 * The result-count heading.
		 *
		 * @return {string} e.g. "11 resultaten".
		 */
		countLabel() {
			if (this.total === 1) {
				return '1 resultaat'
			}

			return `${this.total} resultaten`
		},

		/**
		 * The orderings this API actually implements.
		 *
		 * Each was checked against the live endpoint on 2026-08-20 and returns
		 * a different first row. The reference's "Meest relevant" is absent
		 * because nothing here implements relevance ranking.
		 *
		 * @return {Array<object>} `{value, label}` options.
		 */
		sortOptions() {
			return [
				{ value: '', label: 'Standaardvolgorde' },
				{ value: 'publicationDate:DESC', label: 'Datum - nieuw naar oud' },
				{ value: 'publicationDate:ASC', label: 'Datum - oud naar nieuw' },
				{ value: 'title:ASC', label: 'Naam - A naar Z' },
				{ value: 'title:DESC', label: 'Naam - Z naar A' },
			]
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
			this.sort = params.get('_sort') || ''
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
			assign('_sort', this.sort)

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
				sort: this.sort,
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
		 * Re-order the results.
		 *
		 * @param {string} value A `field:DIRECTION` pair, or '' for the default.
		 * @return {void}
		 */
		onSort(value) {
			this.sort = value || ''
			// Page 1, for the same reason a new query resets it: page 7 of the
			// old ordering is a different set of rows from page 7 of the new
			// one, and landing there looks like the sort lost the results.
			this.page = 1
			this.writeLocation(true)
			this.search()
		},

		/**
		 * The in-site href of a result's detail page.
		 *
		 * @param {object} result A view-model row.
		 * @return {string} The href.
		 */
		detailHref(result) {
			if (!result.id) {
				// No id means no detail page to link to; fall back to whatever
				// the row itself points at rather than emitting a dead link.
				return result.href || '#'
			}

			const url = new URL(window.location.href)
			url.search = ''
			url.searchParams.set('route', `${this.detailRoute}/${result.id}`)

			return url.toString()
		},

		/**
		 * Navigate to a result's detail page.
		 *
		 * Emits rather than navigating directly: the host renderer owns
		 * routing, and a block that called `history.pushState` itself would
		 * move the URL without telling the renderer to load anything.
		 *
		 * @param {object} result A view-model row.
		 * @return {void}
		 */
		openDetail(result) {
			if (!result.id) {
				if (result.href) {
					window.location.href = result.href
				}
				return
			}

			this.$emit('navigate', `${this.detailRoute}/${result.id}`)
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
	/* Filters left, results right, while the DOM order is results-first —
	   explicit column placement is what lets those two disagree. */
	grid-template-columns: minmax(180px, 1fr) 3fr;
	gap: 24px;
	align-items: start;
}

.pq-search__layout--faceted .pq-search__facets {
	grid-column: 1;
	grid-row: 1;
}

.pq-search__layout--faceted .pq-search__results {
	grid-column: 2;
	grid-row: 1;
}

.pq-search__results-header {
	display: flex;
	align-items: flex-end;
	justify-content: space-between;
	gap: 16px;
	flex-wrap: wrap;
	margin-block-end: 16px;
}

.pq-search__count {
	margin: 0;
}

.pq-search__sort {
	display: flex;
	align-items: center;
	gap: 8px;
}

.pq-search__meta {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 16px;
	flex-wrap: wrap;
}

.pq-search__meta-items {
	display: flex;
	align-items: center;
	gap: 16px;
	flex-wrap: wrap;
}

.pq-search__meta-item {
	/* Measured on the reference: 12px, weight 700, rgb(0, 56, 101). The colour
	   comes from the token so a re-theme moves it. */
	margin: 0;
	color: var(--nldesign-color-info, #003865);
	font-weight: 700;
}

.pq-search__page-gap {
	display: inline-block;
	padding: 6px 12px;
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
	/* Measured on the reference: rgb(0, 0, 0), weight 400. The heading token
	   sets a heavier weight for page titles, which a list row is not. */
	color: var(--utrecht-card-heading-color, #000000);
	font-weight: 400;
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
