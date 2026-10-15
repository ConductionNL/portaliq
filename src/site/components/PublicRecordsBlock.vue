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
		<article v-else-if="recordId && record" data-testid="public-record">
			<h2 class="utrecht-heading-2">
				{{ record.title }}
			</h2>
			<p v-if="record.subtitle" class="utrecht-paragraph">
				{{ record.subtitle }}
			</p>
			<KpiCards
				v-if="record.summary.length"
				:cards="figures.cards"
				:row="figures.row"
				:level="3"
				:t="t"
				:locale="language" />
			<CollectionTable
				v-if="record.rows.length"
				:collection="tableCollection"
				:objects="record.rows"
				:t="t"
				:locale="language" />
			<p
				v-if="record.note"
				class="utrecht-paragraph"
				data-testid="public-record-note">
				{{ record.note }}
			</p>
			<p class="utrecht-paragraph">
				<a :href="backHref" data-testid="public-record-back">{{
					backLabel
				}}</a>
			</p>
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
			<ul>
				<li v-for="entry in shown" :key="entry.id">
					<a :href="hrefFor(entry)">{{ entry.title }}</a>
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
	matchingEntries,
	recordFigures,
	recordIdFrom,
	recordTableCollection,
} from '../lib/publicRecordsApi.js'
import {
	addressOfRecord,
	addressOfRoute,
	routeOfLocation,
} from '../lib/siteAddress.js'
import { pageLocale } from '../pages/inbox/translate.js'

import '@utrecht/paragraph-css/dist/index.css'

const STRINGS = {
	nl: {
		loading: 'Laden…',
		gone: 'Dit overzicht bestaat niet (meer).',
		search: 'Zoek een naam',
		back: 'Terug naar het overzicht',
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
		/** The record id (test seam); read from `?record=` otherwise. */
		recordParam: { type: String, default: '' },
		/** `{list(app, list), record(app, list, id)}` (test seam); the public routes otherwise. */
		apiOverride: { type: Object, default: null },
	},

	data() {
		return { state: 'loading', entries: [], record: null, typed: '' }
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
		 * @spec openspec/changes/site-council-pages-read-decidiq/specs/site-council-records/spec.md#requirement-the-council-pages-show-decidiqs-published-meetings-and-decisions-req-scp-001
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
		 * A real link to one record, so a new tab and a failed script still work.
		 *
		 * @param {object} entry A list entry.
		 * @return {string} The href.
		 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t3
		 * @spec openspec/changes/record-and-search-links-keep-their-portal/specs/site-navigation/spec.md#requirement-a-link-to-a-record-or-a-search-result-keeps-the-portal-and-the-route-req-rsl-001
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
