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
			<div class="pq-invite-contact__top">
				<div>
					<p class="pq-invite-contact__overline">
						{{ t('My contacts') }}
					</p>
					<h2
						id="pq-invite-title"
						ref="heading"
						class="utrecht-heading-3"
						tabindex="-1">
						{{ t('Invite someone') }}
					</h2>
				</div>
				<button
					type="button"
					class="pq-invite-contact__close"
					:aria-label="t('Close')"
					data-testid="invite-contact-close"
					@click="$emit('cancel')">
					<span aria-hidden="true">×</span>
				</button>
			</div>
			<p class="utrecht-paragraph">
				{{
					t(
						'Invite someone to become your contact, for example a family member or someone who helps you. We send an e-mail with a personal link.',
					)
				}}
			</p>
			<div class="utrecht-form-field">
				<label for="pq-invite-email" class="utrecht-form-label">
					{{ t('E-mail address') }}
				</label>
				<p id="pq-invite-email-help" class="pq-invite-contact__help">
					{{ t('Of the person you want to invite.') }}
				</p>
				<input
					id="pq-invite-email"
					v-model="email"
					class="utrecht-textbox"
					type="email"
					autocomplete="off"
					aria-describedby="pq-invite-email-help"
					data-testid="invite-contact-email" />
			</div>
			<div class="utrecht-form-field">
				<label for="pq-invite-message" class="utrecht-form-label">
					{{ t('Message (optional)') }}
				</label>
				<p id="pq-invite-message-help" class="pq-invite-contact__help">
					{{ t('This message is in the e-mail, below your name.') }}
				</p>
				<textarea
					id="pq-invite-message"
					v-model="message"
					class="utrecht-textarea"
					maxlength="500"
					aria-describedby="pq-invite-message-help"
					data-testid="invite-contact-message" />
			</div>
			<div class="pq-invite-contact__steps">
				<h3 class="utrecht-heading-4">
					{{ t('What happens after you send it?') }}
				</h3>
				<ol class="utrecht-ordered-list">
					<li class="utrecht-ordered-list__item">
						{{ t('The person gets an e-mail with a link. The link works for 14 days.') }}
					</li>
					<li class="utrecht-ordered-list__item">
						{{ t('With the link they create an account. The e-mail address is already filled in.') }}
					</li>
					<li class="utrecht-ordered-list__item">
						{{ t('Once the account is made you are contacts of each other. You do not have to approve anything else.') }}
					</li>
				</ol>
			</div>
			<p class="pq-invite-contact__help">
				{{
					t(
						'Does this person already have an account? Then they get no invitation, but a request to approve you.',
					)
				}}
			</p>
			<p
				v-if="problem !== ''"
				class="utrecht-paragraph pq-invite-contact__error"
				role="alert"
				data-testid="invite-contact-problem">
				{{ t(problem) }}
			</p>
			<div class="pq-invite-contact__buttons">
				<button
					type="button"
					class="utrecht-button utrecht-button--secondary-action"
					data-testid="invite-contact-cancel"
					@click="$emit('cancel')">
					{{ t('Cancel') }}
				</button>
				<button
					type="submit"
					class="utrecht-button utrecht-button--primary-action"
					data-testid="invite-contact-submit"
					:disabled="busy">
					{{ t('Send invitation') }}
				</button>
			</div>
		</form>
	</dialog>
</template>

<script>
import { contactsApi } from '../../../shared/areaApi.js'
import { inviteProblem } from '../../pages/e/contacts.js'
import { closeDialog, openDialog } from '../forms/draftDialog.js'

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
	 * @spec openspec/changes/archive/2026-11-25-own-contacts-and-invitations/tasks.md#t06
	 */
	mounted() {
		openDialog(this.$refs.dialog, this.$refs.heading)
	},

	/**
	 * Close the dialog with the component.
	 *
	 * @return {void}
	 *
	 * @spec openspec/changes/archive/2026-11-25-own-contacts-and-invitations/tasks.md#t06
	 */
	beforeUnmount() {
		closeDialog(this.$refs.dialog)
	},

	methods: {
		/**
		 * Send the invitation. A refusal is shown and the dialog stays open.
		 *
		 * @return {Promise<void>} Resolves when answered.
		 *
		 * @spec openspec/changes/archive/2026-11-25-own-contacts-and-invitations/tasks.md#t06
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
/*
 * The dialog as board ContactUitnodigen draws it (own-contacts-and-invitations
 * T09): centred, an overline and a close button over the title, a help line
 * under each label, the three steps numbered in a panel, and the buttons in
 * a footer with Cancel before Send.
 */
.pq-invite-contact {
	inset: 0;
	margin: auto;
	inline-size: min(32rem, calc(100vw - 2rem));
	max-block-size: calc(100vh - 2rem);
	padding: var(--utrecht-space-block-lg, 1.5rem);
	color: var(--utrecht-document-color, inherit);
	background: var(--utrecht-document-background-color, Canvas);
	border: 0;
	border-radius: var(--utrecht-border-radius-md, 0.5rem);
	box-shadow: 0 0.5rem 2rem color-mix(in srgb, currentcolor 25%, transparent);
}

.pq-invite-contact::backdrop {
	background: color-mix(in srgb, CanvasText 40%, transparent);
}

.pq-invite-contact form > * + * {
	margin-block-start: var(--utrecht-space-block-md, 1rem);
}

.pq-invite-contact__top {
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	gap: 1rem;
}

.pq-invite-contact__top h2 {
	margin: 0;
}

.pq-invite-contact__overline {
	margin: 0;
	font-size: 0.75em;
}

.pq-invite-contact__close {
	min-inline-size: 2.75rem;
	min-block-size: 2.75rem;
	border: 0;
	background: none;
	color: inherit;
	font-size: 1.5em;
	line-height: 1;
	cursor: pointer;
}

.pq-invite-contact__help {
	margin: 0.125rem 0 0.375rem;
	color: var(--nldesign-color-text-muted, var(--utrecht-color-grey-30, GrayText));
	font-size: 0.875em;
}

.pq-invite-contact input,
.pq-invite-contact textarea {
	inline-size: 100%;
}

.pq-invite-contact__steps {
	padding: 0.75rem 1rem;
	border: 1px solid var(--nldesign-color-border-light, var(--utrecht-color-grey-80, color-mix(in srgb, currentcolor 18%, transparent)));
	border-radius: var(--utrecht-border-radius-md, 0.375rem);
	background: var(--nldesign-color-primary-light, var(--utrecht-color-grey-90, Canvas));
}

.pq-invite-contact__steps h3 {
	margin: 0 0 0.5rem;
	font-size: 1em;
}

.pq-invite-contact__steps ol {
	margin: 0;
}

.pq-invite-contact__error {
	color: var(--utrecht-feedback-danger-color, currentcolor);
	font-weight: var(--utrecht-typography-weight-scale-bold-font-weight, bold);
}

.pq-invite-contact__buttons {
	display: flex;
	flex-wrap: wrap;
	justify-content: flex-end;
	gap: var(--utrecht-space-inline-sm, 0.5rem);
	padding-block-start: var(--utrecht-space-block-md, 1rem);
	border-block-start: 1px solid var(--nldesign-color-border-light, var(--utrecht-color-grey-80, color-mix(in srgb, currentcolor 18%, transparent)));
}
</style>
