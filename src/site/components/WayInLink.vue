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
		return { link: null, busy: false, result: null, opened: null }
	},

	computed: {
		/**
		 * The identity routes for this portal.
		 *
		 * @return {object}
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
