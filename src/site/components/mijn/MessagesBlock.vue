<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	A `messages` block on a record page: the messages about the open record,
	both ways, newest first, from its collection's messages provider (dossiq:
	`caseMessages`). Each message says who wrote it and when. Loading shows a
	skeleton, a failed read an alert with "Opnieuw proberen", and nothing yet a
	sentence. The ask button above it is the page's cta for the block's
	`askAction`, so the question is stored on this case.
-->
<template>
	<section
		v-if="recordId"
		class="pq-messages-block"
		:aria-labelledby="headingId"
		data-testid="mijn-messages-block">
		<component :is="`h${level}`" :id="headingId" class="utrecht-heading-3">
			{{ heading }}
		</component>
		<Skeleton
			v-if="answer === null && !failed"
			:label="tr('Loading')"
			:rows="3" />
		<LoadError
			v-else-if="failed"
			:text="tr('The messages could not be loaded.')"
			:retryLabel="tr('Try again')"
			@retry="load" />
		<EmptyState
			v-else-if="messages.length === 0"
			:text="tr('There are no messages about this case yet.')" />
		<ol v-else class="pq-messages-block__list" data-testid="case-messages">
			<li
				v-for="(message, index) in messages"
				:key="message.id || index"
				class="pq-messages-block__item"
				:class="{ 'pq-messages-block__item--own': message.fromResident === true }"
				data-testid="case-message">
				<p class="utrecht-paragraph pq-messages-block__meta">
					<span>{{ writerOf(message, tr) }}</span>
					<time
						v-if="message.receivedAt"
						:datetime="message.receivedAt">
						{{ dateOf(message.receivedAt) }}
					</time>
					<span
						v-if="message.fromResident !== true && message.read === false"
						class="pq-messages-block__unread"
						data-testid="case-message-unread">
						{{ tr('Unread') }}
					</span>
				</p>
				<p
					v-if="message.subject"
					class="utrecht-paragraph pq-messages-block__subject">
					<strong>{{ message.subject }}</strong>
				</p>
				<p v-if="message.body" class="utrecht-paragraph pq-messages-block__body">
					{{ message.body }}
				</p>
			</li>
		</ol>
	</section>
</template>

<script>
import EmptyState from './EmptyState.vue'
import LoadError from './LoadError.vue'
import Skeleton from './Skeleton.vue'
import { newestMessagesFirst, writerOf } from './caseMessages.js'
import { longDate } from './dates.js'
import { loadRecordAnswer, recordIdOf } from './recordAnswer.js'
import { mijnTranslator } from './rows.js'

/**
 * @spec openspec/changes/capability-rows-said-and-planned/tasks.md#t07
 */
export default {
	name: 'MessagesBlock',

	components: { EmptyState, LoadError, Skeleton },

	props: {
		/** The normalised block: `collection`, `label?`, `askAction?`. */
		block: { type: Object, required: true },
		/** The collection, with its `messages` declaration. */
		collection: { type: Object, default: null },
		/** The open record. */
		record: { type: Object, default: null },
		/** The shared portal api (`fetchCaseMessages`). */
		api: { type: Object, default: null },
		/** The heading level. */
		level: { type: Number, default: 2 },
		/** The site translator. */
		t: { type: Function, default: null },
		/** The page language. */
		locale: { type: String, default: 'nl' },
		/** Changes after the resident asked a question: read again. */
		reloadKey: { type: Number, default: 0 },
		/** An answer to start from, for a test. */
		initialAnswer: { type: Object, default: null },
	},

	data() {
		return { answer: this.initialAnswer, failed: false }
	},

	computed: {
		/**
		 * @return {(key: string, vars?: object) => string} The translator.
		 * @spec openspec/changes/capability-rows-said-and-planned/tasks.md#t07
		 */
		tr() {
			return mijnTranslator(this.t, this.locale)
		},

		/**
		 * @return {string} The open record's id, or ''.
		 * @spec openspec/changes/capability-rows-said-and-planned/tasks.md#t07
		 */
		recordId() {
			return recordIdOf(this.record)
		},

		/**
		 * @return {string} The block's label, else the app's, else "Berichten".
		 * @spec openspec/changes/capability-rows-said-and-planned/tasks.md#t07
		 */
		heading() {
			return (
				this.block?.label
				|| this.answer?.label
				|| this.collection?.messages?.label
				|| this.tr('Messages')
			)
		},

		/**
		 * @return {string} The heading's id.
		 * @spec openspec/changes/capability-rows-said-and-planned/tasks.md#t07
		 */
		headingId() {
			return `pq-messages-${String(this.block?.collection || '').replace(/[^A-Za-z0-9_-]/g, '')}`
		},

		/**
		 * @return {Array<object>} The messages, newest first.
		 * @spec openspec/changes/capability-rows-said-and-planned/tasks.md#t07
		 */
		messages() {
			return newestMessagesFirst(this.answer?.messages)
		},
	},

	watch: {
		/**
		 * Another record opened: read its messages.
		 *
		 * @return {void}
		 * @spec openspec/changes/capability-rows-said-and-planned/tasks.md#t07
		 */
		recordId() {
			this.load()
		},

		/**
		 * The resident asked a question: read the messages again.
		 *
		 * @return {void}
		 * @spec openspec/changes/capability-rows-said-and-planned/tasks.md#t07
		 */
		reloadKey() {
			this.load()
		},
	},

	/**
	 * Read the open record's messages on arrival.
	 *
	 * @spec openspec/changes/capability-rows-said-and-planned/tasks.md#t07
	 */
	created() {
		if (this.answer === null) {
			this.load()
		}
	},

	methods: {
		writerOf,

		/**
		 * @param {string} value The date.
		 * @return {string} The date in words, or the value as it came.
		 * @spec openspec/changes/capability-rows-said-and-planned/tasks.md#t07
		 */
		dateOf(value) {
			return longDate(value, this.locale) || value
		},

		/**
		 * Read the messages; a read that fails says so, it is not "none yet".
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/capability-rows-said-and-planned/tasks.md#t07
		 */
		load() {
			return loadRecordAnswer(this, { api: this.api, fetch: 'fetchCaseMessages', list: 'messages' })
		},
	},
}
</script>

<style scoped>
.pq-messages-block {
	margin-block-end: var(--utrecht-space-block-lg, 1.5rem);
}

.pq-messages-block__list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.pq-messages-block__item {
	border-block-end: 1px solid var(--utrecht-color-grey-80, var(--color-border));
	padding-block: var(--utrecht-space-block-sm, 0.75rem);
}

.pq-messages-block__item--own {
	padding-inline-start: var(--utrecht-space-inline-md, 1rem);
}

.pq-messages-block__meta {
	display: flex;
	flex-wrap: wrap;
	gap: var(--utrecht-space-inline-sm, 0.5rem);
	color: var(--utrecht-color-grey-30, var(--color-text-maxcontrast));
	margin-block-end: 0;
}

.pq-messages-block__unread {
	font-weight: bold;
	color: var(--utrecht-color-blue-35, var(--color-primary-element));
}

.pq-messages-block__subject,
.pq-messages-block__body {
	margin-block: var(--utrecht-space-block-2xs, 0.25rem);
	white-space: pre-line;
}
</style>
