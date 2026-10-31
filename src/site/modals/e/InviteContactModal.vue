<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	The dialog that invites someone to be a contact (own-contacts-and-invitations,
	board ContactUitnodigen). A modal dialog: an e-mail address, an optional
	message, and what happens after sending. It sends nothing until the
	resident presses "Send invitation"; it shows the refusal in words and
	keeps what was typed. Escape and "Cancel" emit `cancel`.
-->
<template>
	<dialog
		ref="dialog"
		class="pq-invite-contact"
		aria-labelledby="pq-invite-title"
		data-testid="invite-contact"
		@cancel.prevent="$emit('cancel')">
		<form method="dialog" novalidate @submit.prevent="submit">
			<h2
				id="pq-invite-title"
				ref="heading"
				class="utrecht-heading-3"
				tabindex="-1">
				{{ t('Invite someone') }}
			</h2>
			<div class="utrecht-form-field">
				<label for="pq-invite-email" class="utrecht-form-label">
					{{ t('E-mail address') }}
				</label>
				<input
					id="pq-invite-email"
					v-model="email"
					class="utrecht-textbox"
					type="email"
					autocomplete="off"
					data-testid="invite-contact-email" />
			</div>
			<div class="utrecht-form-field">
				<label for="pq-invite-message" class="utrecht-form-label">
					{{ t('Message (optional)') }}
				</label>
				<textarea
					id="pq-invite-message"
					v-model="message"
					class="utrecht-textarea"
					maxlength="500"
					data-testid="invite-contact-message" />
			</div>
			<h3 class="utrecht-heading-4">
				{{ t('What happens after you send it?') }}
			</h3>
			<ul class="utrecht-unordered-list">
				<li class="utrecht-unordered-list__item">
					{{ t('The person receives your invitation.') }}
				</li>
				<li class="utrecht-unordered-list__item">
					{{
						t(
							'If they have no account yet, they can create one with the link. It works for 14 days.',
						)
					}}
				</li>
				<li class="utrecht-unordered-list__item">
					{{ t('You become contacts once they accept.') }}
				</li>
			</ul>
			<p
				v-if="problem !== ''"
				class="utrecht-paragraph pq-invite-contact__error"
				role="alert"
				data-testid="invite-contact-problem">
				{{ t(problem) }}
			</p>
			<div class="pq-invite-contact__buttons">
				<button
					type="submit"
					class="utrecht-button utrecht-button--primary-action"
					data-testid="invite-contact-submit"
					:disabled="busy">
					{{ t('Send invitation') }}
				</button>
				<button
					type="button"
					class="utrecht-button utrecht-button--secondary-action"
					data-testid="invite-contact-cancel"
					@click="$emit('cancel')">
					{{ t('Cancel') }}
				</button>
			</div>
		</form>
	</dialog>
</template>

<script>
import { contactsApi } from '../../../shared/areaApi.js'
import { inviteProblem } from '../../pages/e/contacts.js'

export default {
	name: 'InviteContactModal',

	props: {
		/** The portal API adapter (`createPortalApi` shape). */
		api: { type: Object, required: true },
		/** The translator `t(key, vars)`. */
		t: { type: Function, required: true },
	},

	emits: ['sent', 'cancel'],

	data() {
		return { email: '', message: '', problem: '', busy: false }
	},

	/**
	 * Open as a modal and put focus on the heading.
	 *
	 * @return {void}
	 *
	 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t06
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
	 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t06
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
		 * Send the invitation. A refusal is shown and the dialog stays open.
		 *
		 * @return {Promise<void>} Resolves when answered.
		 *
		 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t06
		 */
		async submit() {
			this.problem = ''
			this.busy = true
			const answer = await contactsApi(this.api).contactAction('invite', {
				email: this.email.trim(),
				message: this.message.trim(),
			})
			this.busy = false
			if (!answer.ok) {
				this.problem = inviteProblem(answer)
				return
			}
			this.$emit('sent')
		},
	},
}
</script>

<style scoped>
.pq-invite-contact {
	max-inline-size: min(36rem, calc(100vw - 2rem));
	padding: var(--utrecht-space-block-lg, 1.5rem);
	color: var(--utrecht-document-color, inherit);
	background: var(--utrecht-document-background-color, Canvas);
	border: var(--utrecht-border-width-sm, 1px) solid
		var(--utrecht-color-grey-80, currentcolor);
}

.pq-invite-contact form > * + * {
	margin-block-start: var(--utrecht-space-block-md, 1rem);
}

.pq-invite-contact input,
.pq-invite-contact textarea {
	inline-size: 100%;
}

.pq-invite-contact__error {
	color: var(--utrecht-feedback-danger-color, currentcolor);
	font-weight: var(--utrecht-typography-weight-scale-bold-font-weight, bold);
}

.pq-invite-contact__buttons {
	display: flex;
	flex-wrap: wrap;
	gap: var(--utrecht-space-inline-sm, 0.5rem);
}
</style>
