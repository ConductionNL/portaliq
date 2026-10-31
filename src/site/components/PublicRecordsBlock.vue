<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	A contributed public record list on a site page (site-member-voting-record-
	and-confidential-papers). Without ?record it shows the list with a search
	field. With ?record=<id> it shows that record on the same page: heading,
	summary figures, the rows as a table, the provider's note and a link back.
	Everything is read anonymously, and shown as plain text.
-->
<template>
	<section class="pq-records" data-testid="public-records">
		<p
			v-if="state === 'loading'"
			class="utrecht-paragraph"
			role="status"
			data-testid="public-records-loading">
			{{ words.loading }}
		</p>
		<p
			v-else-if="state === 'gone'"
			class="utrecht-paragraph"
			role="alert"
			data-testid="public-records-gone">
			{{ words.gone }}
		</p>
		<article
			v-else-if="recordId && record"
			class="pq-records__record"
			data-testid="public-record">
			<div class="pq-records__main">
				<h2 class="utrecht-heading-2">
					{{ recordTitle }}
				</h2>
				<form
					v-if="filters.length"
					class="pq-records__filters"
					data-testid="public-record-filters"
					@submit.prevent="applyFilters">
					<div
						v-for="filter in filters"
						:key="filter.key"
						class="pq-records__filter">
						<label
							class="utrecht-form-label"
							:for="`pq-records-filter-${filter.key}`"
							>{{ filter.label }}</label
						>
						<select
							:id="`pq-records-filter-${filter.key}`"
							:value="chosen[filter.key] || ''"
							class="utrecht-select utrecht-select--html-select"
							:data-testid="`public-record-filter-${filter.key}`"
							@change="choose(filter.key, $event.target.value)">
							<option value="">
								{{ filter.all || words.all }}
							</option>
							<option
								v-for="option in filter.options"
								:key="option"
								:value="option">
								{{ option }}
							</option>
						</select>
					</div>
					<button
						type="submit"
						class="utrecht-button utrecht-button--secondary-action"
						data-testid="public-record-filter-apply">
						{{ filterButton || words.apply }}
					</button>
				</form>
				<p
					v-if="countLine"
					class="utrecht-paragraph pq-records__count"
					role="status"
					data-testid="public-record-count">
					{{ countLine }}
				</p>
				<CollectionTable
					v-if="shownPage.rows.length"
					:collection="tableCollection"
					:objects="shownPage.rows"
					:t="t"
					:locale="language" />
				<nav
					v-if="shownPage.pages > 1"
					class="pq-records__pager"
					:aria-label="words.paging"
					data-testid="public-record-pager">
					<span>{{ rangeLine }}</span>
					<span class="pq-records__pages">
						<button
							v-if="shownPage.page > 1"
							type="button"
							class="utrecht-button utrecht-button--subtle"
							data-testid="public-record-page-previous"
							@click="page = shownPage.page - 1">
							{{ words.previous }}
						</button>
						<button
							v-for="number in shownPage.pages"
							:key="number"
							type="button"
							class="utrecht-button utrecht-button--subtle"
							:aria-current="
								number === shownPage.page ? 'page' : undefined
							"
							:aria-label="fill(words.pageNumber, { number })"
							:data-testid="`public-record-page-${number}`"
							@click="page = number">
							{{ number }}
						</button>
						<button
							v-if="shownPage.page < shownPage.pages"
							type="button"
							class="utrecht-button utrecht-button--subtle"
							data-testid="public-record-page-next"
							@click="page = shownPage.page + 1">
							{{ words.next }}
						</button>
					</span>
				</nav>
				<p
					v-if="record.note"
					class="utrecht-paragraph"
					data-testid="public-record-note">
					{{ record.note }}
				</p>
			</div>
			<aside class="pq-records__side" data-testid="public-record-side">
				<div class="pq-records__who">
					<span class="pq-records__initials" aria-hidden="true">{{
						initials
					}}</span>
					<div>
						<p class="utrecht-paragraph pq-records__name">
							{{ record.title }}
						</p>
						<p v-if="record.subtitle" class="utrecht-paragraph">
							{{ record.subtitle }}
						</p>
					</div>
				</div>
				<KpiCards
					v-if="record.summary.length"
					variant="list"
					:cards="figures.cards"
					:row="figures.row"
					:level="3"
					:t="t"
					:locale="language" />
				<ul class="pq-records__links">
					<li>
						<a :href="backHref" data-testid="public-record-back">{{
							backLabel
						}}</a>
					</li>
					<li v-for="link in sideLinks" :key="link.href">
						<a :href="link.address" data-testid="public-record-link">{{
							link.label
						}}</a>
					</li>
				</ul>
			</aside>
		</article>
		<div v-else data-testid="public-records-list">
			<h2 v-if="title" class="utrecht-heading-2">
				{{ title }}
			</h2>
			<label class="utrecht-form-label" for="pq-records-search">{{
				searchText
			}}</label>
			<input
				id="pq-records-search"
				v-model="typed"
				class="utrecht-textbox utrecht-textbox--html-input"
				type="search"
				autocomplete="off" />
			<ul class="pq-records__list">
				<li v-for="entry in shown" :key="entry.id">
					<a
						class="utrecht-link pq-records__link"
						:href="hrefFor(entry)"
						>{{ entry.title }}</a
					>
					<span v-if="entry.subtitle" class="pq-records__subtitle">
						{{ entry.subtitle }}
					</span>
				</li>
			</ul>
		</div>
	</section>
</template>

<script>
import CollectionTable from './collections/CollectionTable.vue'
import KpiCards from './collections/KpiCards.vue'
import { resolveApiBase, runtimeConfig } from '../lib/contentApi.js'
import {
	fetchRecord,
	fetchRecordList,
	fillText,
	filteredRows,
	initialsOf,
	matchingEntries,
	recordFigures,
	recordFilters,
	recordIdFrom,
	recordTableCollection,
	rowsPage,
} from '../lib/publicRecordsApi.js'
import {
	addressOfRecord,
	addressOfRoute,
	routeOfLocation,
} from '../lib/siteAddress.js'
import { pageLocale } from '../pages/inbox/translate.js'

import '@utrecht/button-css/dist/index.css'
import '@utrecht/paragraph-css/dist/index.css'
import '@utrecht/select-css/dist/index.css'

const STRINGS = {
	nl: {
		loading: 'Laden…',
		gone: 'Dit overzicht bestaat niet (meer).',
		search: 'Zoek een naam',
		back: 'Terug naar het overzicht',
		all: 'Alle',
		apply: 'Toon',
		paging: 'Paginering',
		range: '{from} tot {to} van {total}',
		previous: 'Vorige',
		next: 'Volgende',
		pageNumber: 'Pagina {number}',
		// The words the shared figure cards and table may show.
		shared: {
			'Loading…': 'Laden…',
			'No items.': 'Geen items.',
			'No figures yet.': 'Nog geen cijfers.',
			'Needs attention': 'Vraagt aandacht',
		},
	},
	en: {
		loading: 'Loading…',
		gone: 'This overview no longer exists.',
		search: 'Search for a name',
		back: 'Back to the overview',
		all: 'All',
		apply: 'Show',
		paging: 'Pages',
		range: '{from} to {to} of {total}',
		previous: 'Previous',
		next: 'Next',
		pageNumber: 'Page {number}',
		shared: {},
	},
}

/**
 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t3
 */
export default {
	name: 'PublicRecordsBlock',
	components: { CollectionTable, KpiCards },
	props: {
		/** The contributing app that declares the list. */
		app: { type: String, default: '' },
		/** The id of the contributed record list. */
		list: { type: String, default: '' },
		/** A heading above the list; none by default. */
		title: { type: String, default: '' },
		/** The name of the column that links to its subject when a row has one. */
		linkKey: { type: String, default: 'subject' },
		/** The text of the link back to the list; "Alle ..." style, from the page. */
		backText: { type: String, default: '' },
		/** The label of the search field ("Zoek een vergadering"); the block's own words when empty. */
		searchLabel: { type: String, default: '' },
		/** The reader's locale; the page language when empty. */
		locale: { type: String, default: '' },
		/** The record's heading, with `{title}` for its title ("Stemgedrag van {title}"); the title when empty. */
		recordHeading: { type: String, default: '' },
		/** Filters on the record's rows: `[{key, label, all?}]`, `key` a column, `all` the no-choice option. */
		filterBy: { type: Array, default: () => [] },
		/** The filter button's text ("Toon stemmingen"); the block's own words when empty. */
		filterButton: { type: String, default: '' },
		/** The line above the rows, with `{count}`, `{from}` and `{to}` ("{count} stemmingen"); none when empty. */
		countText: { type: String, default: '' },
		/** Rows per page; 0 shows every row. */
		pageSize: { type: Number, default: 0 },
		/** Links under the side card: `[{label, href}]`, `href` a site route or an http(s) address. */
		links: { type: Array, default: () => [] },
		/** The record id (test seam); read from `?record=` otherwise. */
		recordParam: { type: String, default: '' },
		/** `{list(app, list), record(app, list, id)}` (test seam); the public routes otherwise. */
		apiOverride: { type: Object, default: null },
	},

	data() {
		return {
			state: 'loading',
			entries: [],
			record: null,
			typed: '',
			chosen: {},
			applied: {},
			page: 1,
		}
	},

	computed: {
		/**
		 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t3
		 */
		language() {
			return pageLocale(this.locale)
		},

		/**
		 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t3
		 */
		words() {
			return STRINGS[this.language] || STRINGS.nl
		},

		/**
		 * The translator the shared figure cards and table use.
		 *
		 * @return {Function} The translator.
		 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t3
		 */
		t() {
			const shared = this.words.shared || {}
			return (key) => shared[key] || key
		},

		/**
		 * The record's summary as the shared figure cards read it.
		 *
		 * @return {{cards: Array<object>, row: object}} The cards and their row.
		 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t3
		 */
		figures() {
			return recordFigures(this.record?.summary)
		},

		/**
		 * The record's columns as the shared table reads them.
		 *
		 * @return {{columns: Array<object>}} The table's collection.
		 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t3
		 */
		tableCollection() {
			return recordTableCollection(this.record?.columns, this.linkKey)
		},

		/**
		 * The record's heading: the page's sentence around the title, or the title.
		 *
		 * @return {string} The heading.
		 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t3
		 */
		recordTitle() {
			const title = this.record?.title || ''
			return this.recordHeading
				? fillText(this.recordHeading, { title })
				: title
		},

		/**
		 * The page's filters with the values the rows hold.
		 *
		 * @return {Array<object>} The filters.
		 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t3
		 */
		filters() {
			return recordFilters(
				this.record?.rows,
				this.filterBy,
				this.record?.columns,
			)
		},

		/**
		 * The page of rows on screen, after the applied filters.
		 *
		 * @return {object} The page (publicRecordsApi rowsPage).
		 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t3
		 */
		shownPage() {
			return rowsPage(
				filteredRows(this.record?.rows, this.applied),
				this.page,
				this.pageSize,
			)
		},

		/**
		 * @return {string} The line above the rows, or ''.
		 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t3
		 */
		countLine() {
			const { total, from, to } = this.shownPage
			return this.countText
				? fillText(this.countText, { count: total, from, to })
				: ''
		},

		/**
		 * @return {string} "1 tot 5 van 48".
		 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t3
		 */
		rangeLine() {
			const { total, from, to } = this.shownPage
			return fillText(this.words.range, { from, to, total })
		},

		/**
		 * @return {string} The initials on the side card.
		 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t3
		 */
		initials() {
			return initialsOf(this.record?.title)
		},

		/**
		 * The side card's links that have a label and a site route or an
		 * http(s) address, each with the address it opens.
		 *
		 * @return {Array<{label: string, href: string, address: string}>} The links.
		 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t3
		 */
		sideLinks() {
			return (Array.isArray(this.links) ? this.links : [])
				.filter(
					(link) =>
						link
						&& typeof link.label === 'string'
						&& link.label !== ''
						&& typeof link.href === 'string'
						&& /^(\/(?!\/)|https?:\/\/)/.test(link.href),
				)
				.map((link) => ({
					label: link.label,
					href: link.href,
					address: this.addressOf(link.href),
				}))
		},

		/**
		 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t3
		 */
		recordId() {
			if (this.recordParam) {
				return this.recordParam
			}

			return typeof window === 'undefined'
				? ''
				: recordIdFrom(window.location.search)
		},

		/**
		 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t3
		 */
		shown() {
			return matchingEntries(this.entries, this.typed)
		},

		/**
		 * The search field's label: the page's, or the block's own words.
		 *
		 * @return {string} The label.
		 * @spec openspec/specs/site-council-records/spec.md#requirement-the-council-pages-show-decidiqs-published-meetings-and-decisions-req-scp-001
		 */
		searchText() {
			return this.searchLabel || this.words.search
		},

		/**
		 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t3
		 */
		backLabel() {
			return this.backText || this.words.back
		},

		/**
		 * The list this record came from: the page's route, the portal and
		 * the language stay, only `?record=` goes. The bare path dropped
		 * `?portal=` and `?route=`, so "Alle vergaderingen" opened the site's
		 * home on a long address (live round 3, 14 Oct).
		 *
		 * @return {string} The href.
		 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t3
		 * @spec openspec/changes/resident-pages-live-round-3/specs/site-navigation/spec.md#requirement-a-record-pages-back-link-keeps-the-portal-and-the-route-req-rr3-003
		 */
		backHref() {
			if (typeof window === 'undefined') {
				return '?'
			}
			try {
				const href = window.location.href
				const config = runtimeConfig()
				return addressOfRoute(
					routeOfLocation(new URL(href), config),
					href,
					config,
				).toString()
			} catch {
				return window.location.pathname
			}
		},
	},

	async mounted() {
		await this.load()
	},

	methods: {
		/**
		 * Read the list, and the record when the page names one. A list or a
		 * record that is gone shows the stale-link sentence.
		 *
		 * @return {Promise<void>} Resolves when read.
		 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t3
		 */
		async load() {
			this.state = 'loading'
			try {
				const api = this.apiOverride || {
					list: (app, list) =>
						fetchRecordList(resolveApiBase(), app, list),

					record: (app, list, id) =>
						fetchRecord(resolveApiBase(), app, list, id),
				}
				const entries = await api.list(this.app, this.list)
				if (entries === null) {
					this.state = 'gone'
					return
				}

				this.entries = entries
				if (this.recordId) {
					this.record = await api.record(
						this.app,
						this.list,
						this.recordId,
					)
					if (this.record === null) {
						this.state = 'gone'
						return
					}
				}

				this.state = 'ready'
			} catch {
				this.state = 'gone'
			}
		},

		/**
		 * Keep a filter's choice until the button applies it.
		 *
		 * @param {string} key The column.
		 * @param {string} value The chosen value; '' for every value.
		 * @return {void}
		 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t3
		 */
		choose(key, value) {
			this.chosen = { ...this.chosen, [key]: String(value || '') }
		},

		/**
		 * Show the rows that hold the chosen values, from the first page.
		 *
		 * @return {void}
		 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t3
		 */
		applyFilters() {
			this.applied = { ...this.chosen }
			this.page = 1
		},

		/**
		 * @param {string} text A sentence with placeholders.
		 * @param {object} vars The values.
		 * @return {string} The sentence.
		 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t3
		 */
		fill(text, vars) {
			return fillText(text, vars)
		},

		/**
		 * The address of a side card link: a site route keeps the portal and
		 * the language, like every other link on the site.
		 *
		 * @param {string} href A site route or an http(s) address.
		 * @return {string} The address.
		 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t3
		 */
		addressOf(href) {
			if (!href.startsWith('/') || typeof window === 'undefined') {
				return href
			}
			try {
				return addressOfRoute(
					href,
					window.location.href,
					runtimeConfig(),
				).toString()
			} catch {
				return href
			}
		},

		/**
		 * A real link to one record, so a new tab and a failed script still work.
		 *
		 * @param {object} entry A list entry.
		 * @return {string} The href.
		 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t3
		 * @spec openspec/specs/site-navigation/spec.md#requirement-a-link-to-a-record-or-a-search-result-keeps-the-portal-and-the-route-req-rsl-001
		 */
		hrefFor(entry) {
			// The page's route and the portal stay on the link: a bare
			// `?record=` dropped both and opened another portal on a shared
			// host (siteAddress.js).
			try {
				return addressOfRecord(
					String(entry.id),
					window.location.href,
					runtimeConfig(),
				).toString()
			} catch {
				return `?record=${encodeURIComponent(entry.id)}`
			}
		},
	},
}
</script>

<style scoped>
/* The list of records: one per line, the name as a link and what it is
   under it in the muted ink. */
.pq-records__list {
	display: flex;
	flex-direction: column;
	gap: 0.75rem;
	margin: 1rem 0 0;
	padding: 0;
	list-style: none;
}

.pq-records__list li {
	display: flex;
	flex-direction: column;
	gap: 0.125rem;
}

.pq-records__link {
	font-size: 1.125rem;
	font-weight: 600;
}

.pq-records__subtitle {
	color: var(--nldesign-color-text-muted, currentcolor);
	font-size: 0.9375rem;
}

.pq-records__record {
	display: grid;
	gap: var(--utrecht-space-block-lg, 2rem);
	grid-template-columns: minmax(0, 2fr) minmax(16rem, 1fr);
	align-items: start;
}

@media (max-width: 48rem) {
	.pq-records__record {
		grid-template-columns: minmax(0, 1fr);
	}
}

.pq-records__filters {
	display: flex;
	flex-wrap: wrap;
	gap: var(--utrecht-space-block-md, 1rem);
	align-items: end;
}

.pq-records__filter {
	display: flex;
	flex-direction: column;
	gap: var(--utrecht-space-block-xs, 0.375rem);
}

.pq-records__pager,
.pq-records__pages {
	display: flex;
	flex-wrap: wrap;
	gap: var(--utrecht-space-inline-sm, 0.5rem);
	align-items: center;
	justify-content: space-between;
}

.pq-records__pages button[aria-current='page'] {
	font-weight: bold;
	text-decoration: underline;
}

/* The side column as the voting record board draws it: no box, a 4px rule in
   the accent colour on top (PtStemgedrag). */
.pq-records__side {
	display: flex;
	flex-direction: column;
	gap: 1rem;
	border-block-start: 4px solid var(--nldesign-color-accent, currentcolor);
	padding-block-start: 1.25rem;
}

/* The record's table: a tinted header row, 12px 16px cells, a hairline
   between the rows. */
.pq-records__main :deep(table) {
	border-collapse: collapse;
	inline-size: 100%;
}

.pq-records__main :deep(th) {
	padding: 12px 16px;
	font-size: 1rem;
	background: var(--nldesign-color-surface, #f4f6f9);
	border: 0;
	text-align: start;
}

.pq-records__main :deep(td) {
	padding: 12px 16px;
	border: 0;
	border-block-start: 1px solid var(--nldesign-color-border, #d3d8df);
	vertical-align: top;
}

.pq-records__who {
	display: flex;
	gap: var(--utrecht-space-inline-md, 0.75rem);
	align-items: center;
}

.pq-records__initials {
	display: inline-flex;
	flex: none;
	align-items: center;
	justify-content: center;
	block-size: 3rem;
	inline-size: 3rem;
	border-radius: 50%;
	background: var(--utrecht-color-blue-90, var(--color-primary-element-light));
	color: var(--utrecht-color-blue-20, var(--color-primary-element-light-text));
	font-weight: bold;
}

.pq-records__name {
	font-weight: bold;
	margin: 0;
}

.pq-records__links {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
	margin: 0;
	padding: 0;
	list-style: none;
}
</style>
