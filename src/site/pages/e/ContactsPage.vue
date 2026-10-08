<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	"My contacts" (own-contacts-and-invitations, board Contacten): what waits
	for an answer, the approved contacts with a role filter, and the dialog to
	invite someone. A resident reads and changes only their own rows.
-->
<template>
	<section
		class="pq-contacts"
		aria-labelledby="pq-contacts-title"
		data-testid="contacts-page">
		<div class="pq-contacts__head">
			<h1 id="pq-contacts-title" class="utrecht-heading-2">
				{{ t('My contacts') }}
			</h1>
			<button
				type="button"
				class="utrecht-button utrecht-button--primary-action"
				data-testid="contacts-invite"
				@click="inviting = true">
				{{ t('Invite someone') }}
			</button>
		</div>
		<p class="utrecht-paragraph">
			{{
				t(
					'People and organisations you work with. With a contact you can send messages and make a plan together.',
				)
			}}
		</p>
		<p v-if="notice !== ''" class="utrecht-paragraph" role="status">
			{{ t(notice) }}
		</p>
		<p
			v-if="problem !== ''"
			class="utrecht-paragraph pq-contacts__error"
			role="alert">
			{{ t(problem) }}
		</p>
		<p v-if="loading" class="utrecht-paragraph" role="status">
			{{ t('Loading…') }}
		</p>

		<template v-else>
			<section
				v-if="waiting.length > 0"
				aria-labelledby="pq-contacts-waiting"
				data-testid="contacts-waiting">
				<h2 id="pq-contacts-waiting" class="utrecht-heading-3">
					{{ t('Waiting for approval') }}
				</h2>
				<ul class="utrecht-unordered-list pq-contacts__list">
					<li
						v-for="row in waiting"
						:key="row.id"
						class="utrecht-unordered-list__item"
						data-testid="contacts-waiting-row"
						:data-state="row.state">
						<strong>{{ row.displayName || row.email }}</strong>
						<span v-if="row.state === 'declined'">{{ t('Not accepted') }}</span>
						<span v-else-if="sent(row) !== ''">{{
							t('Sent on {date}', { date: sent(row) })
						}}</span>
						<span class="pq-contacts__buttons">
							<template v-if="row.state === 'requested'">
								<button
									type="button"
									class="utrecht-button utrecht-button--primary-action"
									data-testid="contact-accept"
									@click="act('respond', row, { accept: true })">
									{{ t('Accept') }}
								</button>
								<button
									type="button"
									class="utrecht-button utrecht-button--secondary-action"
									data-testid="contact-decline"
									@click="act('respond', row, { accept: false })">
									{{ t('Decline') }}
								</button>
							</template>
							<template v-else-if="row.state === 'invited'">
								<button
									type="button"
									class="utrecht-button utrecht-button--secondary-action"
									data-testid="contact-resend"
									@click="act('resend', row)">
									{{ t('Send again') }}
								</button>
								<button
									type="button"
									class="utrecht-button utrecht-button--secondary-action"
									data-testid="contact-withdraw"
									@click="act('withdraw', row)">
									{{ t('Withdraw') }}
								</button>
							</template>
						</span>
					</li>
				</ul>
			</section>

			<section aria-labelledby="pq-contacts-yours" data-testid="contacts-list">
				<h2 id="pq-contacts-yours" class="utrecht-heading-3">
					{{ t('Your contacts') }}
				</h2>
				<div class="pq-contacts__chips" role="group" :aria-label="t('Your contacts')">
					<button
						v-for="chip in filters"
						:key="chip"
						type="button"
						class="utrecht-button"
						:class="chip === role ? 'utrecht-button--primary-action' : 'utrecht-button--secondary-action'"
						:aria-pressed="chip === role ? 'true' : 'false'"
						:data-testid="`contacts-chip-${chip}`"
						@click="role = chip">
						{{ t(label(chip)) }} ({{ count(chip) }})
					</button>
				</div>
				<p
					v-if="shown.length === 0"
					class="utrecht-paragraph"
					data-testid="contacts-empty">
					{{ t('You have no contacts yet.') }}
				</p>
				<ul v-else class="utrecht-unordered-list pq-contacts__list">
					<li
						v-for="row in shown"
						:key="row.id"
						class="utrecht-unordered-list__item"
						data-testid="contact-row">
						<span class="pq-contacts__initials" aria-hidden="true">{{
							initials(row.displayName)
						}}</span>
						<strong>{{ row.displayName }}</strong>
						<span>{{ t(label(row.role)) }}</span>
						<span v-if="row.line !== ''">{{ row.line }}</span>
						<button
							type="button"
							class="utrecht-button utrecht-button--secondary-action"
							data-testid="contact-remove"
							@click="act('remove', row)">
							{{ t('Remove') }}
						</button>
					</li>
				</ul>
			</section>

			<section aria-labelledby="pq-contacts-sees">
				<h2 id="pq-contacts-sees" class="utrecht-heading-3">
					{{ t('What does a contact see of you?') }}
				</h2>
				<p class="utrecht-paragraph">
					{{
						t(
							'A contact sees your name and the messages you send them. A contact never sees your cases.',
						)
					}}
				</p>
			</section>
		</template>

		<InviteContactModal
			v-if="inviting"
			:api="api"
			:t="t"
			@cancel="inviting = false"
			@sent="invited" />
	</section>
</template>

<script>
import InviteContactModal from '../../modals/e/InviteContactModal.vue'
import { contactsApi } from '../../../shared/areaApi.js'
import { contactsOfRole, initialsOf, ROLE_FILTERS, roleLabel } from './contacts.js'
import { longDate, readerLocale } from './format.js'

export default {
	name: 'ContactsPage',

	components: { InviteContactModal },

	props: {
		/** The portal API adapter (`createPortalApi` shape). */
		api: { type: Object, required: true },
		/** The translator `t(key, vars)`. */
		t: { type: Function, required: true },
		/** The reader's locale; the page's `<html lang>` when empty. */
		locale: { type: String, default: '' },
	},

	data() {
		return {
			overview: { incoming: [], outgoing: [], contacts: [], counts: {} },
			loading: true,
			inviting: false,
			role: 'all',
			notice: '',
			problem: '',
			filters: ROLE_FILTERS,
		}
	},

	computed: {
		/**
		 * @return {object} The contact calls over the portal api.
		 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t06
		 */
		client() {
			return contactsApi(this.api)
		},

		/**
		 * @return {Array<object>} Requests to answer, then invitations sent.
		 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t06
		 */
		waiting() {
			return [...this.overview.incoming, ...this.overview.outgoing]
		},

		/**
		 * @return {Array<object>} The contacts of the chosen role chip.
		 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t06
		 */
		shown() {
			return contactsOfRole(this.overview.contacts, this.role)
		},
	},

	/**
	 * Read the resident's own contacts when the page opens.
	 *
	 * @return {Promise<void>} Resolves when read.
	 *
	 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t06
	 */
	async mounted() {
		await this.load()
	},

	methods: {
		label: roleLabel,
		initials: initialsOf,

		/**
		 * The number on a role chip.
		 *
		 * @param {string} chip The chip.
		 * @return {number} How many approved contacts it holds.
		 *
		 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t06
		 */
		count(chip) {
			return Number(this.overview.counts?.[chip] || 0)
		},

		/**
		 * The date a row was sent, in the reader's language, or ''.
		 *
		 * @param {object} row The row.
		 * @return {string} The date.
		 *
		 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t06
		 */
		sent(row) {
			return row.sentAt ? longDate(row.sentAt, readerLocale(this.locale)) : ''
		},

		/**
		 * Read the contacts again.
		 *
		 * @return {Promise<void>} Resolves when read.
		 *
		 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t06
		 */
		async load() {
			const overview = await this.client.fetchContacts()
			if (overview === null) {
				this.problem = 'That did not work. Try again later.'
			} else {
				this.overview = { ...this.overview, ...overview }
			}
			this.loading = false
		},

		/**
		 * The invitation went: close the dialog, say so and read again.
		 *
		 * @return {Promise<void>} Resolves when read.
		 *
		 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t06
		 */
		async invited() {
			this.inviting = false
			this.notice = 'The invitation is sent.'
			await this.load()
		},

		/**
		 * Do one thing to a row, then read the list again.
		 *
		 * @param {string} action respond, resend, withdraw or remove.
		 * @param {object} row The row.
		 * @param {object} [extra] More arguments, such as `accept`.
		 * @return {Promise<void>} Resolves when done.
		 *
		 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t06
		 */
		async act(action, row, extra = {}) {
			this.notice = ''
			this.problem = ''
			const answer = await this.client.contactAction(action, { id: row.id, ...extra })
			if (!answer.ok) {
				this.problem = 'That did not work. Try again later.'
			}
			await this.load()
		},
	},
}
</script>

<style scoped>
.pq-contacts > * + *,
.pq-contacts section > * + * {
	margin-block-start: var(--utrecht-space-block-md, 1rem);
}

.pq-contacts__head,
.pq-contacts__chips,
.pq-contacts__buttons {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--utrecht-space-inline-sm, 0.5rem);
}

.pq-contacts__head {
	justify-content: space-between;
}

.pq-contacts__list > li {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--utrecht-space-inline-sm, 0.5rem);
}

.pq-contacts__initials {
	display: inline-grid;
	place-items: center;
	inline-size: 2.5rem;
	block-size: 2.5rem;
	border-radius: 50%;
	background: var(--utrecht-color-grey-90, Canvas);
}

.pq-contacts__error {
	color: var(--utrecht-feedback-danger-color, currentcolor);
	font-weight: var(--utrecht-typography-weight-scale-bold-font-weight, bold);
}
</style>
