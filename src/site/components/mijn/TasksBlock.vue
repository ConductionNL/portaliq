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
		<LoadError
			v-else-if="failed"
			:text="tr('What you still have to do could not be loaded.')"
			:retryLabel="tr('Try again')"
			@retry="$emit('retry')" />
		<EmptyState
			v-else-if="entries.length === 0"
			:text="tr('You have nothing to do right now.')" />
		<!-- The "do this first" card (site-school-blocks, display: highlight):
		     an accent-light ground and a small label, never a coloured edge. -->
		<ul
			v-else-if="block.display === 'highlight'"
			class="pq-tasks-block__highlights">
			<li
				v-for="entry in entries"
				:key="entry.id || entry.title"
				class="pq-tasks-block__highlight"
				:class="{ [`pq-tasks-block__highlight--${block.tone}`]: block.tone }"
				data-testid="mijn-task-highlight">
				<div class="pq-tasks-block__highlight-text">
					<p v-if="block.eyebrow" class="pq-tasks-block__eyebrow">
						{{ block.eyebrow }}
					</p>
					<p class="pq-tasks-block__highlight-title">{{ entry.title }}</p>
					<p v-if="lineOf(entry)" class="pq-tasks-block__highlight-line">
						{{ lineOf(entry) }}
					</p>
				</div>
				<a
					v-if="entry.route"
					class="utrecht-button utrecht-button--primary-action pq-tasks-block__highlight-button"
					:href="hrefOf(entry.route)"
					@click.prevent="open(entry)">
					{{ block.buttonLabel || tr('Open') }}
				</a>
			</li>
		</ul>
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
import LoadError from './LoadError.vue'
import Skeleton from './Skeleton.vue'
import {
	keepRecordToOpen,
	recordRoute,
	sessionStore,
} from '../../pages/inbox/inbox.js'
import { dayInWords } from './cases.js'
import { joined } from './displays.js'
import { deadlineBadge, mijnTranslator, siteHref, taskRows } from './rows.js'

/**
 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-tasks-and-messages-must-render-as-action-rows-with-text-badges-req-smo-004
 */
export default {
	name: 'TasksBlock',

	components: { ActionRow, EmptyState, LoadError, Skeleton },

	props: {
		/** The normalised block: `collection`, `dueField?`, `titleFields?`, `limit?`, `label?`. */
		block: { type: Object, required: true },
		/** The collection it reads. */
		collection: { type: Object, default: null },
		/** The collection's rows, scoped to the resident. */
		rows: { type: Array, default: () => [] },
		/** Whether the rows are still loading. */
		loading: { type: Boolean, default: false },
		/** Whether the rows could not be read: an alert, never "nothing to do". */
		failed: { type: Boolean, default: false },
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

	emits: ['navigate', 'retry'],

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
					subtitle: joined(
						entry.row,
						this.block.subtitleFields,
						' · ',
						this.collection,
					),
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
		 * @param {string} route An in-site route.
		 * @return {string} Its real address.
		 * @spec openspec/changes/site-school-blocks/specs/portal-contribution-contract/spec.md#requirement-the-overview-blocks-may-take-the-school-displays
		 */
		hrefOf(route) {
			return siteHref(route)
		},

		/**
		 * The line under a highlight's title: its sub line, and with
		 * `dueInLine` the day it is due by ("Voor uw aanvraag, uiterlijk 18
		 * oktober").
		 *
		 * @param {object} entry The row.
		 * @return {string}
		 * @spec openspec/changes/zuiddrecht-resident-pages-match-the-boards/specs/portal-contribution-contract/spec.md#requirement-a-contribution-may-declare-the-board-displays
		 */
		lineOf(entry) {
			const parts = [entry.subtitle]
			if (this.block?.dueInLine === true && entry.due) {
				const day = dayInWords(
					entry.due,
					this.today || new Date(),
					this.locale,
				)
				if (day) {
					parts.push(this.tr('no later than {date}', { date: day }))
				}
			}
			return parts.filter(Boolean).join(', ')
		},

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

.pq-tasks-block__highlights {
	display: grid;
	gap: 0.75rem;
	margin: 0;
	padding: 0;
	list-style: none;
}

.pq-tasks-block__highlight {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: 1rem;
	padding: 1.25rem 1.5rem;
	border: 1px solid
		var(--nldesign-color-accent, var(--nldesign-color-border, currentcolor));
	border-radius: var(
		--nldesign-website-border-radius-large,
		var(--utrecht-border-radius-md, 0.75rem)
	);
	background: var(
		--nldesign-color-accent-light,
		var(--nldesign-color-primary-light, transparent)
	);
}

/* The board's tones (zuiddrecht-resident-pages-match-the-boards): a
   warning wash for something the resident must send, an info wash for
   something to read; the title a step larger. */
.pq-tasks-block__highlight--warning {
	border-color: color-mix(
		in srgb,
		var(--nldesign-color-warning, #e17000) 40%,
		var(--nldesign-color-background, #fff)
	);
	background: var(
		--nldesign-component-status-badge-warning-background-color,
		rgba(var(--nldesign-color-warning-rgb, 225, 112, 0), 0.12)
	);
}

.pq-tasks-block__highlight--info {
	border-color: color-mix(
		in srgb,
		var(--nldesign-color-primary, #1b1b23) 40%,
		var(--nldesign-color-background, #fff)
	);
	background: var(
		--nldesign-component-status-badge-info-background-color,
		var(--nldesign-color-primary-light, transparent)
	);
}

.pq-tasks-block__highlight--warning .pq-tasks-block__highlight-title,
.pq-tasks-block__highlight--info .pq-tasks-block__highlight-title {
	font-size: 1.1875rem;
}

.pq-tasks-block__eyebrow {
	margin: 0 0 0.25rem;
	color: var(
		--nldesign-color-accent-text,
		var(--utrecht-document-color, CanvasText)
	);
	font-size: 0.8125rem;
	font-weight: 700;
	letter-spacing: 0.06em;
	text-transform: uppercase;
}

.pq-tasks-block__highlight-title {
	margin: 0;
	font-weight: 700;
}

.pq-tasks-block__highlight-line {
	margin: 0.25rem 0 0;
}

.pq-tasks-block__list {
	margin: 0;
	padding: 0;
}
</style>
