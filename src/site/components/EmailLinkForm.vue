<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<form
		class="pq-email-link"
		data-testid="site-email-link-form"
		@submit.prevent="send">
		<div class="utrecht-form-field">
			<label class="utrecht-form-label" :for="fieldId">{{
				say('The e-mail address you signed up with')
			}}</label>
			<input
				:id="fieldId"
				v-model="email"
				class="utrecht-textbox"
				type="email"
				name="email"
				autocomplete="email"
				required />
		</div>
		<button
			type="submit"
			class="utrecht-button pq-email-link__button"
			:class="
				!secondary
					? 'utrecht-button--primary-action'
					: 'utrecht-button--secondary-action'
			"
			:disabled="busy"
			data-testid="site-email-link-send">
			{{ busy ? say('One moment…') : label || say('Send me a sign-in link') }}
		</button>
		<p
			v-if="outcome"
			class="utrecht-paragraph"
			:role="outcome.role"
			data-testid="site-email-link-result">
			{{ outcome.text }}
		</p>
	</form>
</template>

<script>
import {
	emailLinkSentText,
	wayInRefusalText,
	waysInApi,
	waysInTranslator,
} from '../lib/waysIn.js'
import { pageLocale } from '../pages/inbox/translate.js'

/**
 * The e-mail link card's form (sign-in-with-an-email-link, board
 * warmtepompacademie/Inloggen "Als deelnemer"): one address field and
 * "Stuur mij een inloglink". The answer is the same sentence for a known and
 * an unknown address (REQ-IWI-007).
 *
 * @spec openspec/changes/sign-in-with-an-email-link/tasks.md#7
 */
export default {
	name: 'EmailLinkForm',

	props: {
		/** The portal API base (`.../portal/api`). */
		authBase: { type: String, required: true },
		/** The serving portal's slug, or ''. */
		portal: { type: String, default: '' },
		/** The button's words, from the portal's card. */
		label: { type: String, default: '' },
		/** Whether this card's button is a secondary one (not the page's first card). */
		secondary: { type: Boolean, default: false },
	},

	data() {
		return {
			email: '',
			busy: false,
			outcome: null,
			fieldId: `pq-email-link-${Math.random().toString(36).slice(2, 8)}`,
		}
	},

	methods: {
		/**
		 * @param {string} key The English source.
		 * @return {string} The words in the page language.
		 * @spec openspec/changes/sign-in-with-an-email-link/tasks.md#7
		 */
		say(key) {
			return waysInTranslator(null, pageLocale())(key)
		},

		/**
		 * Ask for the link; show the one sentence or the refusal.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/sign-in-with-an-email-link/specs/portal-ways-in/spec.md#requirement-the-e-mail-link-form-reveals-nothing-about-accounts-req-iwi-007
		 */
		async send() {
			this.busy = true
			this.outcome = null
			const answer = await waysInApi(
				this.authBase,
				this.portal,
			).requestEmailLink(this.email)
			this.busy = false
			this.outcome = answer.ok
				? { role: 'status', text: this.say(emailLinkSentText()) }
				: { role: 'alert', text: this.say(wayInRefusalText(answer.error)) }
		},
	},
}
</script>

<style scoped>
.pq-email-link {
	display: grid;
	gap: var(--utrecht-space-block-sm, 0.75rem);
}

.pq-email-link__button {
	justify-self: start;
}
</style>
