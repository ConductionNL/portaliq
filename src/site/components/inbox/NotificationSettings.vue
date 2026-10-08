<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	The resident's notice choices at the top of the inbox
	(inbox-notifications-and-preferences, REQ-NAP-008): per kind, e-mail and
	push, each a checkbox with its own label. Collapsed by default. The push
	column shows only when the account registered a device and the server can
	really deliver a push (`pushAvailable`). When the
	organisation offers the government message box, one more choice lets the
	resident switch letters to it off (inbox-berichtenbox-channel, REQ-MBC-005).
-->
<template>
	<details v-if="loaded" class="pq-notification-settings">
		<summary>{{ t('Notification settings') }}</summary>
		<form @submit.prevent="save">
			<table class="utrecht-table pq-notification-settings__table">
				<thead class="utrecht-table__header">
					<tr class="utrecht-table__row">
						<th scope="col" class="utrecht-table__header-cell">
							<span class="sr-only">{{
								t('Notification settings')
							}}</span>
						</th>
						<th
							v-for="channel in channels"
							:key="channel"
							scope="col"
							class="utrecht-table__header-cell">
							{{ channelLabel(channel) }}
						</th>
					</tr>
				</thead>
				<tbody class="utrecht-table__body">
					<tr
						v-for="kind in kinds"
						:key="kind.key"
						class="utrecht-table__row">
						<th scope="row" class="utrecht-table__header-cell">
							{{ t(kind.label) }}
						</th>
						<td
							v-for="channel in channels"
							:key="channel"
							class="utrecht-table__cell">
							<input
								:id="checkboxId(kind.key, channel)"
								type="checkbox"
								class="utrecht-checkbox"
								:checked="isOn(kind.key, channel)"
								@change="
									set(kind.key, channel, $event.target.checked)
								" />
							<label
								:for="checkboxId(kind.key, channel)"
								class="sr-only">
								{{ `${t(kind.label)}: ${channelLabel(channel)}` }}
							</label>
						</td>
					</tr>
				</tbody>
			</table>
			<p
				v-if="messageBox"
				class="utrecht-paragraph pq-notification-settings__message-box">
				<input
					id="portaliq-notify-message-box"
					type="checkbox"
					class="utrecht-checkbox"
					:checked="choices.messageBox?.enabled !== false"
					@change="setMessageBox($event.target.checked)" />
				<label
					for="portaliq-notify-message-box"
					class="utrecht-form-label utrecht-form-label--checkbox">
					{{
						t('Also send letters to {label}', {
							label: messageBox.label,
						})
					}}
				</label>
			</p>
			<button
				type="submit"
				class="utrecht-button utrecht-button--secondary-action"
				:disabled="busy">
				{{ t('Save') }}
			</button>
			<p v-if="status" class="utrecht-paragraph" role="status">
				{{ status }}
			</p>
		</form>
	</details>
</template>

<script>
import {
	messageBoxChoice,
	withMessageBoxChoice,
} from '../../../shared/messageBox.js'

/** The kinds a resident can choose for, with their English source labels. */
export const KINDS = [
	{ key: 'case.updated', label: 'Changes on your cases' },
	{ key: 'message.created', label: 'New messages' },
]

/**
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-notification-choices-must-be-settable-per-kind-req-srp-031
 */
export default {
	name: 'NotificationSettings',

	props: {
		/** The shared portal API. */
		api: { type: Object, required: true },
		/** The translator. */
		t: { type: Function, required: true },
		/** The settings answer, when the caller already has it (tests). */
		initial: { type: Object, default: null },
	},

	data() {
		return {
			loaded: this.initial,
			choices: { ...(this.initial?.preferences || {}) },
			status: '',
			busy: false,
		}
	},

	computed: {
		/**
		 * @return {Array<object>} The kinds.
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-notification-choices-must-be-settable-per-kind-req-srp-031
		 */
		kinds() {
			return KINDS
		},

		/**
		 * @return {Array<string>} The channels: push only when the server says it is available (a device and a delivering transport).
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-notification-choices-must-be-settable-per-kind-req-srp-031
		 */
		channels() {
			return this.loaded?.pushAvailable ? ['email', 'push'] : ['email']
		},

		/**
		 * @return {{label: string, enabled: boolean}|null} The message box choice, when offered.
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-notification-choices-must-be-settable-per-kind-req-srp-031
		 */
		messageBox() {
			return messageBoxChoice(this.loaded)
		},
	},

	async mounted() {
		if (this.loaded) {
			return
		}
		const answer = await this.api.fetchNotificationPreferences()
		if (answer) {
			this.loaded = answer
			this.choices = { ...(answer.preferences || {}) }
		}
	},

	methods: {
		/**
		 * @param {string} channel The channel.
		 * @return {string} Its label.
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-notification-choices-must-be-settable-per-kind-req-srp-031
		 */
		channelLabel(channel) {
			return channel === 'email' ? this.t('E-mail') : this.t('Push')
		},

		/**
		 * @param {string} kind The kind.
		 * @param {string} channel The channel.
		 * @return {string} The checkbox id.
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-notification-choices-must-be-settable-per-kind-req-srp-031
		 */
		checkboxId(kind, channel) {
			return `portaliq-notify-${kind}-${channel}`.replace(/\./g, '-')
		},

		/**
		 * @param {string} kind The kind.
		 * @param {string} channel The channel.
		 * @return {boolean} Whether it is on; a missing choice means on.
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-notification-choices-must-be-settable-per-kind-req-srp-031
		 */
		isOn(kind, channel) {
			return this.choices[kind]?.[channel] !== false
		},

		/**
		 * @param {string} kind The kind.
		 * @param {string} channel The channel.
		 * @param {boolean} value On or off.
		 * @return {void}
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-notification-choices-must-be-settable-per-kind-req-srp-031
		 */
		set(kind, channel, value) {
			this.status = ''
			this.choices = {
				...this.choices,
				[kind]: { ...(this.choices[kind] || {}), [channel]: value },
			}
		},

		/**
		 * @param {boolean} enabled On or off.
		 * @return {void}
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-notification-choices-must-be-settable-per-kind-req-srp-031
		 */
		setMessageBox(enabled) {
			this.status = ''
			this.choices = withMessageBoxChoice(this.choices, enabled)
		},

		/**
		 * Save the choices and say how it went.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-notification-choices-must-be-settable-per-kind-req-srp-031
		 */
		async save() {
			this.busy = true
			const saved = await this.api.saveNotificationPreferences(this.choices)
			this.busy = false
			if (saved) {
				this.choices = { ...(saved.preferences || {}) }
				this.status = this.t('Your choices are saved.')
			} else {
				this.status = this.t('Your choices could not be saved. Try again.')
			}
		},
	},
}
</script>

<style scoped>
.pq-notification-settings {
	margin-block-end: 16px;
}

.pq-notification-settings summary {
	cursor: pointer;
	font-weight: bold;
}
</style>
