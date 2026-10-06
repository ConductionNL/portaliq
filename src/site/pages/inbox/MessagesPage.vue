<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	A resident's conversations with the organisation (guardian-direct-messages),
	in the language the resident picks (translated-message-notice), grouped per
	record with tabs ("Alle berichten", "Over Vera"), each conversation a card
	that opens with its messages and a reply form, and a form to write to a
	contact the app names for one of the resident's records
	(site-messages-per-record).
-->
<template>
	<section class="pq-messages">
		<div v-if="hasCompose" class="pq-messages__bar">
			<button
				type="button"
				class="utrecht-button utrecht-button--primary-action"
				data-testid="messages-new"
				@click="focusCompose">
				{{ ct('New message') }}
			</button>
		</div>
		<Skeleton
			v-if="threads === null && !failed"
			:label="mt('Loading')"
			:rows="2" />
		<!-- A read that failed says so and offers to try again; it never
		     reads as "no conversations" (site-mijn-omgeving-components
		     REQ-SMO-009). -->
		<LoadError
			v-else-if="failed"
			:text="mt('Your conversations could not be loaded.')"
			:retryLabel="mt('Try again')"
			@retry="load" />
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

			<div
				v-if="tabs.length > 0"
				class="pq-messages__tabs"
				role="group"
				:aria-label="ct('Conversations')"
				data-testid="messages-tabs">
				<button
					v-for="tab in tabs"
					:key="tab.key || 'all'"
					type="button"
					class="pq-messages__tab"
					:aria-pressed="tab.key === tabKey ? 'true' : 'false'"
					data-testid="messages-tab"
					@click="tabKey = tab.key">
					{{ tab.label }}
				</button>
			</div>

			<EmptyState
				v-if="shown.length === 0"
				:text="
					tabName
						? ct('No messages about {name} yet.', { name: tabName })
						: tr('No conversations yet.')
				" />

			<ul v-else class="pq-messages__cards" :aria-label="ct('Conversations')">
				<li
					v-for="thread in shown"
					:key="idOf(thread)"
					class="pq-thread"
					:class="{ 'pq-thread--new': cardOf(thread).isNew }"
					data-testid="messages-thread">
					<span class="pq-thread__avatar" aria-hidden="true">{{
						cardOf(thread).initials
					}}</span>
					<div class="pq-thread__body">
						<p class="pq-thread__meta">
							<DataBadge
								v-if="cardOf(thread).isNew"
								:text="ct('New')"
								state="warning" />
							<strong>{{ cardOf(thread).who }}</strong>
							<span>{{ dateTime(cardOf(thread).when) }}</span>
							<span v-if="cardOf(thread).about">{{
								cardOf(thread).about
							}}</span>
						</p>
						<h3 class="utrecht-heading-4 pq-thread__title">
							{{ cardOf(thread).title }}
						</h3>
						<p v-if="cardOf(thread).preview" class="pq-thread__preview">
							{{ cardOf(thread).preview }}
						</p>
						<button
							type="button"
							class="utrecht-button utrecht-button--secondary-action"
							:aria-expanded="
								idOf(thread) === activeId ? 'true' : 'false'
							"
							data-testid="messages-open"
							@click="choose(idOf(thread))">
							{{ ct('Read the whole conversation') }}
						</button>

						<div
							v-if="idOf(thread) === activeId"
							class="pq-thread__open">
							<Skeleton
								v-if="messages === null"
								:label="mt('Loading')"
								:rows="2" />
							<ol v-else class="pq-messages__list">
								<li
									v-for="(message, i) in messages"
									:key="idOf(message, i)"
									class="pq-message"
									:class="{ 'pq-message--own': isOwn(message) }">
									<div class="pq-message__header">
										<strong class="pq-message__sender">
											{{
												isOwn(message)
													? ct('You')
													: cardOf(thread).who
											}}
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
							<form
								class="pq-thread__reply"
								data-testid="messages-reply"
								@submit.prevent="sendReply">
								<label
									class="utrecht-form-label"
									:for="`pq-reply-${idOf(thread)}`">
									{{ ct('Your reply') }}
								</label>
								<textarea
									:id="`pq-reply-${idOf(thread)}`"
									v-model="reply"
									class="utrecht-textarea"
									rows="3" />
								<p
									v-if="replyNotice"
									class="pq-messages__notice"
									role="status">
									{{ replyNotice }}
								</p>
								<button
									type="submit"
									class="utrecht-button utrecht-button--primary-action"
									:disabled="sending">
									{{ ct('Send reply') }}
								</button>
							</form>
						</div>
					</div>
				</li>
			</ul>
		</template>

		<form
			v-if="hasCompose"
			ref="compose"
			class="pq-messages__compose"
			:aria-labelledby="composeHeadingId"
			data-testid="messages-compose"
			@submit.prevent="sendNew">
			<h2 :id="composeHeadingId" class="utrecht-heading-3">
				{{ composeLabel || ct('A message to school') }}
			</h2>
			<label class="utrecht-form-label" for="pq-compose-to">
				{{ ct('To') }}
			</label>
			<select
				id="pq-compose-to"
				ref="composeTo"
				v-model="draft.to"
				class="utrecht-select"
				data-testid="messages-compose-to">
				<option value="" disabled>
					{{ ct('Choose who you write to') }}
				</option>
				<option
					v-for="option in options"
					:key="option.value"
					:value="option.value">
					{{ option.label }}
				</option>
			</select>
			<label class="utrecht-form-label" for="pq-compose-subject">
				{{ ct('Subject (optional)') }}
			</label>
			<input
				id="pq-compose-subject"
				v-model="draft.title"
				class="utrecht-textbox"
				maxlength="120"
				type="text" />
			<label class="utrecht-form-label" for="pq-compose-body">
				{{ ct('Your message') }}
			</label>
			<p v-if="composeHint" id="pq-compose-hint" class="pq-messages__hint">
				{{ composeHint }}
			</p>
			<textarea
				id="pq-compose-body"
				v-model="draft.body"
				class="utrecht-textarea"
				rows="4"
				maxlength="5000"
				:aria-describedby="composeHint ? 'pq-compose-hint' : undefined"
				data-testid="messages-compose-body" />
			<p
				v-if="composeNotice"
				class="pq-messages__notice"
				role="status"
				data-testid="messages-compose-notice">
				{{ composeNotice }}
			</p>
			<button
				type="submit"
				class="utrecht-button utrecht-button--primary-action"
				:disabled="sending">
				{{ ct('Send message') }}
			</button>
		</form>
	</section>
</template>

<script>
import MessageLanguagePicker from '../../components/inbox/MessageLanguagePicker.vue'
import TranslatedText from '../../components/inbox/TranslatedText.vue'
import DataBadge from '../../components/mijn/DataBadge.vue'
import EmptyState from '../../components/mijn/EmptyState.vue'
import LoadError from '../../components/mijn/LoadError.vue'
import Skeleton from '../../components/mijn/Skeleton.vue'
import { mijnTranslator } from '../../components/mijn/rows.js'
import {
	contactOptions,
	conversationTranslator,
	fetchContacts,
	markRead,
	recordTabs,
	replyTo,
	startConversation,
	threadCard,
	threadsInTab,
} from './conversations.js'
import { formatDateTime, rowId } from './inbox.js'
import { PAGE_EMITS, PAGE_PROPS } from './pageProps.js'
import { pageLocale, withStrings } from './translate.js'

/**
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-guardian-must-read-messages-in-their-chosen-language-req-srp-032
 */
export default {
	name: 'MessagesPage',

	components: {
		DataBadge,
		EmptyState,
		LoadError,
		MessageLanguagePicker,
		Skeleton,
		TranslatedText,
	},

	props: PAGE_PROPS,

	emits: PAGE_EMITS,

	data() {
		return {
			threads: null,
			failed: false,
			activeId: null,
			messages: null,
			language: '',
			error: '',
			// site-messages-per-record
			contacts: [],
			composeLabel: '',
			composeHint: '',
			tabKey: '',
			draft: { to: '', title: '', body: '' },
			composeNotice: '',
			reply: '',
			replyNotice: '',
			sending: false,
			composeHeadingId: `pq-compose-${Math.random().toString(36).slice(2, 8)}`,
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

		/**
		 * @return {(key: string, vars?: object) => string} The translator of the per-record words.
		 * @spec openspec/changes/site-messages-per-record/specs/portal-contribution-contract/spec.md#requirement-the-messages-page-groups-conversations-per-record-and-lets-a-resident-write-and-reply
		 */
		ct() {
			return conversationTranslator(this.tr, this.lang)
		},

		/**
		 * @return {Array<object>} "All messages" and one tab per record.
		 * @spec openspec/changes/site-messages-per-record/specs/portal-contribution-contract/spec.md#requirement-the-messages-page-groups-conversations-per-record-and-lets-a-resident-write-and-reply
		 */
		tabs() {
			return recordTabs(this.threads || [], this.contacts, this.ct)
		},

		/**
		 * @return {string} The short name of the open tab's record, '' for all.
		 * @spec openspec/changes/site-messages-per-record/specs/portal-contribution-contract/spec.md#requirement-the-messages-page-groups-conversations-per-record-and-lets-a-resident-write-and-reply
		 */
		tabName() {
			return this.tabs.find((tab) => tab.key === this.tabKey)?.name || ''
		},

		/**
		 * @return {Array<object>} The conversations of the open tab, newest first.
		 * @spec openspec/changes/site-messages-per-record/specs/portal-contribution-contract/spec.md#requirement-the-messages-page-groups-conversations-per-record-and-lets-a-resident-write-and-reply
		 */
		shown() {
			return threadsInTab(this.threads || [], this.tabKey)
		},

		/**
		 * @return {Array<object>} The choices of the "to" field, for the open tab's record.
		 * @spec openspec/changes/site-messages-per-record/specs/portal-contribution-contract/spec.md#requirement-the-messages-page-groups-conversations-per-record-and-lets-a-resident-write-and-reply
		 */
		options() {
			return contactOptions(this.contacts, this.ct, this.tabKey)
		},

		/**
		 * @return {boolean} Whether the resident has anyone to write to.
		 * @spec openspec/changes/site-messages-per-record/specs/portal-contribution-contract/spec.md#requirement-the-messages-page-groups-conversations-per-record-and-lets-a-resident-write-and-reply
		 */
		hasCompose() {
			return this.contacts.length > 0
		},
	},

	watch: {
		/**
		 * One choice for the open tab's record is chosen for you.
		 *
		 * @spec openspec/changes/site-messages-per-record/specs/portal-contribution-contract/spec.md#requirement-the-messages-page-groups-conversations-per-record-and-lets-a-resident-write-and-reply
		 */
		options() {
			if (!this.options.some((option) => option.value === this.draft.to)) {
				this.draft.to =
					this.options.length === 1 ? this.options[0].value : ''
			}
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
			this.failed = false
			this.threads = null
			let list
			let details = null
			try {
				;[list, details] = await Promise.all([
					this.api.fetchThreads({ orNull: true }),
					this.api.getDetails(),
				])
			} catch {
				list = null
			}
			if (!Array.isArray(list)) {
				this.failed = true
				return
			}
			this.threads = list
			this.language = details?.messageLanguage || ''
			// Who the resident may write to; a failed read shows no form.
			const found = await fetchContacts(this.api).catch(() => null)
			this.contacts = found?.contacts || []
			this.composeLabel = found?.composeLabel || ''
			this.composeHint = found?.composeHint || ''
		},

		/**
		 * Read the threads again after writing, keeping the open one open.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/site-messages-per-record/specs/portal-contribution-contract/spec.md#requirement-the-messages-page-groups-conversations-per-record-and-lets-a-resident-write-and-reply
		 */
		async reload() {
			const list = await this.api.fetchThreads({ orNull: true })
			if (Array.isArray(list)) {
				this.threads = list
			}
		},

		/**
		 * Start a conversation with the chosen contact about the chosen record.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/site-messages-per-record/specs/portal-contribution-contract/spec.md#requirement-the-messages-page-groups-conversations-per-record-and-lets-a-resident-write-and-reply
		 */
		async sendNew() {
			this.composeNotice = ''
			const option = this.options.find((o) => o.value === this.draft.to)
			if (!option) {
				this.composeNotice = this.ct('Choose who you write to')
				return
			}
			if (this.draft.body.trim() === '') {
				this.composeNotice = this.ct('Write a message first.')
				return
			}
			this.sending = true
			const id = await startConversation(this.api, {
				staffRef: option.staffRef,
				recordRef: option.recordRef,
				title: this.draft.title.trim(),
				body: this.draft.body.trim(),
			})
			this.sending = false
			if (!id) {
				this.composeNotice = this.ct(
					'Your message could not be sent. Try again.',
				)
				return
			}
			this.draft = {
				to: this.options.length === 1 ? option.value : '',
				title: '',
				body: '',
			}
			this.composeNotice = this.ct('Your message has been sent.')
			await this.reload()
			await this.choose(id)
		},

		/**
		 * Send a reply in the open conversation.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/site-messages-per-record/specs/portal-contribution-contract/spec.md#requirement-the-messages-page-groups-conversations-per-record-and-lets-a-resident-write-and-reply
		 */
		async sendReply() {
			this.replyNotice = ''
			if (!this.activeId || this.reply.trim() === '') {
				this.replyNotice = this.ct('Write a message first.')
				return
			}
			this.sending = true
			const sent = await replyTo(this.api, this.activeId, this.reply.trim())
			this.sending = false
			if (!sent) {
				this.replyNotice = this.ct(
					'Your message could not be sent. Try again.',
				)
				return
			}
			this.reply = ''
			this.replyNotice = this.ct('Your message has been sent.')
			await this.choose(this.activeId, { keepNotice: true })
			await this.reload()
		},

		/**
		 * Move to the form and its first field.
		 *
		 * @spec openspec/changes/site-messages-per-record/specs/portal-contribution-contract/spec.md#requirement-the-messages-page-groups-conversations-per-record-and-lets-a-resident-write-and-reply
		 */
		focusCompose() {
			this.$refs.compose?.scrollIntoView?.({ block: 'start' })
			this.$refs.composeTo?.focus?.()
		},

		/**
		 * @param {object} thread A thread.
		 * @return {object} What its card shows.
		 * @spec openspec/changes/site-messages-per-record/specs/portal-contribution-contract/spec.md#requirement-the-messages-page-groups-conversations-per-record-and-lets-a-resident-write-and-reply
		 */
		cardOf(thread) {
			return threadCard(thread, this.ct)
		},

		/**
		 * Open one thread and read its messages.
		 *
		 * @param {string} threadId The thread.
		 * @param {object} [options] The options.
		 * @param {boolean} [options.keepNotice] Keep the reply's notice on screen.
		 * @return {Promise<void>}
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-guardian-must-read-messages-in-their-chosen-language-req-srp-032
		 */
		async choose(threadId, { keepNotice = false } = {}) {
			if (!keepNotice) {
				this.replyNotice = ''
			}
			this.activeId = threadId || null
			if (!threadId) {
				return
			}
			this.messages = null
			this.messages = (await this.api.fetchThreadMessages(threadId)) || []
			// Opened is read (site-messages-per-record); the "Nieuw" goes on the next read.
			await markRead(this.api, threadId).catch(() => {})
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
.pq-messages__cards,
.pq-messages__list {
	margin: 0 0 16px;
	padding: 0;
	list-style: none;
}

.pq-messages__bar {
	display: flex;
	justify-content: flex-end;
	margin-block-end: 1rem;
}

.pq-messages__tabs {
	display: flex;
	flex-wrap: wrap;
	gap: 0.25rem 1.5rem;
	margin-block: 1rem;
	border-block-end: 1px solid
		var(--nldesign-color-border, var(--utrecht-color-grey-80, currentcolor));
}

.pq-messages__tab {
	padding: 0.5rem 0.25rem;
	border: 0;
	border-block-end: 3px solid transparent;
	background: none;
	color: inherit;
	font: inherit;
	cursor: pointer;
}

.pq-messages__tab[aria-pressed='true'] {
	border-block-end-color: var(
		--nldesign-color-accent,
		var(--utrecht-document-color, CanvasText)
	);
	font-weight: 700;
}

.pq-messages__tab:focus-visible {
	outline: var(--utrecht-focus-outline-width, 2px)
		var(--utrecht-focus-outline-style, solid)
		var(--utrecht-focus-outline-color, currentcolor);
}

.pq-thread {
	display: flex;
	gap: 1rem;
	margin-block-end: 0.75rem;
	padding: 1.25rem;
	border: 1px solid
		var(--nldesign-color-border-dark, var(--utrecht-color-grey-80, currentcolor));
	border-radius: var(
		--nldesign-website-border-radius-large,
		var(--utrecht-border-radius-md, 0.75rem)
	);
	background: var(--utrecht-document-background-color, Canvas);
}

.pq-thread--new {
	border-color: var(
		--nldesign-color-accent,
		var(--nldesign-color-border, currentcolor)
	);
	background: var(
		--nldesign-color-accent-light,
		var(--nldesign-color-primary-light, transparent)
	);
}

.pq-thread__avatar {
	display: inline-flex;
	flex: none;
	align-items: center;
	justify-content: center;
	inline-size: 2.75rem;
	block-size: 2.75rem;
	border-radius: 50%;
	background: var(--nldesign-color-primary-light, var(--utrecht-color-grey-90));
	font-size: 0.875rem;
	font-weight: 700;
}

.pq-thread__body {
	flex: 1;
	min-inline-size: 0;
}

.pq-thread__meta {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 0.25rem 0.75rem;
	margin: 0;
	color: var(
		--nldesign-color-text-muted,
		var(--utrecht-document-color, CanvasText)
	);
}

.pq-thread__meta strong {
	color: var(--utrecht-document-color, CanvasText);
}

.pq-thread__title {
	margin: 0.25rem 0;
}

.pq-thread__preview {
	margin: 0 0 0.75rem;
}

.pq-thread__open,
.pq-thread__reply,
.pq-messages__compose {
	display: grid;
	gap: 0.5rem;
	margin-block-start: 1rem;
}

.pq-messages__compose {
	margin-block-start: 2rem;
	padding: 1.5rem;
	border-radius: var(
		--nldesign-website-border-radius-large,
		var(--utrecht-border-radius-md, 0.75rem)
	);
	background: var(
		--nldesign-color-background-subtle,
		var(--utrecht-color-grey-90)
	);
}

.pq-thread__reply button,
.pq-messages__compose button {
	justify-self: start;
}

.pq-messages__hint {
	margin: 0;
	font-size: 0.875rem;
}

.pq-messages__notice {
	margin: 0;
	font-weight: 700;
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
