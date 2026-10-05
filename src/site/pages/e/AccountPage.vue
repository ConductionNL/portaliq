<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	"My account" on the site (identity-profile-page T07, T10), ported from
	the React portal's AccountPage.jsx. The signed-in person's own name,
	e-mail addresses and phone numbers with the preferred one of each kind
	marked, how the organisation contacts them, and removing the account. After
	a removal the page emits `removed`: the shell listens and signs out. The
	code from an invitation letter is typed here too; when it is right the page
	emits `claimed` and the shell reads the account again, without taking this
	page off the screen.
-->
<template>
	<p
		v-if="details === null"
		class="utrecht-paragraph"
		role="status"
		data-testid="account-loading">
		{{ t('Loading…') }}
	</p>
	<p v-else-if="details.failed" class="utrecht-paragraph" role="alert">
		{{ t('Your account cannot be shown right now.') }}
	</p>
	<section
		v-else
		class="pq-account"
		aria-labelledby="pq-account-title"
		data-testid="account-page">
		<!-- The page title: the shell leaves its own h1 out for this page
		     (OWNS_HEADING in pages/registry.js). -->
		<h1 id="pq-account-title" class="utrecht-heading-2">
			{{ t('My account') }}
		</h1>
		<p v-if="notice" class="utrecht-paragraph pq-e-notice" role="status">
			{{ notice }}
		</p>
		<p v-if="error" class="utrecht-paragraph pq-e-error" role="alert">
			{{ error }}
		</p>

		<form class="pq-account__name" @submit.prevent="saveName">
			<label for="pq-account-name" class="utrecht-form-label">{{
				t('Name')
			}}</label>
			<input
				id="pq-account-name"
				v-model="name"
				class="utrecht-textbox"
				type="text"
				autocomplete="name"
				required />
			<button
				type="submit"
				class="utrecht-button utrecht-button--secondary-action">
				{{ t('Save name') }}
			</button>
		</form>

		<AddressList kind="email" :entries="emails" :t="t" :act="act" />
		<AddressList kind="phone" :entries="phones" :t="t" :act="act" />

		<fieldset class="utrecht-form-fieldset pq-account__channel">
			<legend class="utrecht-form-fieldset__legend">
				{{ t('How should we contact you?') }}
			</legend>
			<label
				v-for="option in channels"
				:key="option.value"
				class="utrecht-form-label pq-account__radio">
				<input
					type="radio"
					class="utrecht-radio-button"
					name="pq-contact-channel"
					:value="option.value"
					:checked="channel === option.value"
					@change="chooseChannel(option.value)" />
				{{ t(option.label) }}
			</label>
		</fieldset>

		<InvitationCodeForm :api="api" :t="t" @claimed="$emit('claimed')" />

		<section class="pq-account__remove" aria-labelledby="pq-account-remove">
			<h3 id="pq-account-remove" class="utrecht-heading-3">
				{{ t('Remove my account') }}
			</h3>
			<button
				v-if="!confirmRemove"
				type="button"
				class="utrecht-button utrecht-button--secondary-action"
				data-testid="account-remove"
				@click="confirmRemove = true">
				{{ t('Remove my account') }}
			</button>
			<div v-else role="group" aria-labelledby="pq-account-remove">
				<p class="utrecht-paragraph">
					{{
						t(
							'Your portal account is removed. Your cases stay with the organisation.',
						)
					}}
				</p>
				<div class="pq-e-buttons">
					<button
						type="button"
						class="utrecht-button utrecht-button--primary-action"
						data-testid="account-remove-confirm"
						@click="removeAccount">
						{{ t('Yes, remove my account') }}
					</button>
					<button
						type="button"
						class="utrecht-button utrecht-button--secondary-action"
						@click="confirmRemove = false">
						{{ t('Cancel') }}
					</button>
				</div>
			</div>
		</section>
	</section>
</template>

<script>
import AddressList from '../../components/e/AddressList.vue'
import InvitationCodeForm from '../../components/e/InvitationCodeForm.vue'
import { refusalText } from '../../../shared/account.js'

/** The contact channels, as English source keys. */
const CHANNELS = [
	{ value: 'portal', label: 'Only through the portal' },
	{ value: 'email', label: 'By e-mail' },
	{ value: 'phone', label: 'By phone' },
	{ value: 'post', label: 'By post' },
]

export default {
	name: 'AccountPage',

	components: { AddressList, InvitationCodeForm },

	props: {
		/** The session as `/portal/api/session` returns it. */
		session: { type: Object, default: null },
		/** The portal record. */
		portal: { type: Object, default: null },
		/** The portal API adapter (`createPortalApi` shape). */
		api: { type: Object, required: true },
		/** The translator `t(key, vars)`. */
		t: { type: Function, required: true },
		/** The shell's `navigate(key, params)`. */
		navigate: { type: Function, default: () => {} },
		/** An answer to show without fetching (test seam). */
		initialDetails: { type: Object, default: null },
		/** Open on the removal step (test seam). */
		initialConfirmRemove: { type: Boolean, default: false },
	},

	emits: ['removed', 'claimed'],

	data() {
		return {
			details: this.initialDetails,
			name: this.initialDetails?.displayName || '',
			notice: '',
			error: '',
			confirmRemove: this.initialConfirmRemove,
			channels: CHANNELS,
		}
	},

	computed: {
		entries() {
			return Array.isArray(this.details?.contactAddresses)
				? this.details.contactAddresses
				: []
		},

		emails() {
			return this.entries.filter((e) => e.kind === 'email')
		},

		phones() {
			return this.entries.filter((e) => e.kind === 'phone')
		},

		channel() {
			return this.details?.contactChannel || 'portal'
		},
	},

	mounted() {
		if (this.initialDetails === null) {
			this.reload()
		}
	},

	methods: {
		/**
		 * Read the account again.
		 *
		 * @return {Promise<void>} Resolves when read.
		 *
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-resident-must-manage-their-own-account-req-srp-037
		 */
		async reload() {
			const answer = await this.api.getDetails()
			this.details = answer || { failed: true }
			this.name = answer?.displayName || ''
		},

		/**
		 * Run one change, show its outcome, and read the account again.
		 *
		 * @param {Promise<object>} pending The api call.
		 * @param {string} done The notice on success.
		 * @return {Promise<boolean>} Whether it worked.
		 *
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-resident-must-manage-their-own-account-req-srp-037
		 */
		async run(pending, done) {
			this.notice = ''
			this.error = ''
			const answer = await pending
			if (!answer || !answer.ok) {
				this.error = this.t(refusalText(answer?.error))
				return false
			}
			this.notice = done
			await this.reload()
			return true
		},

		/**
		 * An address action from a list.
		 *
		 * @param {string} action `add`, `prefer` or `remove`.
		 * @param {string} kind `email` or `phone`.
		 * @param {string} value The address.
		 * @return {Promise<boolean>} Whether it worked.
		 *
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-resident-must-manage-their-own-account-req-srp-037
		 */
		act(action, kind, value) {
			if (action === 'prefer') {
				return this.run(
					this.api.preferContactAddress(kind, value),
					this.t('Your preferred address is changed.'),
				)
			}
			if (action === 'remove') {
				return this.run(
					this.api.removeContactAddress(kind, value),
					this.t('The address is removed.'),
				)
			}
			const sent =
				kind === 'email'
					? this.t(
							'We sent a link to {address}. Follow it to confirm the address.',
							{ address: value },
						)
					: this.t('The phone number is added.')
			return this.run(this.api.addContactAddress(kind, value), sent)
		},

		/**
		 * Save the display name.
		 *
		 * @return {Promise<boolean>} Whether it worked.
		 *
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-resident-must-manage-their-own-account-req-srp-037
		 */
		saveName() {
			return this.run(
				this.api.setDisplayName(this.name),
				this.t('Your name is saved.'),
			)
		},

		/**
		 * Save how the organisation contacts the person.
		 *
		 * @param {string} value The channel.
		 * @return {Promise<boolean>} Whether it worked.
		 *
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-resident-must-manage-their-own-account-req-srp-037
		 */
		chooseChannel(value) {
			return this.run(
				this.api.setContactChannel(value),
				this.t('Your choice is saved.'),
			)
		},

		/**
		 * Remove the account, then hand over to the shell to sign out.
		 *
		 * @return {Promise<void>} Resolves when answered.
		 *
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-resident-must-manage-their-own-account-req-srp-037
		 */
		async removeAccount() {
			const answer = await this.api.removeOwnAccount()
			if (!answer || !answer.ok) {
				this.error = this.t(refusalText(answer?.error))
				return
			}
			this.$emit('removed')
		},
	},
}
</script>

<style scoped>
.pq-account > * + * {
	margin-block-start: var(--utrecht-space-block-md, 1rem);
}

.pq-account__name {
	display: flex;
	flex-wrap: wrap;
	align-items: end;
	gap: var(--utrecht-space-inline-sm, 0.5rem);
}

.pq-account__radio {
	display: block;
}

.pq-e-buttons {
	display: flex;
	flex-wrap: wrap;
	gap: var(--utrecht-space-inline-sm, 0.5rem);
}

.pq-e-error {
	color: var(
		--utrecht-feedback-danger-color,
		var(--nldesign-color-error, currentcolor)
	);
	font-weight: var(--utrecht-typography-weight-scale-bold-font-weight, bold);
}

.pq-e-notice {
	font-weight: var(--utrecht-typography-weight-scale-bold-font-weight, bold);
}
</style>
