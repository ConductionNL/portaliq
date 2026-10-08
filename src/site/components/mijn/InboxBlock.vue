<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	An `inbox` block: the resident's newest messages, of one inbox collection
	or of every inbox, unread first. An unread row carries "Nieuw". A row
	opens the record it is about when a page shows it, else the inbox; "Alle
	berichten" always leads to the inbox.
-->
<template>
	<section
		class="pq-inbox-block"
		:aria-labelledby="label ? headingId : undefined"
		data-testid="mijn-inbox-block">
		<!-- The plain list carries "Alle berichten" beside its heading
		     (zuiddrecht-resident-pages-match-the-boards). -->
		<div v-if="label && plain" class="pq-inbox-block__head">
			<component :is="`h${level}`" :id="headingId" class="utrecht-heading-3">
				{{ label }}
			</component>
			<a
				class="utrecht-link pq-inbox-block__all-link"
				:href="allHref"
				data-testid="mijn-inbox-all"
				@click="openAll"
				>{{ tr('All messages') }}</a
			>
		</div>
		<component
			:is="`h${level}`"
			v-else-if="label"
			:id="headingId"
			class="utrecht-heading-3">
			{{ label }}
		</component>
		<Skeleton
			v-if="messages === null && !failed"
			:label="tr('Loading')"
			:rows="2" />
		<LoadError
			v-else-if="failed"
			:text="tr('Your messages could not be loaded.')"
			:retryLabel="tr('Try again')"
			@retry="retry" />
		<EmptyState
			v-else-if="entries.length === 0"
			:text="tr('You have no messages yet.')" />
		<!-- The plain list: a title and the day, no badge, no chevron. -->
		<ul v-else-if="plain" class="pq-inbox-block__plain">
			<li
				v-for="entry in entries"
				:key="entry.key"
				class="pq-inbox-block__plain-row"
				data-testid="mijn-inbox-plain-row">
				<a
					class="utrecht-link pq-inbox-block__plain-title"
					:class="{ 'pq-inbox-block__plain-title--unread': entry.unread }"
					:href="hrefOf(entry.route)"
					@click="openRow($event, entry)"
					>{{ entry.title }}</a
				>
				<span class="pq-inbox-block__plain-day">{{ entry.day }}</span>
			</li>
		</ul>
		<template v-else>
			<ul class="pq-inbox-block__list">
				<ActionRow
					v-for="entry in entries"
					:key="entry.key"
					:title="entry.title"
					:meta="entry.meta"
					:route="entry.route"
					:unread="entry.unread"
					:badges="
						entry.unread ? [{ text: tr('New'), state: 'success' }] : []
					"
					@open="open(entry)" />
			</ul>
			<p class="utrecht-paragraph pq-inbox-block__all">
				<a class="utrecht-link" :href="allHref" @click="openAll">
					{{ tr('All messages') }}
				</a>
			</p>
		</template>
	</section>
</template>

<script>
import ActionRow from './ActionRow.vue'
import EmptyState from './EmptyState.vue'
import LoadError from './LoadError.vue'
import Skeleton from './Skeleton.vue'
import { routeForNav } from '../../../shared/portalNav.js'
import {
	keepRecordToOpen,
	recordRoute,
	sessionStore,
} from '../../pages/inbox/inbox.js'
import { dayInWords } from './cases.js'
import { inboxRows, mijnTranslator, receivedInWords, siteHref } from './rows.js'

const DAY_MS = 24 * 60 * 60 * 1000

/** The site route of the inbox, as the shell builds it. */
const INBOX_ROUTE = routeForNav({ special: 'inbox' })

/**
 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-tasks-and-messages-must-render-as-action-rows-with-text-badges-req-smo-004
 */
export default {
	name: 'InboxBlock',

	components: { ActionRow, EmptyState, LoadError, Skeleton },

	props: {
		/** The normalised block: `collection?`, `limit?`, `label?`. */
		block: { type: Object, required: true },
		/** The shared portal api (`fetchInbox`). */
		api: { type: Object, default: null },
		/** The app of the contribution the block belongs to. */
		app: { type: String, default: '' },
		/** The open record, when the block narrows to it (`recordField`). */
		record: { type: Object, default: null },
		/** Every navigation entry, to find the page that shows a record. */
		nav: { type: Array, default: () => [] },
		/** The heading level of the block's label. */
		level: { type: Number, default: 2 },
		/** The site translator. */
		t: { type: Function, default: null },
		/** The page language. */
		locale: { type: String, default: 'nl' },
		/** Messages to start from, for a test. */
		initialMessages: { type: Array, default: null },
		/** Today; a test passes a fixed day. */
		today: { type: Date, default: null },
	},

	emits: ['navigate'],

	data() {
		return {
			messages: this.initialMessages,
			failed: false,
		}
	},

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
			return `pq-inbox-${String(this.block?.collection || 'all').replace(/[^A-Za-z0-9_-]/g, '')}`
		},

		/**
		 * The messages about the open record when the block names a
		 * `recordField` (REQ-SMO-025); every message otherwise. Without an
		 * open record such a block shows none.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/portal-contribution-contract/spec.md#requirement-tasks-and-inbox-blocks-may-narrow-to-the-open-record-and-leave-rows-out-by-a-lookup-req-smo-025
		 */
		aboutTheRecord() {
			const field = this.block?.recordField
			if (!field) {
				return this.messages
			}
			const id = String(
				this.record?.id
					|| this.record?.uuid
					|| this.record?.['@self']?.id
					|| '',
			)
			return (this.messages || []).filter(
				(message) => id !== '' && String(message?.[field] ?? '') === id,
			)
		},

		/**
		 * @return {string} The inbox's real address.
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-tasks-and-messages-must-render-as-action-rows-with-text-badges-req-smo-004
		 */
		allHref() {
			return siteHref(INBOX_ROUTE)
		},

		/**
		 * @return {boolean} Whether the block draws the plain list.
		 * @spec openspec/changes/zuiddrecht-resident-pages-match-the-boards/specs/portal-contribution-contract/spec.md#requirement-a-contribution-may-declare-the-board-displays
		 */
		plain() {
			return this.block?.display === 'list'
		},

		/**
		 * The rows on screen, each with where it leads.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-tasks-and-messages-must-render-as-action-rows-with-text-badges-req-smo-004
		 */
		entries() {
			return inboxRows(this.aboutTheRecord, this.block, this.app).map(
				(message, index) => {
					const link = message.recordLink?.id ? message.recordLink : null
					const recordAt = link ? recordRoute(this.nav, link) : null
					return {
						key: String(message.id || message.uuid || index),
						title: message.subject || '',
						meta: [
							message._source?.label || '',
							receivedInWords(
								message.receivedAt,
								this.today || new Date(),
								this.tr,
								this.locale,
							),
						]
							.filter(Boolean)
							.join(', '),
						unread: message.read !== true,
						link: recordAt ? link : null,
						route: recordAt || INBOX_ROUTE,
						day: this.dayOf(message.receivedAt),
					}
				},
			)
		},
	},

	/**
	 * Read on arrival.
	 *
	 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-tasks-and-messages-must-render-as-action-rows-with-text-badges-req-smo-004
	 */
	created() {
		if (this.messages === null) {
			this.load()
		}
	},

	methods: {
		/**
		 * The day a message came in, as the plain list says it: "vandaag",
		 * "gisteren", else the day in words.
		 *
		 * @param {string} value An ISO date-time.
		 * @return {string}
		 * @spec openspec/changes/zuiddrecht-resident-pages-match-the-boards/specs/portal-contribution-contract/spec.md#requirement-a-contribution-may-declare-the-board-displays
		 */
		dayOf(value) {
			const at = value ? new Date(value) : null
			if (!at || Number.isNaN(at.getTime())) {
				return ''
			}
			const today = this.today || new Date()
			const midnight = (d) =>
				new Date(d.getFullYear(), d.getMonth(), d.getDate())
			const days = Math.round(
				(midnight(today).getTime() - midnight(at).getTime()) / DAY_MS,
			)
			if (days === 0) {
				return this.tr('today')
			}
			if (days === 1) {
				return this.tr('yesterday')
			}
			return dayInWords(value, today, this.locale)
		},

		/**
		 * @param {string} route An in-site route.
		 * @return {string} Its real address.
		 * @spec openspec/changes/zuiddrecht-resident-pages-match-the-boards/specs/portal-contribution-contract/spec.md#requirement-a-contribution-may-declare-the-board-displays
		 */
		hrefOf(route) {
			return siteHref(route)
		},

		/**
		 * A plain click on a row of the plain list stays in the site.
		 *
		 * @param {MouseEvent} event The click.
		 * @param {object} entry The row.
		 * @return {void}
		 * @spec openspec/changes/zuiddrecht-resident-pages-match-the-boards/specs/portal-contribution-contract/spec.md#requirement-a-contribution-may-declare-the-board-displays
		 */
		openRow(event, entry) {
			if (event?.ctrlKey || event?.metaKey || event?.shiftKey) {
				return
			}
			event?.preventDefault?.()
			this.open(entry)
		},

		/**
		 * Read the unified inbox; a read that fails says so, it is not empty.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-tasks-and-messages-must-render-as-action-rows-with-text-badges-req-smo-004
		 */
		async load() {
			let answer
			try {
				answer = await this.api?.fetchInbox?.()
			} catch {
				answer = null
			}
			this.failed = !Array.isArray(answer)
			this.messages = Array.isArray(answer) ? answer : []
		},

		/**
		 * Try the read again after it failed, showing the skeleton meanwhile.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-loading-and-empty-states-must-say-what-is-happening-req-smo-009
		 */
		async retry() {
			this.failed = false
			this.messages = null
			await this.load()
		},

		/**
		 * Open a row: its record when a page shows it, else the inbox.
		 *
		 * @param {object} entry The row.
		 * @return {void}
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-tasks-and-messages-must-render-as-action-rows-with-text-badges-req-smo-004
		 */
		open(entry) {
			if (entry.link) {
				keepRecordToOpen(sessionStore(), entry.link)
			}
			this.$emit('navigate', entry.route)
		},

		/**
		 * @param {MouseEvent} event The click.
		 * @return {void}
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-tasks-and-messages-must-render-as-action-rows-with-text-badges-req-smo-004
		 */
		openAll(event) {
			if (event?.ctrlKey || event?.metaKey || event?.shiftKey) {
				return
			}
			event?.preventDefault?.()
			this.$emit('navigate', INBOX_ROUTE)
		},
	},
}
</script>

<style scoped>
.pq-inbox-block {
	margin-block-end: var(--utrecht-space-block-lg, 1.5rem);
}

.pq-inbox-block__list {
	margin: 0;
	padding: 0;
}

.pq-inbox-block__all {
	margin-block-start: var(--utrecht-space-block-sm, 0.5rem);
}

/* The plain list (zuiddrecht-resident-pages-match-the-boards): hairlines
   between the rows, the title as a link, the day at the end. */
.pq-inbox-block__head {
	display: flex;
	flex-wrap: wrap;
	justify-content: space-between;
	align-items: baseline;
	gap: 0.5rem;
}

.pq-inbox-block__head > * {
	margin: 0;
}

.pq-inbox-block__all-link {
	font-weight: 600;
}

.pq-inbox-block__plain {
	margin: 0;
	padding: 0;
	list-style: none;
	border-block-start: 1px solid var(--nldesign-color-border, currentcolor);
}

.pq-inbox-block__plain-row {
	display: flex;
	flex-wrap: wrap;
	justify-content: space-between;
	gap: 0.5rem 1rem;
	padding-block: 1rem;
	border-block-end: 1px solid var(--nldesign-color-border, currentcolor);
}

.pq-inbox-block__plain-title {
	font-size: 1.125rem;
	font-weight: 600;
}

.pq-inbox-block__plain-day {
	color: var(
		--thematiq-website-text-muted,
		var(--nldesign-color-text-muted, inherit)
	);
	font-size: 0.9375rem;
}
</style>
