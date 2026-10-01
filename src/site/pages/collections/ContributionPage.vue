<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<section
		class="pq-contribution-page"
		:data-page="currentPage ? currentPage.id : undefined"
		data-testid="contribution-page">
		<p
			v-if="recordNotFound"
			class="utrecht-paragraph pq-contribution-page__notice"
			role="status"
			data-testid="contribution-page-record-not-found">
			{{ tr('This record is not in your list, so nothing of it is shown.') }}
		</p>

		<template v-for="item in blocks" :key="item.index">
			<RichTextBlock
				v-if="item.kind === 'richText'"
				:markdown="item.block.markdown || ''" />

			<div
				v-else-if="item.kind === 'table'"
				class="pq-contribution-page__collection"
				:data-collection="item.collection.id"
				data-testid="contribution-page-collection">
				<!-- One heading per title: a collection named like the page it
				     is on is already titled by the shell's h1, whose id it then
				     takes as its label. -->
				<h2
					v-if="showsHeading(item)"
					:id="headingId(item)"
					class="utrecht-heading-3">
					{{ item.collection.label }}
				</h2>
				<!-- A collection that declares groupByField shows one table per
				     child, each named by its own heading
				     (collection-group-by-field). -->
				<template
					v-for="group in groupsOf(item)"
					:key="group.value || '_rest'">
					<h3
						:id="groupHeadingId(item, group)"
						class="utrecht-heading-4 pq-contribution-page__group"
						data-testid="contribution-page-group">
						{{ group.label || tr('Other') }}
					</h3>
					<CollectionTable
						:collection="item.collection"
						:objects="group.rows"
						:loading="loadedOf(item.collection).loading"
						:selectable="true"
						:selectedRow="selected[item.collection.id] || null"
						:rowActions="item.tableActions"
						:offers="offers"
						:busyRow="busyRow"
						:labelledby="groupHeadingId(item, group)"
						:t="tr"
						:locale="lang"
						@select="select(item.collection, $event)"
						@rowAction="(action, row) => onRowAction(item, action, row)" />
				</template>
				<CollectionTable
					v-if="groupsOf(item).length === 0"
					:collection="item.collection"
					:objects="loadedOf(item.collection).objects"
					:loading="loadedOf(item.collection).loading"
					:selectable="true"
					:selectedRow="selected[item.collection.id] || null"
					:rowActions="item.tableActions"
					:offers="offers"
					:busyRow="busyRow"
					:labelledby="labelOf(item)"
					:t="tr"
					:locale="lang"
					@select="select(item.collection, $event)"
					@rowAction="(action, row) => onRowAction(item, action, row)" />
				<!-- Sign and decline get their own step, every other endpoint
				     row action the plain confirm step: slice c fills it. -->
				<SlotHost
					v-if="pending && pending.collectionId === item.collection.id"
					:key="pendingKey"
					name="rowAction"
					:dialog="pending.dialog"
					:action="pending.action"
					:viewAction="item.viewAction"
					:collection="item.collection"
					:row="pending.row"
					:api="api"
					:t="tr"
					:locale="lang"
					@done="afterWrite(item.collection)"
					@close="pending = null" />
			</div>

			<DetailCard
				v-else-if="item.kind === 'detail'"
				:collection="item.collection"
				:row="selected[item.collection.id] || null"
				:api="api"
				:proposeAction="item.proposeAction"
				:t="tr"
				:locale="lang" />

			<SlotHost
				v-else-if="item.kind === 'citizenCase'"
				name="citizenCase"
				:block="item.block"
				:collection="item.collection"
				:row="selected[item.collection.id] || null"
				:api="api"
				:t="tr"
				:locale="lang" />

			<SlotHost
				v-else-if="item.kind === 'timedTask'"
				name="timedTask"
				:block="item.block"
				:collection="item.collection"
				:app="currentContribution ? currentContribution.app || '' : ''"
				:attempts="loadedOf(item.collection).objects"
				:api="api"
				:t="tr"
				:locale="lang"
				@changed="afterWrite(item.collection)" />

			<SlotHost
				v-else-if="item.kind === 'action' || item.kind === 'cta'"
				name="action"
				:block="item.block"
				:action="item.action"
				:contribution="currentContribution"
				:api="api"
				:t="tr"
				:locale="lang"
				@created="(object, written) => afterWrite(written || item.action)" />
		</template>
	</section>
</template>

<script>
import CollectionTable from '../../components/collections/CollectionTable.vue'
import DetailCard from '../../components/collections/DetailCard.vue'
import RichTextBlock from '../../components/collections/RichTextBlock.vue'
import SlotHost from '../../components/collections/SlotHost.vue'
import {
	isEndpointRowAction,
	offersRowAction,
} from '../../../portal/lib/rowAction.js'
import { dialogFor } from '../../../portal/lib/signing.js'
import {
	anyGrouped,
	groupFieldOf,
	groupLabelCollection,
	groupRows,
} from '../../../shared/collectionGroups.js'
import { consumeOpenTarget, forgetOpenTarget } from '../../../shared/openRecord.js'
import { rowIdOf } from '../../components/collections/cells.js'
import { createCollectionLoader, openRecordState } from './collectionLoader.js'
import { resolveBlocks } from './pageBlocks.js'
import { collectionsTranslator, pageLocale } from './translate.js'

/**
 * Where a record link is kept across the sign-in.
 *
 * @return {Storage|null}
 */
function sessionStore() {
	try {
		return typeof window !== 'undefined' ? window.sessionStorage : null
	} catch {
		return null
	}
}

/**
 * One contribution page (slice b, b1): its ordered blocks, rendered from the
 * contribution the page belongs to. The site's counterpart of the React
 * portal's PageView.jsx, registered under the `contribution` key.
 *
 * Tables, detail cards, item lists, timelines and rich text are built here.
 * Forms and buttons (`action`, `cta`), endpoint row action steps, change
 * proposals and attached actions (slice c), timed tasks (slice d) and the
 * resident's case (slice e) go to their named place (SlotHost, see
 * blockSlots.js), so this page never builds another slice's screen.
 *
 * On mount the page loads every collection its blocks read. After a write it
 * loads every collection on that register and schema again and emits
 * `unread` with the fresh count.
 *
 * A record link (`#open=<app>/<collection>/<id>`, kept across the sign-in)
 * that names a collection on this page selects that row, from the resident's
 * own scoped rows only. A record not in that list opens nothing and says so.
 *
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-contribution-page-must-render-its-blocks-req-srp-014
 */
export default {
	name: 'ContributionPage',

	components: { CollectionTable, DetailCard, RichTextBlock, SlotHost },

	// The shell hands every page the whole contract (session, portal, nav, …);
	// this page reads none of those, and they must not land on the DOM.
	inheritAttrs: false,

	props: {
		/** The navigation entry: `key`, `label`, `page`, `contribution`. */
		entry: { type: Object, default: null },
		/** The page, when no entry carries it. */
		page: { type: Object, default: null },
		/** The contribution, when no entry carries it. */
		contribution: { type: Object, default: null },
		/** The shared portal api, bound to the resident's bearer. */
		api: { type: Object, default: null },
		/** The contributions aggregate, or its list. */
		contributions: { type: [Object, Array], default: null },
		/** The site translator. */
		t: { type: Function, default: null },
		/** The language. */
		locale: { type: String, default: '' },
		/** A record link to open, when the shell already read it. */
		openRecord: { type: Object, default: null },
		/** Rows to start from, by collection id, for a server render or a test. */
		initialData: { type: Object, default: null },
		/** Rows selected from the start, by collection id. */
		initialSelected: { type: Object, default: null },
	},

	emits: ['navigate', 'unread', 'refresh', 'recordOpened'],

	data() {
		return {
			store: { ...(this.initialData || {}) },
			selected: { ...(this.initialSelected || {}) },
			pending: null,
			busyRow: null,
			recordNotFound: false,
			target: this.openRecord,
			loader: null,
		}
	},

	computed: {
		currentPage() {
			return this.entry?.page || this.page || null
		},

		currentContribution() {
			return this.entry?.contribution || this.contribution || null
		},

		lang() {
			return pageLocale(this.locale)
		},

		tr() {
			return collectionsTranslator(this.t, this.lang)
		},

		blocks() {
			return resolveBlocks(this.currentPage, this.currentContribution)
		},

		allContributions() {
			const list = Array.isArray(this.contributions)
				? this.contributions
				: this.contributions?.contributions
			return Array.isArray(list) && list.length > 0
				? list
				: [this.currentContribution].filter(Boolean)
		},

		pendingKey() {
			return this.pending
				? `${this.pending.action.id}:${rowIdOf(this.pending.row) || ''}`
				: ''
		},

		targetOnPage() {
			const target = this.target
			if (!target || target.app !== this.currentContribution?.app) {
				return null
			}
			return this.blocks.some(
				(item) =>
					item.collection && item.collection.id === target.collection,
			)
				? target
				: null
		},

		targetState() {
			const target = this.targetOnPage
			return target
				? openRecordState(this.store[target.collection], target)
				: { state: 'waiting' }
		},
	},

	watch: {
		targetState(next) {
			this.applyTarget(next)
		},

		currentPage() {
			this.selected = {}
			this.pending = null
			this.loadPage()
		},
	},

	mounted() {
		this.loader = createCollectionLoader({ api: this.api, store: this.store })
		if (!this.target) {
			this.target = consumeOpenTarget(
				window.location,
				window.history,
				sessionStore(),
			)
		}
		this.loadPage()
	},

	methods: {
		loadPage() {
			if (this.loader && this.currentPage) {
				this.loader.loadPage(this.currentPage, this.currentContribution)
				this.loadGroupLabels()
			}
		},

		/**
		 * Load the rows that name the groups (the guardian's children), when
		 * a table on this page groups its rows.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/collection-group-by-field/tasks.md#T3
		 */
		loadGroupLabels() {
			const source = groupLabelCollection(this.currentContribution)
			const grouped = anyGrouped(
				this.blocks
					.filter((item) => item.kind === 'table')
					.map((item) => item.collection),
			)
			if (source && grouped && !this.store[source.id]) {
				this.loader.load(source)
			}
		},

		/**
		 * A table block's rows in groups, or [] to render it as one table.
		 *
		 * @param {object} item The page block.
		 * @return {Array<{value: string, label: string, rows: Array<object>}>}
		 *
		 * @spec openspec/changes/collection-group-by-field/tasks.md#T3
		 */
		groupsOf(item) {
			const source = groupLabelCollection(this.currentContribution)
			return groupRows(
				this.loadedOf(item.collection).objects,
				groupFieldOf(item.collection),
				source ? this.store[source.id]?.objects || [] : [],
			)
		},

		groupHeadingId(item, group) {
			return `${this.headingId(item)}-group-${group.value ? group.value.replace(/[^A-Za-z0-9_-]/g, '') : 'rest'}`
		},

		loadedOf(collection) {
			return (
				this.store[collection.id] || {
					loading: !this.initialData,
					objects: [],
				}
			)
		},

		headingId(item) {
			return `pq-collection-${item.index}-${item.collection.id}`
		},

		/**
		 * Whether a collection shows its own heading: it has a label, and
		 * the label is not the page title the shell already shows as h1.
		 *
		 * @param {object} item The page block.
		 * @return {boolean}
		 *
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-contribution-page-must-render-its-blocks-req-srp-014
		 */
		showsHeading(item) {
			const label = item.collection.label || ''
			return label !== '' && label !== (this.entry && this.entry.label)
		},

		/**
		 * The id of the heading that names a collection's table: its own,
		 * else the shell's page title, else none.
		 *
		 * @param {object} item The page block.
		 * @return {string} The id, or ''.
		 *
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-contribution-page-must-render-its-blocks-req-srp-014
		 */
		labelOf(item) {
			if (this.showsHeading(item)) {
				return this.headingId(item)
			}
			return item.collection.label ? 'site-account-title' : ''
		},

		offers(action, row) {
			return offersRowAction(action, row)
		},

		select(collection, row) {
			this.selected = { ...this.selected, [collection.id]: row }
		},

		/**
		 * Select the linked record once its collection has loaded, or say it
		 * is not in the resident's list; then forget the link.
		 *
		 * @param {object} state What openRecordState answered.
		 * @return {void}
		 */
		applyTarget(state) {
			const target = this.targetOnPage
			if (!target || state.state === 'waiting') {
				return
			}
			if (state.state === 'found') {
				this.selected = { ...this.selected, [target.collection]: state.row }
			}
			this.recordNotFound = state.state === 'missing'
			forgetOpenTarget(sessionStore())
			this.target = null
			this.$emit('recordOpened', target)
		},

		/**
		 * A row button: an endpoint action opens its step below the table, a
		 * `type: update` transition runs at once with no field data.
		 *
		 * @param {object} item The resolved table block.
		 * @param {object} action The action.
		 * @param {object} row The row.
		 * @return {Promise<void>}
		 */
		async onRowAction(item, action, row) {
			if (isEndpointRowAction(action)) {
				this.pending = {
					collectionId: item.collection.id,
					action,
					row,
					dialog: dialogFor(action),
				}
				return
			}
			if (!this.loader) {
				return
			}
			this.busyRow = rowIdOf(row) || null
			try {
				await this.loader.transition(action, row, item.collection)
			} finally {
				this.busyRow = null
			}
		},

		/**
		 * After a write: load every collection on that register and schema
		 * again, and hand the fresh unread count to the shell.
		 *
		 * @param {{register: string, schema: string}} written What was written to.
		 * @return {Promise<void>}
		 */
		async afterWrite(written) {
			if (!this.loader || !written) {
				return
			}
			const unread = await this.loader.afterWrite(this.allContributions, {
				register: written.register,
				schema: written.schema,
			})
			if (typeof unread === 'number') {
				this.$emit('unread', unread)
			}
		},
	},
}
</script>

<style scoped>
.pq-contribution-page__collection {
	margin-block-end: var(--utrecht-space-block-lg, 1.5rem);
}
</style>
