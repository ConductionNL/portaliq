<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<section
		v-if="opened"
		class="container pq-reference-case"
		aria-labelledby="pq-reference-case-title"
		data-testid="reference-case">
		<h1 id="pq-reference-case-title" class="utrecht-heading-2">
			{{ t('Case {reference}', { reference: opened.caseReference }) }}
		</h1>
		<p class="utrecht-paragraph" role="status">
			{{
				t(
					'You are viewing this case with a link. You cannot change anything here.',
				)
			}}
		</p>
		<dl class="pq-reference-case__fields">
			<div v-for="field in opened.fields" :key="field.key">
				<dt>{{ field.key }}</dt>
				<dd>{{ field.value }}</dd>
			</div>
		</dl>
	</section>

	<section
		v-else
		class="container pq-way-in-link"
		:data-testid="`way-in-link-${link ? link.kind : 'none'}`">
		<p v-if="busy" class="utrecht-paragraph" role="status">
			{{ t('Loading…') }}
		</p>
		<!-- The e-mail link page (sign-in-with-an-email-link T07): the portal
		     and the masked address, one button; in another browser the address
		     first. Never recorded (data-traffic-no-recording). -->
		<div
			v-if="link && link.kind === 'email-link' && emailLink"
			data-traffic-no-recording
			data-testid="way-in-email-link">
			<h1 class="utrecht-heading-2">
				{{
					t('Sign in to {portal}', {
						portal: emailLink.portal || portalName,
					})
				}}
			</h1>
			<p class="utrecht-paragraph">
				{{ t('You sign in as {address}.', { address: emailLink.address }) }}
			</p>
			<form @submit.prevent="signInWithLink">
				<div v-if="!emailLink.sameBrowser" class="utrecht-form-field">
					<label class="utrecht-form-label" for="pq-email-link-confirm">{{
						t('The e-mail address this link was sent to')
					}}</label>
					<input
						id="pq-email-link-confirm"
						v-model="typedAddress"
						class="utrecht-textbox"
						type="email"
						autocomplete="email"
						required />
				</div>
				<button
					type="submit"
					class="utrecht-button utrecht-button--primary-action"
					:disabled="busy"
					data-testid="way-in-email-link-sign-in">
					{{ t('Sign in') }}
				</button>
			</form>
		</div>
		<template v-if="link && link.kind === 'invitation' && !result">
			<h1 class="utrecht-heading-2">
				{{ t('You are invited to {portal}', { portal: portalName }) }}
			</h1>
			<button
				type="button"
				class="utrecht-button utrecht-button--primary-action"
				:disabled="busy"
				data-testid="way-in-accept"
				@click="accept">
				{{ t('Accept') }}
			</button>
		</template>
		<p
			v-if="result"
			class="utrecht-paragraph"
			:role="result.role"
			data-testid="way-in-link-result">
			{{ result.text }}
		</p>
	</section>
</template>

<script>
import { storeSessionToken } from '../lib/authApi.js'
import {
	readyText,
	referenceCaseFields,
	takeWayInLink,
	wayInRefusalText,
	waysInApi,
} from '../lib/waysIn.js'

/**
 * What a mailed link opens on the site (identity-ways-in-screens T03, T04,
 * T06): an activation, an invitation to accept, or one case read only.
 *
 * @spec openspec/specs/portal-ways-in/spec.md#requirement-every-way-in-sends-its-secret-by-mail-req-iwi-001
 */
export default {
	name: 'WayInLink',

	props: {
		/** The portal API base (`.../portal/api`). */
		authBase: { type: String, required: true },
		/** The serving portal's slug, or ''. */
		portal: { type: String, default: '' },
		/** The portal's name, for the invitation heading. */
		portalName: { type: String, default: '' },
		/** The label of the e-mail sign-in, or ''. */
		emailSignIn: { type: String, default: '' },
		/** The translator. */
		t: { type: Function, required: true },
	},

	data() {
		return {
			link: null,
			busy: false,
			result: null,
			opened: null,
			emailLink: null,
			typedAddress: '',
		}
	},

	computed: {
		/**
		 * The identity routes for this portal.
		 *
		 * @return {object}
		 *
		 * @spec openspec/specs/portal-ways-in/spec.md#requirement-every-way-in-sends-its-secret-by-mail-req-iwi-001
		 */
		api() {
			return waysInApi(this.authBase, this.portal)
		},
	},

	/**
	 * Read the link once; an activation and a reference link act at once.
	 *
	 * @spec openspec/changes/archive/2026-10-02-identity-ways-in-screens/tasks.md#T03
	 */
	async mounted() {
		this.link = takeWayInLink(window.location, window.history)
		if (this.link === null) {
			return
		}
		if (this.link.kind === 'activate') {
			this.busy = true
			const answer = await this.api.activateAccount(this.link.token)
			this.busy = false
			this.result = answer.ok
				? { role: 'status', text: readyText(this.t, this.emailSignIn) }
				: {
						role: 'alert',
						text: this.t(
							wayInRefusalText(answer.error || 'activation_not_valid'),
						),
					}
		}
		if (this.link.kind === 'reference') {
			await this.openReference()
		}
		if (this.link.kind === 'email-link') {
			await this.describeEmailLink()
		}
	},

	methods: {
		/**
		 * Redeem a reference link and read its one case, read only.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/archive/2026-10-02-identity-ways-in-screens/tasks.md#T04
		 */
		async openReference() {
			this.busy = true
			const answer = await this.api.redeemReferenceLink(this.link.token)
			const read =
				answer.ok && answer.data.bearer
					? await this.api.referenceCase(answer.data.bearer)
					: null
			this.busy = false
			if (read && read.case) {
				this.opened = {
					caseReference:
						read.caseReference || answer.data.caseReference || '',

					fields: referenceCaseFields(read.case),
				}
				return
			}
			this.result = {
				role: 'alert',
				text: this.t('This link is no longer valid.'),
			}
		},

		/**
		 * Read what the e-mail link is, without spending it.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/sign-in-with-an-email-link/specs/portal-ways-in/spec.md#requirement-a-link-opened-in-another-browser-asks-for-the-address-first-req-iwi-010
		 */
		async describeEmailLink() {
			this.busy = true
			const answer = await this.api.describeEmailLink(this.link.token)
			this.busy = false
			if (answer.ok && answer.data.nonce) {
				this.emailLink = answer.data
				return
			}
			this.result = {
				role: 'alert',
				text: this.t(wayInRefusalText(answer.error || 'link_not_valid')),
			}
		},

		/**
		 * Press "Inloggen": spend the link, keep the bearer for this tab and
		 * open the site signed in.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/sign-in-with-an-email-link/specs/portal-ways-in/spec.md#requirement-an-e-mail-link-session-is-a-fresh-low-session-that-cannot-raise-itself-req-iwi-011
		 */
		async signInWithLink() {
			this.busy = true
			const answer = await this.api.redeemEmailLink({
				token: this.link.token,
				nonce: this.emailLink.nonce,
				email: this.emailLink.sameBrowser ? '' : this.typedAddress,
			})
			this.busy = false
			if (answer.ok && answer.data.bearer) {
				storeSessionToken(answer.data.bearer)
				window.location.reload()
				return
			}
			const text = this.t(wayInRefusalText(answer.error || 'link_not_valid'))
			if (
				answer.error === 'address_needed'
				|| answer.error === 'address_wrong'
			) {
				// The link is not spent: ask for the address it was sent to.
				this.emailLink = { ...this.emailLink, sameBrowser: false }
				this.result =
					answer.error === 'address_wrong' ? { role: 'alert', text } : null
				return
			}
			this.emailLink = null
			this.result = { role: 'alert', text }
		},

		/**
		 * Accept the invitation, then point to the e-mail sign-in.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/archive/2026-10-02-identity-ways-in-screens/tasks.md#T06
		 */
		async accept() {
			this.busy = true
			const answer = await this.api.acceptInvitation(this.link.token)
			this.busy = false
			this.result = answer.ok
				? { role: 'status', text: readyText(this.t, this.emailSignIn) }
				: {
						role: 'alert',
						text: this.t(
							wayInRefusalText(answer.error || 'invitation_not_valid'),
						),
					}
		},
	},
}
</script>
