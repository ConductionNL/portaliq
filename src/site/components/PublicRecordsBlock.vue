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
			<ul v-if="record.summary.length" class="pq-records__cards">
				<li
					v-for="card in record.summary"
					:key="card.label"
					class="pq-records__card"
					data-testid="public-record-card">
					<p class="pq-records__label">
						{{ card.label }}
					</p>
					<p class="pq-records__value">
						{{ card.value }}
					</p>
					<p v-if="card.detail" class="pq-records__detail">
						{{ card.detail }}
					</p>
				</li>
			</ul>
			<table
				v-if="record.rows.length"
				class="utrecht-table"
				data-testid="public-record-table">
				<thead>
					<tr>
						<th
							v-for="column in record.columns"
							:key="column.key"
							scope="col">
							{{ column.label }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="(row, index) in record.rows" :key="index">
						<td v-for="column in record.columns" :key="column.key">
							<a
								v-if="column.key === linkKey && row.subjectUrl"
								:href="row.subjectUrl"
								>{{ row[column.key] }}</a
							>
							<template v-else>
								{{ row[column.key] }}
							</template>
						</td>
					</tr>
				</tbody>
			</table>
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
				words.search
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
import { resolveApiBase } from '../lib/contentApi.js'
import {
	fetchRecord,
	fetchRecordList,
	matchingEntries,
	recordIdFrom,
} from '../lib/publicRecordsApi.js'
import { pageLocale } from '../pages/inbox/translate.js'

import '@utrecht/paragraph-css/dist/index.css'

const STRINGS = {
	nl: {
		loading: 'Laden…',
		gone: 'Dit overzicht bestaat niet (meer).',
		search: 'Zoek een naam',
		back: 'Terug naar het overzicht',
	},
	en: {
		loading: 'Loading…',
		gone: 'This overview no longer exists.',
		search: 'Search for a name',
		back: 'Back to the overview',
	},
}

/**
 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t3
 */
export default {
	name: 'PublicRecordsBlock',
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
		words() {
			return STRINGS[pageLocale(this.locale)] || STRINGS.nl
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
		 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t3
		 */
		backLabel() {
			return this.backText || this.words.back
		},

		/**
		 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t3
		 */
		backHref() {
			return typeof window === 'undefined' ? '?' : window.location.pathname
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
		 */
		hrefFor(entry) {
			return `?record=${encodeURIComponent(entry.id)}`
		},
	},
}
</script>
