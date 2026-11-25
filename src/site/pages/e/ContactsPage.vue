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
				<p class="utrecht-paragraph pq-contacts__hint">
					{{ t('Someone only becomes your contact once you have both approved each other.') }}
				</p>
				<ul class="pq-contacts__cards">
					<li
						v-for="row in waiting"
						:key="row.id"
						class="pq-contacts__card"
						:class="{ 'pq-contacts__card--request': row.state === 'requested' }"
						data-testid="contacts-waiting-row"
						:data-state="row.state">
						<span class="pq-contacts__initials" aria-hidden="true">{{
							initials(row.displayName || row.email)
						}}</span>
						<span class="pq-contacts__who">
							<strong v-if="row.state === 'requested'">{{
								t('{name} wants to add you as a contact', { name: row.displayName || row.email })
							}}</strong>
							<strong v-else-if="row.state === 'invited'">{{
								t('Invitation to {email}', { email: row.email || row.displayName })
							}}</strong>
							<strong v-else>{{ row.displayName || row.email }}</strong>
							<span class="pq-contacts__line">
								<template v-if="row.state === 'declined'">{{ t('Not accepted') }}</template>
								<template v-else-if="row.state === 'requested'">{{
									waitingLine(row)
								}}</template>
								<template v-else-if="sent(row) !== ''">{{
									t('Sent on {date}', { date: sent(row) })
								}}</template>
							</span>
						</span>
						<span v-if="row.state === 'invited'" class="pq-contacts__badge pq-contacts__badge--waiting">{{
							t('Waiting for a reply')
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
									class="pq-contacts__link"
									data-testid="contact-resend"
									@click="act('resend', row)">
									{{ t('Send again') }}
								</button>
								<button
									type="button"
									class="pq-contacts__link pq-contacts__link--danger"
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
				<div
					class="pq-contacts__chips"
					role="group"
					:aria-label="t('Your contacts')">
					<button
						v-for="chip in filters"
						:key="chip"
						type="button"
						class="pq-contacts__chip"
						:class="{ 'pq-contacts__chip--on': chip === role }"
						:aria-pressed="chip === role ? 'true' : 'false'"
						:data-testid="`contacts-chip-${chip}`"
						@click="role = chip">
						{{ t(label(chip)) }}
						<span class="pq-contacts__count">{{ count(chip) }}</span>
					</button>
				</div>
				<p
					v-if="shown.length === 0"
					class="utrecht-paragraph"
					data-testid="contacts-empty">
					{{ t('You have no contacts yet.') }}
				</p>
				<ul v-else class="pq-contacts__rows">
					<li
						v-for="row in shown"
						:key="row.id"
						class="pq-contacts__row"
						data-testid="contact-row">
						<span class="pq-contacts__initials" aria-hidden="true">{{
							initials(row.displayName)
						}}</span>
						<span class="pq-contacts__who">
							<span class="pq-contacts__name">
								<strong>{{ row.displayName }}</strong>
								<span class="pq-contacts__badge" :class="`pq-contacts__badge--${row.role}`">{{ t(label(row.role)) }}</span>
							</span>
							<span v-if="row.line" class="pq-contacts__line">{{ row.line }}</span>
						</span>
						<span class="pq-contacts__buttons">
							<button
								type="button"
								class="pq-contacts__link"
								data-testid="contact-message-open"
								@click="openMessage(row)">
								{{ t('Send message') }}
							</button>
							<button
								type="button"
								class="pq-contacts__link pq-contacts__link--danger"
								data-testid="contact-remove"
								@click="act('remove', row)">
								{{ t('Remove') }}
							</button>
						</span>
					</li>
				</ul>
			</section>

			<section aria-labelledby="pq-contacts-sees" class="pq-contacts__callout">
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
		<ContactMessageModal
			v-if="writingTo"
			:api="api"
			:t="t"
			:contact="writingTo"
			@cancel="writingTo = null"
			@sent="messageSent" />
	</section>
</template>

<script>
import ContactMessageModal from '../../modals/e/ContactMessageModal.vue'
import InviteContactModal from '../../modals/e/InviteContactModal.vue'
import { contactsApi } from '../../../shared/areaApi.js'
import { contactsOfRole, initialsOf, ROLE_FILTERS, roleLabel } from './contacts.js'
import { longDate, readerLocale } from './format.js'

export default {
	name: 'ContactsPage',

	components: { ContactMessageModal, InviteContactModal },

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
			// The contact the message dialog writes to, or null.
			writingTo: null,
			role: 'all',
			notice: '',
			problem: '',
			filters: ROLE_FILTERS,
		}
	},

	computed: {
		/**
		 * @return {object} The contact calls over the portal api.
		 * @spec openspec/changes/archive/2026-11-25-own-contacts-and-invitations/tasks.md#t06
		 */
		client() {
			return contactsApi(this.api)
		},

		/**
		 * @return {Array<object>} Requests to answer, then invitations sent.
		 * @spec openspec/changes/archive/2026-11-25-own-contacts-and-invitations/tasks.md#t06
		 */
		waiting() {
			return [...this.overview.incoming, ...this.overview.outgoing]
		},

		/**
		 * @return {Array<object>} The contacts of the chosen role chip.
		 * @spec openspec/changes/archive/2026-11-25-own-contacts-and-invitations/tasks.md#t06
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
	 * @spec openspec/changes/archive/2026-11-25-own-contacts-and-invitations/tasks.md#t06
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
		 * @spec openspec/changes/archive/2026-11-25-own-contacts-and-invitations/tasks.md#t06
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
		 * @spec openspec/changes/archive/2026-11-25-own-contacts-and-invitations/tasks.md#t06
		 */
		sent(row) {
			return row.sentAt ? longDate(row.sentAt, readerLocale(this.locale)) : ''
		},

		/**
		 * The second line of a request: who is asking, and since when.
		 *
		 * @param {object} row The requested row.
		 * @return {string} The role, the line and the date asked, as far as known.
		 *
		 * @spec openspec/changes/archive/2026-11-25-own-contacts-and-invitations/tasks.md#t09
		 */
		waitingLine(row) {
			const who = [this.t(this.label(row.role)), row.line].filter((part) => part).join(', ')
			const asked = this.sent(row) !== '' ? this.t('Requested on {date}', { date: this.sent(row) }) : ''
			return [who, asked].filter((part) => part !== '').join('. ')
		},

		/**
		 * Read the contacts again.
		 *
		 * @return {Promise<void>} Resolves when read.
		 *
		 * @spec openspec/changes/archive/2026-11-25-own-contacts-and-invitations/tasks.md#t06
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
		 * @spec openspec/changes/archive/2026-11-25-own-contacts-and-invitations/tasks.md#t06
		 */
		async invited() {
			this.inviting = false
			this.notice = 'The invitation is sent.'
			await this.load()
		},

		/**
		 * Open the message dialog for one approved contact.
		 *
		 * @param {object} row The contact.
		 * @return {void}
		 *
		 * @spec openspec/changes/archive/2026-11-25-own-contacts-and-invitations/tasks.md#t07
		 */
		openMessage(row) {
			this.notice = ''
			this.problem = ''
			this.writingTo = row
		},

		/**
		 * The message went: close the dialog and say where the conversation is.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/archive/2026-11-25-own-contacts-and-invitations/tasks.md#t07
		 */
		messageSent() {
			this.writingTo = null
			this.notice =
				'Your message has been sent. You find the conversation under Messages.'
		},

		/**
		 * Do one thing to a row, then read the list again.
		 *
		 * @param {string} action respond, resend, withdraw or remove.
		 * @param {object} row The row.
		 * @param {object} [extra] More arguments, such as `accept`.
		 * @return {Promise<void>} Resolves when done.
		 *
		 * @spec openspec/changes/archive/2026-11-25-own-contacts-and-invitations/tasks.md#t06
		 */
		async act(action, row, extra = {}) {
			this.notice = ''
			this.problem = ''
			const answer = await this.client.contactAction(action, {
				id: row.id,
				...extra,
			})
			if (!answer.ok) {
				this.problem = 'That did not work. Try again later.'
			}
			await this.load()
		},
	},
}
</script>

<style scoped>
/*
 * The page as board Contacten draws it (own-contacts-and-invitations T09):
 * a request is a highlighted card with its answer buttons, an invitation a
 * card with its state and two text actions, the role filter a row of chips
 * with counts, each contact a row with initials, name, role badge, line and
 * two text actions, and "what does a contact see" a callout. Colours come
 * from the theme tokens, with the system colours as the last fallback.
 */
.pq-contacts > * + *,
.pq-contacts section > * + * {
	margin-block-start: var(--utrecht-space-block-md, 1rem);
}

.pq-contacts__head,
.pq-contacts__chips,
.pq-contacts__buttons,
.pq-contacts__name {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--utrecht-space-inline-sm, 0.5rem);
}

.pq-contacts__head {
	justify-content: space-between;
}

.pq-contacts__hint,
.pq-contacts__line {
	color: var(--nldesign-color-text-muted, var(--utrecht-color-grey-30, GrayText));
	font-size: 0.875em;
}

.pq-contacts .pq-contacts__hint {
	margin-block-start: 0.25rem;
}

.pq-contacts__cards,
.pq-contacts__rows {
	list-style: none;
	margin: 0;
	padding: 0;
}

.pq-contacts__card,
.pq-contacts__row {
	display: grid;
	grid-template-columns: auto minmax(0, 1fr) auto auto;
	align-items: center;
	gap: 0.75rem 1rem;
}

.pq-contacts__card {
	padding: 0.75rem 1rem;
	border: 1px solid var(--nldesign-color-border-light, var(--utrecht-color-grey-80, color-mix(in srgb, currentcolor 18%, transparent)));
	border-radius: var(--utrecht-border-radius-md, 0.375rem);
}

.pq-contacts__card + .pq-contacts__card {
	margin-block-start: 0.5rem;
}

.pq-contacts__card--request {
	border-color: var(--utrecht-feedback-warning-border-color, var(--nldesign-color-warning, Mark));
	background: var(--utrecht-feedback-warning-background-color, var(--nldesign-color-warning-light, Canvas));
}

.pq-contacts__row {
	padding-block: 0.75rem;
	border-block-end: 1px solid var(--nldesign-color-border-light, var(--utrecht-color-grey-80, color-mix(in srgb, currentcolor 18%, transparent)));
}

.pq-contacts__rows {
	border-block-start: 1px solid var(--nldesign-color-border-light, var(--utrecht-color-grey-80, color-mix(in srgb, currentcolor 18%, transparent)));
}

.pq-contacts__who {
	display: flex;
	flex-direction: column;
	min-inline-size: 0;
}

.pq-contacts__initials {
	display: inline-grid;
	place-items: center;
	inline-size: 2.5rem;
	block-size: 2.5rem;
	border-radius: 50%;
	background: var(--nldesign-color-primary-light, var(--utrecht-color-grey-90, Canvas));
	font-size: 0.875em;
	font-weight: var(--utrecht-typography-weight-scale-bold-font-weight, bold);
}

.pq-contacts__badge {
	display: inline-flex;
	align-items: center;
	padding: 0.125rem 0.5rem;
	border-radius: 999px;
	background: var(--nldesign-color-primary-light, var(--utrecht-color-grey-90, Canvas));
	font-size: 0.75em;
}

.pq-contacts__badge--waiting {
	background: var(--utrecht-feedback-warning-background-color, var(--nldesign-color-warning-light, Canvas));
}

.pq-contacts__chip {
	display: inline-flex;
	align-items: center;
	gap: 0.5rem;
	min-block-size: 2.75rem;
	padding-inline: 1rem;
	border: 1px solid var(--nldesign-color-primary, LinkText);
	border-radius: 999px;
	background: var(--utrecht-document-background-color, Canvas);
	color: var(--nldesign-color-primary, LinkText);
	font: inherit;
	cursor: pointer;
}

.pq-contacts__chip--on {
	background: var(--nldesign-color-primary, LinkText);
	color: var(--nldesign-color-primary-contrast, Canvas);
}

.pq-contacts__count {
	display: inline-grid;
	place-items: center;
	min-inline-size: 1.5rem;
	block-size: 1.5rem;
	padding-inline: 0.25rem;
	border-radius: 999px;
	background: var(--nldesign-color-primary-light, var(--utrecht-color-grey-90, Canvas));
	color: var(--nldesign-color-primary, LinkText);
	font-size: 0.75em;
}

.pq-contacts__link {
	min-block-size: 24px;
	padding: 0;
	border: 0;
	background: none;
	color: var(--utrecht-link-color, LinkText);
	font: inherit;
	text-decoration: underline;
	cursor: pointer;
}

.pq-contacts__link--danger {
	color: var(--utrecht-feedback-danger-color, var(--nldesign-color-error, LinkText));
}

.pq-contacts__callout {
	padding: 1rem 1.25rem;
	border-radius: var(--utrecht-border-radius-md, 0.375rem);
	background: var(--nldesign-color-primary-light, var(--utrecht-color-grey-90, Canvas));
}

.pq-contacts .pq-contacts__callout h2 {
	margin-block-start: 0;
	font-size: 1em;
}

@media (max-width: 40rem) {
	.pq-contacts__card,
	.pq-contacts__row {
		grid-template-columns: auto minmax(0, 1fr);
	}

	.pq-contacts__buttons,
	.pq-contacts__badge--waiting {
		grid-column: 2;
	}
}

.pq-contacts__error {
	color: var(--utrecht-feedback-danger-color, currentcolor);
	font-weight: var(--utrecht-typography-weight-scale-bold-font-weight, bold);
}
</style>
