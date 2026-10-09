<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	The unified inbox (portal-inbox-v2): every app's messages for this
	resident, merged and newest first. Each row has an unread mark in text,
	a "mark as read" button, the optional readiness fields (nature, legal
	effect, deadline), the message box delivery line, "Open" for the record a
	message is about and "View task" for a task it asks for. The notice choices
	sit at the top, also when the inbox is empty.
-->
<template>
	<div class="pq-inbox-page">
		<BusyStatus v-if="loading" :t="tr" />
		<template v-else>
			<NotificationSettings :api="api" :t="tr" />
			<p v-if="messages.length === 0" class="utrecht-paragraph pq-empty">
				<em>{{ tr('No messages.') }}</em>
			</p>
			<div v-if="deletableIds.length > 0" class="pq-inbox__bulk">
				<label class="pq-inbox__select-all">
					<input
						type="checkbox"
						:checked="allSelected"
						data-testid="inbox-select-all"
						@change="toggleAll()" />
					{{ tr('Select all') }}
				</label>
				<button
					v-if="selected.length > 0"
					type="button"
					class="utrecht-button utrecht-button--secondary-action"
					data-testid="inbox-delete-selected"
					@click="askDelete(selectedMessages)">
					{{ tr('Delete selected ({count})', { count: selected.length }) }}
				</button>
			</div>
			<div
				v-if="confirming.length > 0"
				ref="confirm"
				class="pq-inbox__confirm"
				role="group"
				aria-labelledby="pq-inbox-confirm-question"
				tabindex="-1"
				data-testid="inbox-delete-confirm">
				<p id="pq-inbox-confirm-question" class="utrecht-paragraph">
					{{ question }}
				</p>
				<div class="pq-inbox__confirm-actions">
					<button
						type="button"
						class="utrecht-button utrecht-button--primary-action"
						:disabled="deleting"
						data-testid="inbox-delete-yes"
						@click="confirmDelete()">
						{{ tr('Yes, delete') }}
					</button>
					<button
						type="button"
						class="utrecht-button utrecht-button--secondary-action"
						:disabled="deleting"
						@click="cancelDelete()">
						{{ tr('Cancel') }}
					</button>
				</div>
			</div>
			<p class="utrecht-paragraph pq-inbox__notice" role="status">
				{{ notice }}
			</p>
			<p v-if="failed" class="utrecht-paragraph pq-inbox__failed" role="alert">
				{{ tr('Not every message could be deleted. Please try again.') }}
			</p>
			<div
				v-if="tabsOn && messages.length > 0"
				class="pq-inbox__tabs"
				role="group"
				:aria-label="tr('Filter messages')">
				<button
					v-for="tab in tabs"
					:key="tab.key"
					type="button"
					class="utrecht-button utrecht-button--subtle pq-inbox__tab"
					:class="{ 'pq-inbox__tab--on': tab.key === activeTab }"
					:aria-pressed="tab.key === activeTab ? 'true' : 'false'"
					:data-testid="`inbox-tab-${tab.key}`"
					@click="activeTab = tab.key">
					{{ tabLabel(tab) }}
				</button>
				<button
					v-if="shownUnread > 0"
					type="button"
					class="utrecht-button utrecht-button--subtle"
					:disabled="markingAll"
					data-testid="inbox-mark-all-read"
					@click="markAllRead()">
					{{ tr('Mark all as read') }}
				</button>
			</div>
			<ul v-if="messages.length > 0" class="pq-inbox">
				<li
					v-for="(message, i) in shownMessages"
					:key="idOf(message, i)"
					class="pq-inbox-row"
					:class="{ 'pq-inbox-row--unread': message.read !== true }">
					<div class="pq-inbox-row__header">
						<label
							v-if="deletable(message)"
							class="pq-inbox-row__select">
							<input
								type="checkbox"
								:checked="selected.includes(idOf(message, i))"
								@change="toggleSelected(idOf(message, i))" />
							{{ tr('Select') }}
							<span class="sr-only">{{ message.subject || '' }}</span>
						</label>
						<strong
							v-if="message.read !== true"
							class="pq-inbox-row__unread">
							{{ tr('Unread') }}
						</strong>
						<span class="pq-inbox-row__subject">{{
							message.subject || ''
						}}</span>
						<span
							v-if="message._source?.label"
							class="pq-inbox-row__source">
							{{ message._source.label }}
						</span>
						<span class="pq-inbox-row__date">
							{{ dateTime(message.receivedAt) }}
						</span>
					</div>
					<p
						v-if="message.senderRole"
						class="utrecht-paragraph pq-inbox-row__role"
						data-testid="inbox-row-role">
						{{ message.senderRole }}
					</p>
					<p
						v-if="message.about"
						class="utrecht-paragraph pq-inbox-row__about"
						data-testid="inbox-row-about">
						<a
							v-if="aboutHref(message)"
							:href="aboutHref(message)"
							@click="onAboutClick($event, message)"
							>{{ tr('About: {value}', { value: message.about }) }}</a
						>
						<template v-else>
							{{ tr('About: {value}', { value: message.about }) }}
						</template>
					</p>

					<TranslatedText
						v-if="shownBody(message)"
						:id="idOf(message, i)"
						:text="shownBody(message)"
						:translation="shownTranslation(message)"
						:partsOf="partsOf"
						:t="tr"
						:locale="lang"
						bodyClass="utrecht-paragraph pq-inbox-row__body"
						@navigate="go" />

					<dl v-if="readiness(message)" class="pq-inbox-row__meta">
						<div v-if="message.nature">
							<dt>{{ tr('Nature') }}</dt>
							<dd>{{ message.nature }}</dd>
						</div>
						<div v-if="message.rechtsgevolg">
							<dt>{{ tr('Legal effect') }}</dt>
							<dd>{{ message.rechtsgevolg }}</dd>
						</div>
						<div v-if="message.term">
							<dt>{{ tr('Deadline') }}</dt>
							<dd>{{ dateTime(message.term) }}</dd>
						</div>
					</dl>

					<div
						v-if="attachments(message).length > 0"
						class="pq-inbox-row__files"
						data-testid="inbox-row-files">
						<p
							:id="`pq-inbox-files-${idOf(message, i)}`"
							class="utrecht-paragraph pq-inbox-row__files-title">
							{{ tr('Attachments') }}
						</p>
						<ul
							class="pq-inbox-row__file-list"
							:aria-labelledby="`pq-inbox-files-${idOf(message, i)}`">
							<li v-for="file in attachments(message)" :key="file.id">
								<button
									type="button"
									class="utrecht-button utrecht-button--subtle"
									:disabled="downloadingId === file.id"
									data-testid="inbox-row-download"
									@click="download(message, file)">
									{{ file.name || file.id }}
								</button>
							</li>
						</ul>
						<p
							v-if="downloadFailedFor === idOf(message, i)"
							class="utrecht-paragraph pq-inbox-row__download-error"
							role="alert">
							{{ tr('The download did not work.') }}
						</p>
					</div>

					<p
						v-if="delivery(message)"
						class="utrecht-paragraph pq-inbox-row__delivery">
						{{ delivery(message) }}
					</p>

					<!-- The sender asked to see when this opens; the resident is told
					     before they open it (inbox-read-receipt-on-request). -->
					<p
						v-if="message.readReceiptRequested === true"
						class="utrecht-paragraph pq-inbox-row__receipt"
						data-testid="inbox-row-receipt-notice">
						{{ tr('The sender sees when you opened this message.') }}
					</p>

					<div class="pq-inbox-row__actions">
						<!-- A real link (a new tab, a bookmark); a plain click keeps
						     the record for the page it opens, as an e-mail link does. -->
						<a
							v-if="openRouteOf(message)"
							class="utrecht-button-link utrecht-button-link--html-a utrecht-button-link--secondary-action"
							:href="hrefOf(openRouteOf(message))"
							data-testid="inbox-row-open"
							@click="onOpenClick($event, message.recordLink)">
							{{ tr('Open') }}
						</a>
						<a
							v-if="actionOf(message, origin)"
							class="utrecht-button-link utrecht-button-link--html-a utrecht-button-link--primary-action"
							:href="actionHref(message)"
							data-testid="inbox-row-action"
							@click="onActionClick($event, message)">
							{{ actionOf(message, origin).label }}
						</a>
						<button
							v-if="message.taskUuid"
							type="button"
							class="utrecht-button utrecht-button--secondary-action"
							@click="openTask(message.taskUuid)">
							{{ tr('View task') }}
						</button>
						<button
							type="button"
							class="utrecht-button utrecht-button--subtle"
							:disabled="
								message.read === true || busyId === idOf(message, i)
							"
							@click="markRead(message)">
							{{
								message.read === true
									? tr('Read')
									: tr('Mark as read')
							}}
						</button>
						<button
							v-if="deletable(message)"
							type="button"
							class="utrecht-button utrecht-button--subtle"
							data-testid="inbox-delete"
							:disabled="deleting"
							@click="askDelete([message])">
							{{ tr('Delete') }}
						</button>
					</div>

					<!-- The app lets the resident answer this message
					     (inbox-reply-with-attachments). -->
					<InboxReply
						v-if="replyOf(message) && api"
						:message="message"
						:reply="replyOf(message)"
						:api="api"
						:locale="lang"
						:idBase="`pq-inbox-reply-${idOf(message, i)}`" />
				</li>
			</ul>
		</template>
	</div>
</template>

<script>
import { defineAsyncComponent } from 'vue'
import BusyStatus from '../../components/inbox/BusyStatus.vue'
import NotificationSettings from '../../components/inbox/NotificationSettings.vue'
import TranslatedText from '../../components/inbox/TranslatedText.vue'
import { unreadIn } from '../../../shared/inboxUnread.js'
import { deliveryLine } from '../../../shared/messageBox.js'
import { siteHref } from '../../components/mijn/rows.js'
import {
	actionOf,
	attachmentsOf,
	bodyParts,
	bodyWithoutOpenLink,
	canDelete,
	downloadCollection,
	formatDateTime,
	hasReadiness,
	inboxTabs,
	keepRecordToOpen,
	keepTaskToOpen,
	markedRead,
	messagesOnTab,
	recordRoute,
	rowId,
	sessionStore,
	TASKS_ROUTE,
	withoutMessages,
} from './inbox.js'
import { PAGE_EMITS, PAGE_PROPS } from './pageProps.js'
import { pageLocale, withStrings } from './translate.js'

// "Open" is a button link; without this stylesheet its classes name
// nothing and the browser draws its own blue link.
import '@utrecht/button-link-css/dist/index.css'

/**
 * The page's origin, or '' where there is no window (a render in node).
 *
 * @return {string} The origin.
 */
function pageOrigin() {
	try {
		return typeof window !== 'undefined' ? window.location.origin : ''
	} catch {
		return ''
	}
}

/**
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-the-inbox-must-merge-every-apps-messages-req-srp-030
 */
export default {
	name: 'InboxPage',

	components: {
		BusyStatus,
		// On demand: only a message that can be answered needs the form.
		InboxReply: defineAsyncComponent(
			() => import('../../components/inbox/InboxReply.vue'),
		),
		NotificationSettings,
		TranslatedText,
	},

	props: PAGE_PROPS,

	emits: PAGE_EMITS,

	data() {
		return {
			loading: true,
			messages: [],
			busyId: null,
			downloadingId: null,
			downloadFailedFor: null,
			selected: [],
			confirming: [],
			deleting: false,
			notice: '',
			failed: false,
			origin: pageOrigin(),
			activeTab: 'all',
			markingAll: false,
		}
	},

	computed: {
		/**
		 * @return {string} `nl` or `en`.
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-the-inbox-must-merge-every-apps-messages-req-srp-030
		 */
		lang() {
			return pageLocale(this.locale)
		},

		/**
		 * @return {(key: string, vars?: object) => string} The translator.
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-the-inbox-must-merge-every-apps-messages-req-srp-030
		 */
		tr() {
			return withStrings(this.t, this.lang)
		},

		/**
		 * The unread count, always from the rows on screen. The page used to
		 * start from the sign-in count (`contributions.unreadCount`) and count
		 * down from it; a page that mounted again after a read, before the
		 * shell reloaded the contributions, counted down from that old number.
		 *
		 * @return {number} How many of the loaded rows are unread.
		 * @spec openspec/changes/woo-inbox-notices/specs/portal-notifications-and-preferences/spec.md#requirement-the-inbox-badge-counts-the-unread-messages-the-inbox-shows-req-nap-011
		 */
		unread() {
			return unreadIn(this.messages)
		},

		/**
		 * @return {boolean} Whether the inbox page declares tabs (`entry.tabs`).
		 * @spec openspec/changes/a-message-names-its-record-and-links-its-action/tasks.md#task-2
		 */
		tabsOn() {
			return Boolean(this.entry?.tabs)
		},

		/**
		 * @return {Array<object>} The tabs: all, unread, one per tab value.
		 * @spec openspec/changes/a-message-names-its-record-and-links-its-action/tasks.md#task-2
		 */
		tabs() {
			return inboxTabs(this.messages)
		},

		/**
		 * @return {Array<object>} The messages on the active tab, or all without tabs.
		 * @spec openspec/changes/a-message-names-its-record-and-links-its-action/tasks.md#task-2
		 */
		shownMessages() {
			return this.tabsOn
				? messagesOnTab(this.messages, this.activeTab)
				: this.messages
		},

		/**
		 * @return {number} How many of the shown messages are unread.
		 * @spec openspec/changes/a-message-names-its-record-and-links-its-action/tasks.md#task-2
		 */
		shownUnread() {
			return unreadIn(this.shownMessages)
		},

		/**
		 * @return {Array<string>} The ids of the rows the resident may delete.
		 * @spec openspec/changes/inbox-delete-own-messages/specs/portal-notifications-and-preferences/spec.md#requirement-a-resident-can-delete-their-own-inbox-messages
		 */
		deletableIds() {
			return this.messages.filter(canDelete).map((m) => rowId(m))
		},

		/**
		 * @return {boolean} Whether every deletable row is chosen.
		 * @spec openspec/changes/inbox-delete-own-messages/specs/portal-notifications-and-preferences/spec.md#requirement-a-resident-can-delete-their-own-inbox-messages
		 */
		allSelected() {
			return (
				this.deletableIds.length > 0
				&& this.deletableIds.every((id) => this.selected.includes(id))
			)
		},

		/**
		 * @return {Array<object>} The chosen messages.
		 * @spec openspec/changes/inbox-delete-own-messages/specs/portal-notifications-and-preferences/spec.md#requirement-a-resident-can-delete-their-own-inbox-messages
		 */
		selectedMessages() {
			return this.messages.filter((m) => this.selected.includes(rowId(m)))
		},

		/**
		 * @return {string} The question before a delete, singular or plural.
		 * @spec openspec/changes/inbox-delete-own-messages/specs/portal-notifications-and-preferences/spec.md#requirement-a-resident-can-delete-their-own-inbox-messages
		 */
		question() {
			return this.confirming.length === 1
				? this.tr('Delete this message? You cannot undo this.')
				: this.tr('Delete {count} messages? You cannot undo this.', {
						count: this.confirming.length,
					})
		},
	},

	created() {
		this.load()
	},

	methods: {
		/**
		 * The reply a message can be answered with, or null.
		 *
		 * @param {object} message The message.
		 * @return {object|null} The reply declaration.
		 * @spec openspec/changes/inbox-reply-with-attachments/tasks.md#t05
		 */
		replyOf(message) {
			const reply = message && message._source && message._source.reply
			return reply && reply.action && typeof reply.action.id === 'string'
				? reply
				: null
		},

		/**
		 * Read the merged inbox.
		 *
		 * Then tell the shell how many of the loaded rows are unread: a notice
		 * a job wrote after sign-in is not in the sign-in count.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-the-inbox-must-merge-every-apps-messages-req-srp-030
		 * @spec openspec/changes/woo-inbox-notices/specs/portal-notifications-and-preferences/spec.md#requirement-the-inbox-badge-counts-the-unread-messages-the-inbox-shows-req-nap-011
		 */
		async load() {
			this.loading = true
			const messages = await this.api.fetchInbox()
			this.messages = Array.isArray(messages) ? messages : []
			this.loading = false
			// A failed read says nothing about the count: the shell keeps its own.
			if (Array.isArray(messages)) {
				this.$emit('unread', this.unread)
			}
		},

		/**
		 * @param {object} message A message.
		 * @param {number} i Its position.
		 * @return {string|number} Its id.
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-the-inbox-must-merge-every-apps-messages-req-srp-030
		 */
		idOf(message, i) {
			return rowId(message, i)
		},

		/**
		 * @param {string} value An ISO date-time.
		 * @return {string} The value in the page language.
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-the-inbox-must-merge-every-apps-messages-req-srp-030
		 */
		dateTime(value) {
			return formatDateTime(value, this.lang)
		},

		/**
		 * The route the row's "Open" button leads to, or null without one.
		 *
		 * @param {object} message A message.
		 * @return {string|null} The route.
		 * @spec openspec/changes/woo-inbox-notices/specs/portal-notifications-and-preferences/spec.md#requirement-the-inbox-does-not-repeat-the-open-link-in-the-text-req-nap-012
		 */
		openRouteOf(message) {
			return message?.recordLink?.id ? this.routeOf(message.recordLink) : null
		},

		/**
		 * The body as the row shows it: without the web address that leads
		 * where "Open" leads, which the e-mail needs and the row does not.
		 *
		 * @param {object} message A message.
		 * @return {string} The body to show.
		 * @spec openspec/changes/woo-inbox-notices/specs/portal-notifications-and-preferences/spec.md#requirement-the-inbox-does-not-repeat-the-open-link-in-the-text-req-nap-012
		 */
		shownBody(message) {
			return bodyWithoutOpenLink(
				message?.body || '',
				message?.recordLink || null,
				this.openRouteOf(message),
			)
		},

		/**
		 * A shown text as text and named links: an address into this site
		 * becomes a link with a name, any other address stays text.
		 *
		 * @param {string} text The shown body or translation.
		 * @return {Array<object>} The parts.
		 * @spec openspec/changes/woo-inbox-notices/specs/portal-notifications-and-preferences/spec.md#requirement-the-inbox-shows-other-site-addresses-as-named-links-req-nap-013
		 */
		partsOf(text) {
			return bodyParts(text, this.origin, {
				publication: this.tr('View the publication'),
				link: this.tr('View the link'),
			})
		},

		/**
		 * The reader's translation, with the same address taken out.
		 *
		 * @param {object} message A message.
		 * @return {object|null} The translation entry, or null.
		 * @spec openspec/changes/woo-inbox-notices/specs/portal-notifications-and-preferences/spec.md#requirement-the-inbox-does-not-repeat-the-open-link-in-the-text-req-nap-012
		 */
		shownTranslation(message) {
			const translation = message?.translation || null
			if (!translation || typeof translation.text !== 'string') {
				return translation
			}
			return {
				...translation,
				text: bodyWithoutOpenLink(
					translation.text,
					message.recordLink || null,
					this.openRouteOf(message),
				),
			}
		},

		/**
		 * @param {object} message A message.
		 * @return {boolean} Whether it carries readiness fields.
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-the-inbox-must-merge-every-apps-messages-req-srp-030
		 */
		readiness(message) {
			return hasReadiness(message)
		},

		/**
		 * @param {object} message A message.
		 * @return {Array<object>} The files that came with it.
		 * @spec openspec/changes/inbox-reply-with-attachments/specs/portal-inbox-reply/spec.md#requirement-files-that-came-with-a-message-open-req-ira-004
		 */
		attachments(message) {
			return attachmentsOf(message)
		},

		/**
		 * Download one file of a message through the scoped download, which
		 * proves the message is the resident's before it serves the file.
		 *
		 * @param {object} message The message.
		 * @param {object} file The file: `id`, `name`.
		 * @return {Promise<void>}
		 * @spec openspec/changes/inbox-reply-with-attachments/specs/portal-inbox-reply/spec.md#requirement-files-that-came-with-a-message-open-req-ira-004
		 */
		async download(message, file) {
			const id = rowId(message)
			const collection = downloadCollection(message)
			if (!id || !collection) {
				return
			}
			this.downloadingId = file.id
			this.downloadFailedFor = null
			const result = await this.api.downloadFile(collection, id, file)
			this.downloadingId = null
			if (!result?.ok) {
				this.downloadFailedFor = id
			}
		},

		/**
		 * @param {object} message A message.
		 * @return {string|null} The message box delivery line.
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-the-inbox-must-merge-every-apps-messages-req-srp-030
		 */
		delivery(message) {
			return deliveryLine(message, this.tr)
		},

		/**
		 * @param {object} link A record link.
		 * @return {string|null} The route of the page that shows it.
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-the-inbox-must-merge-every-apps-messages-req-srp-030
		 */
		routeOf(link) {
			return recordRoute(this.nav, link)
		},

		/**
		 * Mark one message read; the server confirms, then the row and the
		 * menu's unread count follow.
		 *
		 * @param {object} message The message.
		 * @return {Promise<void>}
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-the-inbox-must-merge-every-apps-messages-req-srp-030
		 */
		async markRead(message) {
			const id = rowId(message)
			if (!id || message.read === true) {
				return
			}
			this.busyId = id
			const result = await this.api.markMessageRead(message)
			this.busyId = null
			if (result?.ok) {
				this.messages = markedRead(this.messages, id)
				this.$emit('unread', this.unread)
			}
		},

		/**
		 * @param {object} message A message.
		 * @return {boolean} Whether the resident may delete it.
		 * @spec openspec/changes/inbox-delete-own-messages/specs/portal-notifications-and-preferences/spec.md#requirement-a-resident-can-delete-their-own-inbox-messages
		 */
		deletable(message) {
			return canDelete(message)
		},

		/**
		 * Choose a row, or leave it out again. A row the resident may not
		 * delete is never chosen.
		 *
		 * @param {string} id The row id.
		 * @return {void}
		 * @spec openspec/changes/inbox-delete-own-messages/specs/portal-notifications-and-preferences/spec.md#requirement-a-resident-can-delete-their-own-inbox-messages
		 */
		toggleSelected(id) {
			if (!this.deletableIds.includes(id)) {
				return
			}
			this.selected = this.selected.includes(id)
				? this.selected.filter((other) => other !== id)
				: [...this.selected, id]
		},

		/**
		 * Choose every deletable row, or none when all are chosen already.
		 *
		 * @return {void}
		 * @spec openspec/changes/inbox-delete-own-messages/specs/portal-notifications-and-preferences/spec.md#requirement-a-resident-can-delete-their-own-inbox-messages
		 */
		toggleAll() {
			this.selected = this.allSelected ? [] : [...this.deletableIds]
		},

		/**
		 * Ask on the page before deleting: nothing is deleted until the
		 * resident answers "Yes, delete".
		 *
		 * @param {Array<object>} messages The messages to delete.
		 * @return {void}
		 * @spec openspec/changes/inbox-delete-own-messages/specs/portal-notifications-and-preferences/spec.md#requirement-a-resident-can-delete-their-own-inbox-messages
		 */
		askDelete(messages) {
			this.confirming = (messages || []).filter(canDelete).map((m) => rowId(m))
			this.notice = ''
			this.failed = false
			// Outside a mounted page (a render or a test) there is nothing to focus.
			if (this.confirming.length > 0 && this.$refs) {
				this.$nextTick(() => this.$refs?.confirm?.focus?.())
			}
		},

		/**
		 * Leave the question without deleting anything.
		 *
		 * @return {void}
		 * @spec openspec/changes/inbox-delete-own-messages/specs/portal-notifications-and-preferences/spec.md#requirement-a-resident-can-delete-their-own-inbox-messages
		 */
		cancelDelete() {
			this.confirming = []
		},

		/**
		 * Delete the messages the question named, one call each; the server
		 * checks each is the resident's own. A message it refuses stays, and
		 * stays chosen.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/inbox-delete-own-messages/specs/portal-notifications-and-preferences/spec.md#requirement-a-resident-can-delete-their-own-inbox-messages
		 */
		async confirmDelete() {
			const ids = [...this.confirming]
			const deleted = []
			this.deleting = true
			for (const message of this.messages.filter((m) =>
				ids.includes(rowId(m)),
			)) {
				const result = await this.api.deleteMessage(message)
				if (result?.ok) {
					deleted.push(rowId(message))
				}
			}
			this.deleting = false
			this.confirming = []
			this.messages = withoutMessages(this.messages, deleted)
			this.selected = this.selected.filter((id) => !deleted.includes(id))
			this.failed = deleted.length < ids.length
			this.notice =
				deleted.length === 1
					? this.tr('The message is deleted.')
					: deleted.length > 1
						? this.tr('{count} messages are deleted.', {
								count: deleted.length,
							})
						: ''
			if (deleted.length > 0) {
				this.$emit('unread', this.unread)
			}
		},

		/**
		 * Open the record a message is about, as a link from the e-mail does.
		 *
		 * @param {{app: string, collection: string, id: string}} link The record link.
		 * @return {void}
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-the-inbox-must-merge-every-apps-messages-req-srp-030
		 */
		openRecord(link) {
			const route = recordRoute(this.nav, link)
			if (!route) {
				return
			}
			keepRecordToOpen(sessionStore(), link)
			this.go(route)
		},

		/**
		 * The link's real address for an in-site route.
		 *
		 * @param {string} route The in-site route.
		 * @return {string} The address.
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-the-inbox-must-merge-every-apps-messages-req-srp-030
		 */
		hrefOf(route) {
			return siteHref(route)
		},

		/**
		 * A plain click on "Open" stays in the site; a click for a new tab or
		 * window (a modifier key, another button) is the browser's.
		 *
		 * @param {MouseEvent} event The click.
		 * @param {{app: string, collection: string, id: string}} link The record link.
		 * @return {void}
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-the-inbox-must-merge-every-apps-messages-req-srp-030
		 */
		onOpenClick(event, link) {
			if (
				event?.ctrlKey
				|| event?.metaKey
				|| event?.shiftKey
				|| (event?.button ?? 0) !== 0
			) {
				return
			}
			event?.preventDefault?.()
			this.openRecord(link)
		},

		/**
		 * Open "My tasks" on the task a message asks for.
		 *
		 * @param {string} uuid The task uuid.
		 * @return {void}
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-resident-must-be-able-to-complete-their-tasks-req-srp-035
		 */
		openTask(uuid) {
			keepTaskToOpen(sessionStore(), uuid)
			this.go(TASKS_ROUTE)
		},

		/**
		 * @param {{key: string, kind: string, value?: string}} tab A tab.
		 * @return {string} Its label: "All", "Unread (2)" or the tab value.
		 * @spec openspec/changes/a-message-names-its-record-and-links-its-action/tasks.md#task-2
		 */
		tabLabel(tab) {
			if (tab.kind === 'all') {
				return this.tr('All')
			}
			if (tab.kind === 'unread') {
				return this.tr('Unread ({count})', { count: this.unread })
			}
			return tab.value
		},

		/**
		 * Mark the shown unread messages read, each through its own
		 * collection's read endpoint. A message the server refuses stays unread.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/a-message-names-its-record-and-links-its-action/tasks.md#task-2
		 */
		async markAllRead() {
			this.markingAll = true
			for (const message of this.shownMessages.filter(
				(m) => m.read !== true,
			)) {
				const id = rowId(message)
				if (!id) {
					continue
				}
				const result = await this.api.markMessageRead(message)
				if (result?.ok) {
					this.messages = markedRead(this.messages, id)
				}
			}
			this.markingAll = false
			this.$emit('unread', this.unread)
		},

		/**
		 * @param {object} message A message.
		 * @return {{label: string, href: string}|null} Its action, only when it stays in the portal.
		 * @spec openspec/changes/a-message-names-its-record-and-links-its-action/tasks.md#task-2
		 */
		actionOf(message) {
			return actionOf(message, this.origin)
		},

		/**
		 * @param {object} message A message.
		 * @return {string} The address its action button opens.
		 * @spec openspec/changes/a-message-names-its-record-and-links-its-action/tasks.md#task-2
		 */
		actionHref(message) {
			return this.portalHref(actionOf(message, this.origin)?.href)
		},

		/**
		 * @param {object} message A message.
		 * @return {string|null} The record page its "About" line links to, if any.
		 * @spec openspec/changes/a-message-names-its-record-and-links-its-action/tasks.md#task-2
		 */
		aboutHref(message) {
			const link = actionOf(
				{ action: { label: 'x', href: message?.aboutLink } },
				this.origin,
			)
			return link ? this.portalHref(link.href) : null
		},

		/**
		 * @param {string} href A same-portal address from `actionOf`.
		 * @return {string} The address as the browser should follow it.
		 * @spec openspec/changes/a-message-names-its-record-and-links-its-action/tasks.md#task-2
		 */
		portalHref(href) {
			return typeof href === 'string'
				&& href.startsWith('/')
				&& !href.startsWith('//')
				&& !href.startsWith(this.origin + '/')
				? siteHref(href)
				: href
		},

		/**
		 * A plain click on the action stays in the site.
		 *
		 * @param {MouseEvent} event The click.
		 * @param {object} message The message.
		 * @return {void}
		 * @spec openspec/changes/a-message-names-its-record-and-links-its-action/tasks.md#task-2
		 */
		onActionClick(event, message) {
			const action = actionOf(message, this.origin)
			if (action && action.href.startsWith('/')) {
				this.followSameSite(event, action.href)
			}
		},

		/**
		 * @param {MouseEvent} event The click.
		 * @param {object} message The message.
		 * @return {void}
		 * @spec openspec/changes/a-message-names-its-record-and-links-its-action/tasks.md#task-2
		 */
		onAboutClick(event, message) {
			const href = this.aboutHref(message)
			if (href && message.aboutLink.startsWith('/')) {
				this.followSameSite(event, message.aboutLink)
			}
		},

		/**
		 * @param {MouseEvent} event The click.
		 * @param {string} route The in-site route.
		 * @return {void}
		 * @spec openspec/changes/a-message-names-its-record-and-links-its-action/tasks.md#task-2
		 */
		followSameSite(event, route) {
			if (
				event?.ctrlKey
				|| event?.metaKey
				|| event?.shiftKey
				|| (event?.button ?? 0) !== 0
			) {
				return
			}
			event?.preventDefault?.()
			this.go(route)
		},

		/**
		 * Go elsewhere in the signed-in area.
		 *
		 * @param {string} route The in-site route.
		 * @return {void}
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-the-inbox-must-merge-every-apps-messages-req-srp-030
		 */
		go(route) {
			if (typeof this.navigate === 'function') {
				this.navigate(route)
				return
			}
			this.$emit('navigate', route)
		},
	},
}
</script>

<style scoped>
.pq-inbox__tabs {
	display: flex;
	flex-wrap: wrap;
	gap: 0.5rem;
	margin-block-end: 1rem;
}

.pq-inbox__tab--on {
	font-weight: 700;
	text-decoration: underline;
}

.pq-inbox-row__role,
.pq-inbox-row__about {
	margin-block: 0.25rem;
}

.pq-inbox {
	margin: 0;
	padding: 0;
	list-style: none;
}

.pq-inbox-row {
	padding-block: 12px;
	border-block-end: 1px solid var(--utrecht-color-grey-80, currentcolor);
}

.pq-inbox-row__header {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	align-items: baseline;
}

.pq-inbox-row--unread .pq-inbox-row__subject {
	font-weight: bold;
}

.pq-inbox-row__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

.pq-inbox-row__meta div {
	display: flex;
	gap: 8px;
}

.pq-inbox-row__meta dd {
	margin: 0;
}

.pq-inbox-row__files-title {
	margin-block-end: 4px;
	font-weight: bold;
}

.pq-inbox__bulk,
.pq-inbox__confirm-actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	align-items: center;
}

.pq-inbox__confirm {
	margin-block: 12px;
	padding: 12px;
	border: 2px solid var(--utrecht-color-grey-80, currentcolor);
	border-radius: 4px;
}

.pq-inbox__notice:empty {
	display: none;
}

.pq-inbox-row__select {
	display: inline-flex;
	gap: 4px;
	align-items: center;
}

.pq-inbox-row__file-list {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin: 0;
	padding: 0;
	list-style: none;
}
</style>
