<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	A guardian's conversations with school (guardian-direct-messages), read
	only, in the language the guardian picks (translated-message-notice). The
	picker writes the account's own `messageLanguage`; a message the server
	translated shows through TranslatedText, with the AI notice and the
	original one click away.
-->
<template>
	<section class="pq-messages">
		<Skeleton v-if="threads === null" :label="mt('Loading')" :rows="2" />
		<template v-else>
			<MessageLanguagePicker
				id="portaliq-message-language"
				:label="tr('Show messages in')"
				:hint="
					tr(
						'Messages from school are translated by AI into this language. You can always see the original text.',
					)
				"
				:language="language"
				:error="error"
				:t="tr"
				:locale="lang"
				@change="changeLanguage" />

			<EmptyState
				v-if="threads.length === 0"
				:text="tr('No conversations yet.')" />

			<nav
				v-else
				class="pq-messages__threads"
				:aria-label="tr('Conversations')">
				<!-- Each conversation a Den Haag action row that opens it on
				     this page (site-mijn-omgeving-components REQ-SMO-004). -->
				<ul>
					<ActionRow
						v-for="thread in threads"
						:key="idOf(thread)"
						:title="
							thread.kind === 'group'
								? tr('Group conversation')
								: tr('Conversation with school')
						"
						:meta="dateTime(thread.createdAt)"
						:button="true"
						:current="idOf(thread) === activeId"
						@open="choose(idOf(thread))" />
				</ul>
			</nav>

			<Skeleton
				v-if="activeId && messages === null"
				:label="mt('Loading')"
				:rows="2" />

			<ol v-if="messages" class="pq-messages__list">
				<li
					v-for="(message, i) in messages"
					:key="idOf(message, i)"
					class="pq-message"
					:class="{ 'pq-message--own': isOwn(message) }">
					<div class="pq-message__header">
						<strong class="pq-message__sender">
							{{ isOwn(message) ? tr('You') : tr('School') }}
						</strong>
						<span class="pq-message__date">{{
							dateTime(message.sentAt)
						}}</span>
					</div>
					<TranslatedText
						:id="idOf(message, i)"
						:text="message.body || ''"
						:translation="message.translation || null"
						:t="tr"
						:locale="lang" />
				</li>
			</ol>
		</template>
	</section>
</template>

<script>
import MessageLanguagePicker from '../../components/inbox/MessageLanguagePicker.vue'
import TranslatedText from '../../components/inbox/TranslatedText.vue'
import ActionRow from '../../components/mijn/ActionRow.vue'
import EmptyState from '../../components/mijn/EmptyState.vue'
import Skeleton from '../../components/mijn/Skeleton.vue'
import { mijnTranslator } from '../../components/mijn/rows.js'
import { formatDateTime, rowId } from './inbox.js'
import { PAGE_EMITS, PAGE_PROPS } from './pageProps.js'
import { pageLocale, withStrings } from './translate.js'

/**
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-guardian-must-read-messages-in-their-chosen-language-req-srp-032
 */
export default {
	name: 'MessagesPage',

	components: {
		ActionRow,
		EmptyState,
		MessageLanguagePicker,
		Skeleton,
		TranslatedText,
	},

	props: PAGE_PROPS,

	emits: PAGE_EMITS,

	data() {
		return {
			threads: null,
			activeId: null,
			messages: null,
			language: '',
			error: '',
		}
	},

	computed: {
		/**
		 * @return {string} `nl` or `en`.
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-guardian-must-read-messages-in-their-chosen-language-req-srp-032
		 */
		lang() {
			return pageLocale(this.locale)
		},

		/**
		 * @return {(key: string, vars?: object) => string} The translator.
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-guardian-must-read-messages-in-their-chosen-language-req-srp-032
		 */
		tr() {
			return withStrings(this.t, this.lang)
		},

		/**
		 * @return {(key: string, vars?: object) => string} The translator of the mijn omgeving components.
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-tasks-and-messages-must-render-as-action-rows-with-text-badges-req-smo-004
		 */
		mt() {
			return mijnTranslator(this.t, this.lang)
		},
	},

	created() {
		this.load()
	},

	methods: {
		/**
		 * Read the threads and the chosen language, then open the first thread.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-guardian-must-read-messages-in-their-chosen-language-req-srp-032
		 */
		async load() {
			const [list, details] = await Promise.all([
				this.api.fetchThreads(),
				this.api.getDetails(),
			])
			this.threads = Array.isArray(list) ? list : []
			this.language = details?.messageLanguage || ''
			if (this.threads.length > 0) {
				await this.choose(rowId(this.threads[0]))
			}
		},

		/**
		 * Open one thread and read its messages.
		 *
		 * @param {string} threadId The thread.
		 * @return {Promise<void>}
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-guardian-must-read-messages-in-their-chosen-language-req-srp-032
		 */
		async choose(threadId) {
			this.activeId = threadId || null
			if (!threadId) {
				return
			}
			this.messages = null
			this.messages = (await this.api.fetchThreadMessages(threadId)) || []
		},

		/**
		 * Save the picked language, then read the thread again in it.
		 *
		 * @param {string} next The language tag, '' for as written.
		 * @return {Promise<void>}
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-guardian-must-read-messages-in-their-chosen-language-req-srp-032
		 */
		async changeLanguage(next) {
			this.error = ''
			const result = await this.api.setMessageLanguage(next)
			if (!result?.ok) {
				this.error = this.tr('Your language choice could not be saved.')
				return
			}
			this.language = next
			await this.choose(this.activeId)
		},

		/**
		 * @param {object} row A thread or message.
		 * @param {number} [i] Its position.
		 * @return {string|number} Its id.
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-guardian-must-read-messages-in-their-chosen-language-req-srp-032
		 */
		idOf(row, i = null) {
			return rowId(row, i)
		},

		/**
		 * @param {object} message A message.
		 * @return {boolean} Whether the reader sent it.
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-guardian-must-read-messages-in-their-chosen-language-req-srp-032
		 */
		isOwn(message) {
			return (
				Boolean(this.session?.subjectRef)
				&& message.senderRef === this.session.subjectRef
			)
		},

		/**
		 * @param {string} value An ISO date-time.
		 * @return {string} The value in the page language.
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-guardian-must-read-messages-in-their-chosen-language-req-srp-032
		 */
		dateTime(value) {
			return formatDateTime(value, this.lang)
		},
	},
}
</script>

<style scoped>
.pq-messages__threads ul,
.pq-messages__list {
	margin: 0 0 16px;
	padding: 0;
	list-style: none;
}

.pq-messages__threads :deep([aria-current='true']) .pq-action-row__title {
	font-weight: bold;
	text-decoration: underline;
}

.pq-message {
	padding-block: 8px;
	border-block-end: 1px solid var(--utrecht-color-grey-80, currentcolor);
}

.pq-message__header {
	display: flex;
	gap: 8px;
}
</style>
