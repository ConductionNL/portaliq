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
		<component
			:is="`h${level}`"
			v-if="label"
			:id="headingId"
			class="utrecht-heading-3">
			{{ label }}
		</component>
		<Skeleton
			v-if="messages === null && !failed"
			:label="tr('Loading')"
			:rows="2" />
		<p v-else-if="failed" class="utrecht-paragraph" role="alert">
			{{ tr('Your messages could not be loaded.') }}
		</p>
		<EmptyState
			v-else-if="entries.length === 0"
			:text="tr('You have no messages yet.')" />
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
import Skeleton from './Skeleton.vue'
import { routeForNav } from '../../../shared/portalNav.js'
import {
	keepRecordToOpen,
	recordRoute,
	sessionStore,
} from '../../pages/inbox/inbox.js'
import { inboxRows, mijnTranslator, receivedInWords, siteHref } from './rows.js'

/** The site route of the inbox, as the shell builds it. */
const INBOX_ROUTE = routeForNav({ special: 'inbox' })

/**
 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-tasks-and-messages-must-render-as-action-rows-with-text-badges-req-smo-004
 */
export default {
	name: 'InboxBlock',

	components: { ActionRow, EmptyState, Skeleton },

	props: {
		/** The normalised block: `collection?`, `limit?`, `label?`. */
		block: { type: Object, required: true },
		/** The shared portal api (`fetchInbox`). */
		api: { type: Object, default: null },
		/** The app of the contribution the block belongs to. */
		app: { type: String, default: '' },
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
		 * @return {string} The inbox's real address.
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-tasks-and-messages-must-render-as-action-rows-with-text-badges-req-smo-004
		 */
		allHref() {
			return siteHref(INBOX_ROUTE)
		},

		/**
		 * The rows on screen, each with where it leads.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-tasks-and-messages-must-render-as-action-rows-with-text-badges-req-smo-004
		 */
		entries() {
			return inboxRows(this.messages, this.block, this.app).map(
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
</style>
