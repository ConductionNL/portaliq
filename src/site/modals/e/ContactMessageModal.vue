<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	The dialog behind "Send message" on a contact (own-contacts-and-invitations
	T07). The resident writes the first message to one approved contact; the
	server proves the contact again and starts the conversation, which then
	lives under Messages. It sends nothing until "Send message" is pressed, and
	keeps what was typed when the server refuses. Escape and "Cancel" emit
	`cancel`.
-->
<template>
	<dialog
		ref="dialog"
		class="pq-contact-message"
		aria-labelledby="pq-contact-message-title"
		data-testid="contact-message"
		@cancel.prevent="$emit('cancel')">
		<form method="dialog" novalidate @submit.prevent="submit">
			<h2
				id="pq-contact-message-title"
				ref="heading"
				class="utrecht-heading-3"
				tabindex="-1">
				{{ t('Message to {name}', { name: contact.displayName }) }}
			</h2>
			<div class="utrecht-form-field">
				<label for="pq-contact-message-body" class="utrecht-form-label">
					{{ t('Your message') }}
				</label>
				<textarea
					id="pq-contact-message-body"
					v-model="body"
					class="utrecht-textarea"
					maxlength="5000"
					aria-describedby="pq-contact-message-hint"
					data-testid="contact-message-body" />
				<p id="pq-contact-message-hint" class="utrecht-paragraph">
					{{ t('You find the conversation under Messages.') }}
				</p>
			</div>
			<p
				v-if="problem !== ''"
				class="utrecht-paragraph pq-contact-message__error"
				role="alert"
				data-testid="contact-message-problem">
				{{ t(problem) }}
			</p>
			<div class="pq-contact-message__buttons">
				<button
					type="submit"
					class="utrecht-button utrecht-button--primary-action"
					data-testid="contact-message-submit"
					:disabled="busy">
					{{ t('Send message') }}
				</button>
				<button
					type="button"
					class="utrecht-button utrecht-button--secondary-action"
					data-testid="contact-message-cancel"
					@click="$emit('cancel')">
					{{ t('Cancel') }}
				</button>
			</div>
		</form>
	</dialog>
</template>

<script>
import { contactsApi } from '../../../shared/areaApi.js'

export default {
	name: 'ContactMessageModal',

	props: {
		/** The portal API adapter (`createPortalApi` shape). */
		api: { type: Object, required: true },
		/** The translator `t(key, vars)`. */
		t: { type: Function, required: true },
		/** The approved contact: its own row `id` and `displayName`. */
		contact: { type: Object, required: true },
	},

	emits: ['sent', 'cancel'],

	data() {
		return { body: '', problem: '', busy: false }
	},

	/**
	 * Open as a modal and put focus on the heading.
	 *
	 * @return {void}
	 *
	 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t07
	 */
	mounted() {
		const dialog = this.$refs.dialog
		if (typeof dialog?.showModal === 'function') {
			dialog.showModal()
		} else {
			dialog?.setAttribute('open', '')
		}
		this.$refs.heading?.focus()
	},

	/**
	 * Close the dialog with the component.
	 *
	 * @return {void}
	 *
	 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t07
	 */
	beforeUnmount() {
		if (
			this.$refs.dialog?.open
			&& typeof this.$refs.dialog.close === 'function'
		) {
			this.$refs.dialog.close()
		}
	},

	methods: {
		/**
		 * Send the first message. A refusal is shown and the dialog stays open.
		 *
		 * @return {Promise<void>} Resolves when answered.
		 *
		 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t07
		 */
		async submit() {
			this.problem = ''
			if (this.body.trim() === '') {
				this.problem = 'Write a message first.'
				return
			}
			this.busy = true
			const id = await contactsApi(this.api).sendMessage(
				this.contact.id,
				this.body.trim(),
			)
			this.busy = false
			if (id === null) {
				this.problem = 'Your message could not be sent. Try again.'
				return
			}
			this.$emit('sent', id)
		},
	},
}
</script>

<style scoped>
.pq-contact-message {
	max-inline-size: min(36rem, calc(100vw - 2rem));
	padding: var(--utrecht-space-block-lg, 1.5rem);
	color: var(--utrecht-document-color, inherit);
	background: var(--utrecht-document-background-color, Canvas);
	border: var(--utrecht-border-width-sm, 1px) solid
		var(--utrecht-color-grey-80, currentcolor);
}

.pq-contact-message form > * + * {
	margin-block-start: var(--utrecht-space-block-md, 1rem);
}

.pq-contact-message textarea {
	inline-size: 100%;
}

.pq-contact-message__error {
	color: var(--utrecht-feedback-danger-color, currentcolor);
	font-weight: var(--utrecht-typography-weight-scale-bold-font-weight, bold);
}

.pq-contact-message__buttons {
	display: flex;
	flex-wrap: wrap;
	gap: var(--utrecht-space-inline-sm, 0.5rem);
}
</style>
