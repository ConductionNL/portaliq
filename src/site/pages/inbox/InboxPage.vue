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
			<ul v-else class="pq-inbox">
				<li
					v-for="(message, i) in messages"
					:key="idOf(message, i)"
					class="pq-inbox-row"
					:class="{ 'pq-inbox-row--unread': message.read !== true }">
					<div class="pq-inbox-row__header">
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

					<div class="pq-inbox-row__actions">
						<button
							v-if="
								message.recordLink?.id && routeOf(message.recordLink)
							"
							type="button"
							class="utrecht-button utrecht-button--secondary-action"
							@click="openRecord(message.recordLink)">
							{{ tr('Open') }}
						</button>
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
					</div>
				</li>
			</ul>
		</template>
	</div>
</template>

<script>
import BusyStatus from '../../components/inbox/BusyStatus.vue'
import NotificationSettings from '../../components/inbox/NotificationSettings.vue'
import TranslatedText from '../../components/inbox/TranslatedText.vue'
import { unreadIn } from '../../../shared/inboxUnread.js'
import { deliveryLine } from '../../../shared/messageBox.js'
import {
	attachmentsOf,
	bodyParts,
	bodyWithoutOpenLink,
	downloadCollection,
	formatDateTime,
	hasReadiness,
	keepRecordToOpen,
	keepTaskToOpen,
	markedRead,
	recordRoute,
	rowId,
	sessionStore,
	TASKS_ROUTE,
} from './inbox.js'
import { PAGE_EMITS, PAGE_PROPS } from './pageProps.js'
import { pageLocale, withStrings } from './translate.js'

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

	components: { BusyStatus, NotificationSettings, TranslatedText },

	props: PAGE_PROPS,

	emits: PAGE_EMITS,

	data() {
		return {
			loading: true,
			messages: [],
			busyId: null,
			downloadingId: null,
			downloadFailedFor: null,
			origin: pageOrigin(),
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
	},

	created() {
		this.load()
	},

	methods: {
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

.pq-inbox-row__file-list {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin: 0;
	padding: 0;
	list-style: none;
}
</style>
