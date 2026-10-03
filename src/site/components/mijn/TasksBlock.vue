<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	A `tasks` block: what the resident still has to do, from one collection
	of the contribution, soonest deadline first. Each row is an action row
	with its deadline as a badge ("Voor 12 oktober", "Nog 3 dagen") and links
	to the page that shows that row. While the rows load it shows a skeleton,
	with none an empty state.
-->
<template>
	<section
		class="pq-tasks-block"
		:aria-labelledby="label ? headingId : undefined"
		:data-collection="block.collection"
		data-testid="mijn-tasks-block">
		<component
			:is="`h${level}`"
			v-if="label"
			:id="headingId"
			class="utrecht-heading-3">
			{{ label }}
		</component>
		<Skeleton v-if="loading && entries.length === 0" :label="tr('Loading')" />
		<EmptyState
			v-else-if="entries.length === 0"
			:text="tr('You have nothing to do right now.')" />
		<ul v-else class="pq-tasks-block__list">
			<ActionRow
				v-for="entry in entries"
				:key="entry.id || entry.title"
				:title="entry.title"
				:route="entry.route"
				:badges="entry.badge ? [entry.badge] : []"
				@open="open(entry)" />
		</ul>
	</section>
</template>

<script>
import ActionRow from './ActionRow.vue'
import EmptyState from './EmptyState.vue'
import Skeleton from './Skeleton.vue'
import {
	keepRecordToOpen,
	recordRoute,
	sessionStore,
} from '../../pages/inbox/inbox.js'
import { deadlineBadge, mijnTranslator, taskRows } from './rows.js'

/**
 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-tasks-and-messages-must-render-as-action-rows-with-text-badges-req-smo-004
 */
export default {
	name: 'TasksBlock',

	components: { ActionRow, EmptyState, Skeleton },

	props: {
		/** The normalised block: `collection`, `dueField?`, `titleFields?`, `limit?`, `label?`. */
		block: { type: Object, required: true },
		/** The collection it reads. */
		collection: { type: Object, default: null },
		/** The collection's rows, scoped to the resident. */
		rows: { type: Array, default: () => [] },
		/** Whether the rows are still loading. */
		loading: { type: Boolean, default: false },
		/** The app of the contribution the block belongs to. */
		app: { type: String, default: '' },
		/** Every navigation entry, to find the page that shows a row. */
		nav: { type: Array, default: () => [] },
		/** The heading level of the block's label. */
		level: { type: Number, default: 2 },
		/** The site translator. */
		t: { type: Function, default: null },
		/** The page language. */
		locale: { type: String, default: 'nl' },
		/** Today; a test passes a fixed day. */
		today: { type: Date, default: null },
	},

	emits: ['navigate'],

	computed: {
		/**
		 * @return {(key: string, vars?: object) => string} The translator.
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-tasks-and-messages-must-render-as-action-rows-with-text-badges-req-smo-004
		 */
		tr() {
			return mijnTranslator(this.t, this.locale)
		},

		/**
		 * @return {string} The block's heading, or ''.
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-tasks-and-messages-must-render-as-action-rows-with-text-badges-req-smo-004
		 */
		label() {
			return this.block?.label || ''
		},

		/**
		 * @return {string} The heading's id.
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-tasks-and-messages-must-render-as-action-rows-with-text-badges-req-smo-004
		 */
		headingId() {
			return `pq-tasks-${String(this.block?.collection || '').replace(/[^A-Za-z0-9_-]/g, '')}`
		},

		/**
		 * The rows with their badge and the route of the page that shows them.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-tasks-and-messages-must-render-as-action-rows-with-text-badges-req-smo-004
		 */
		entries() {
			return taskRows(this.rows, this.block, this.collection).map((entry) => {
				const link = {
					app: this.app,
					collection: this.block.collection,
					id: entry.id,
				}
				return {
					...entry,
					link,
					route: entry.id ? recordRoute(this.nav, link) || '' : '',
					badge: deadlineBadge(
						entry.due,
						this.today || new Date(),
						this.tr,
						this.locale,
					),
				}
			})
		},
	},

	methods: {
		/**
		 * Keep the row to open, so the page that shows it selects it, then go.
		 *
		 * @param {object} entry The row.
		 * @return {void}
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-tasks-and-messages-must-render-as-action-rows-with-text-badges-req-smo-004
		 */
		open(entry) {
			if (!entry.route) {
				return
			}
			keepRecordToOpen(sessionStore(), entry.link)
			this.$emit('navigate', entry.route)
		},
	},
}
</script>

<style scoped>
.pq-tasks-block {
	margin-block-end: var(--utrecht-space-block-lg, 1.5rem);
}

.pq-tasks-block__list {
	margin: 0;
	padding: 0;
}
</style>
