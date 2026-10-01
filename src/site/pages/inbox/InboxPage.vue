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
						v-if="message.body"
						:id="idOf(message, i)"
						:text="message.body"
						:translation="message.translation || null"
						:t="tr"
						:locale="lang"
						bodyClass="utrecht-paragraph pq-inbox-row__body" />

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
import { deliveryLine } from '../../../shared/messageBox.js'
import {
	formatDateTime,
	hasReadiness,
	keepRecordToOpen,
	keepTaskToOpen,
	markedRead,
	recordRoute,
	rowId,
	sessionStore,
	TASKS_ROUTE,
	unreadAfterRead,
} from './inbox.js'
import { PAGE_EMITS, PAGE_PROPS } from './pageProps.js'
import { pageLocale, withStrings } from './translate.js'

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
			unread: this.contributions?.unreadCount ?? null,
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
	},

	created() {
		this.load()
	},

	methods: {
		/**
		 * Read the merged inbox.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-the-inbox-must-merge-every-apps-messages-req-srp-030
		 */
		async load() {
			this.loading = true
			const messages = await this.api.fetchInbox()
			this.messages = Array.isArray(messages) ? messages : []
			this.loading = false
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
		 * @param {object} message A message.
		 * @return {boolean} Whether it carries readiness fields.
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-the-inbox-must-merge-every-apps-messages-req-srp-030
		 */
		readiness(message) {
			return hasReadiness(message)
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
				this.unread = unreadAfterRead(this.unread)
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
			this.go('contribution', { open: link }, route)
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
			this.go('tasks', { task: uuid }, TASKS_ROUTE)
		},

		/**
		 * Go elsewhere in the signed-in area.
		 *
		 * @param {string} key The page key.
		 * @param {object} params What the page needs.
		 * @param {string} route The in-site route.
		 * @return {void}
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-the-inbox-must-merge-every-apps-messages-req-srp-030
		 */
		go(key, params, route) {
			if (typeof this.navigate === 'function') {
				this.navigate(key, params)
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
</style>
