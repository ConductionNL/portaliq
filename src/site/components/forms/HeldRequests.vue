<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	The resident's own requests on Mijn zaken that wait for a co-signer
	(resident-identity-in-forms REQ-RIF-003, board WooVerzoekVerstuurd): one
	card per request, "Wacht op mede-ondertekening door {naam}" with the
	deadline, or that it was not co-signed and so not sent. Such a request has
	no case until it is co-signed and delivered, so it stands here instead of
	in the case list. Nothing shows while there is none, or when the list
	cannot be read.
-->
<template>
	<ul v-if="lines.length" class="pq-held" data-testid="held-requests">
		<li
			v-for="line in lines"
			:key="line.reference"
			class="pq-held__card"
			:class="{ 'pq-held__card--error': line.tone === 'error' }"
			data-testid="held-request">
			<span class="pq-held__kind">{{ t('Waiting for co-signature') }}</span>
			<span class="pq-held__name">{{ line.title }}</span>
			<span class="pq-held__line" data-testid="held-request-line">{{ line.sentence }}</span>
		</li>
	</ul>
</template>

<script>
import { heldLine } from '../../lib/cosign.js'

export default {
	name: 'HeldRequests',

	props: {
		/** The portal api; `listHeldRequests()` reads the held requests. */
		api: { type: Object, required: true },
		/** The translator `t(key, vars)`. */
		t: { type: Function, required: true },
		/** The page language. */
		locale: { type: String, default: 'nl' },
		/** Requests handed in by a test; null reads them. */
		initialRequests: { type: Array, default: null },
	},

	data() {
		return { requests: this.initialRequests || [] }
	},

	computed: {
		/**
		 * @return {Array<{reference: string, tone: string, title: string, sentence: string}>} The lines to show.
		 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003
		 */
		lines() {
			return this.requests
				.map((request) => heldLine(request, this.t, this.locale))
				.filter((line) => line !== null)
		},
	},

	async mounted() {
		if (this.initialRequests !== null || typeof this.api.listHeldRequests !== 'function') {
			return
		}
		this.requests = await this.api.listHeldRequests()
	},
}
</script>

<style scoped>
.pq-held {
	display: grid;
	gap: 0.75rem;
	margin: 0 0 1.5rem;
	padding: 0;
	list-style: none;
}

.pq-held__card {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
	padding: 1rem 1.25rem;
	border: 1px solid var(--nldesign-website-attention-border-color, var(--nldesign-color-border, currentcolor));
	border-radius: var(--nldesign-website-border-radius-large, 6px);
	background: var(--nldesign-website-attention-background-color, transparent);
}

.pq-held__card--error {
	border-color: var(--nldesign-color-error, var(--color-error, currentcolor));
}

.pq-held__kind {
	font-size: 0.875rem;
	font-weight: 600;
}

.pq-held__name {
	font-size: 1.125rem;
	font-weight: 700;
}

.pq-held__line {
	font-size: 0.9375rem;
}
</style>
