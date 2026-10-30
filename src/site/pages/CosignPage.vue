<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<section
		class="container pq-cosign"
		data-testid="cosign-page"
		:aria-busy="phase === 'loading' ? 'true' : 'false'">
		<h1 class="utrecht-heading-1">
			{{ session && request ? t('Check and co-sign') : t('Co-sign a request') }}
		</h1>

		<template v-if="!session">
			<p class="utrecht-paragraph">
				{{ t('You were asked to co-sign a request. Sign in with your own DigiD to see it.') }}
			</p>
			<button
				type="button"
				class="utrecht-button utrecht-button--primary-action"
				data-testid="cosign-sign-in"
				@click="$emit('signin')">
				{{ t('Sign in and check') }}
			</button>
		</template>

		<p v-else-if="phase === 'loading'" role="status" data-testid="cosign-loading">
			{{ t('Loading…') }}
		</p>

		<div v-else-if="outcome" :role="outcome.role" data-testid="cosign-outcome">
			<p class="utrecht-paragraph">
				{{ outcome.text }}
			</p>
		</div>

		<form v-else-if="request" data-testid="cosign-form" @submit.prevent="answer('sign')">
			<dl class="pq-cosign__summary" data-testid="cosign-summary">
				<dt>{{ t('Request') }}</dt>
				<dd>{{ request.formName }}</dd>
				<dt>{{ t('Reference') }}</dt>
				<dd>{{ request.reference }}</dd>
				<dt>{{ t('Co-sign before') }}</dt>
				<dd>{{ request.dueAt }}</dd>
			</dl>
			<p class="utrecht-paragraph">
				{{ t('You cannot change the request.') }}
			</p>
			<div class="utrecht-form-field utrecht-form-field--checkbox">
				<input
					id="pq-cosign-read"
					v-model="confirmed"
					type="checkbox"
					class="utrecht-checkbox"
					data-testid="cosign-confirm">
				<label class="utrecht-form-label" for="pq-cosign-read">
					{{ t('I have read the request and co-sign it.') }}
				</label>
			</div>
			<div class="pq-cosign__actions">
				<button
					type="submit"
					class="utrecht-button utrecht-button--primary-action"
					:disabled="busy || !confirmed"
					data-testid="cosign-sign">
					{{ t('Co-sign') }}
				</button>
				<button
					type="button"
					class="utrecht-button utrecht-button--secondary-action"
					:disabled="busy"
					data-testid="cosign-refuse"
					@click="answer('refuse')">
					{{ t('Decline') }}
				</button>
			</div>
		</form>
	</section>
</template>

<script>
import { cosignRefusal } from '../../shared/cosignLink.js'

/**
 * The co-signer's page (resident-identity-in-forms T04, MedeOndertekenen):
 * signed out, the way in; signed in, the request read-only with the box to
 * tick, "Co-sign" and "Decline". The case already exists and waits; the
 * answer goes to the case app through the portal.
 *
 * @spec openspec/changes/resident-identity-in-forms/specs/resident-identity-in-forms/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003
 */
export default {
	name: 'CosignPage',

	props: {
		/** The token from the link. */
		token: {
			type: String,
			required: true,
		},

		/** The session, or null when signed out. */
		session: {
			type: Object,
			default: null,
		},

		/** The shared portal API, bound to the bearer. */
		api: {
			type: Object,
			required: true,
		},

		/** The site translator. */
		t: {
			type: Function,
			required: true,
		},
	},

	emits: ['signin', 'done'],

	data() {
		return {
			phase: 'loading',
			request: null,
			outcome: null,
			confirmed: false,
			busy: false,
		}
	},

	watch: {
		session() {
			this.load()
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Read what the co-signer is asked to sign.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/resident-identity-in-forms/tasks.md#t04
		 */
		async load() {
			if (!this.session) {
				return
			}
			this.phase = 'loading'
			const res = await this.api.request('GET', `/cosign?token=${encodeURIComponent(this.token)}`)
			if (res.ok) {
				this.request = res.json
			} else {
				this.outcome = { role: 'alert', text: this.t(cosignRefusal(res.status, res.json?.error)) }
			}
			this.phase = 'ready'
		},

		/**
		 * Send the answer and show what became of it.
		 *
		 * @param {string} answer `sign` or `refuse`.
		 * @return {Promise<void>}
		 * @spec openspec/changes/resident-identity-in-forms/tasks.md#t04
		 */
		async answer(answer) {
			this.busy = true
			const res = await this.api.request('POST', '/cosign', {
				token: this.token,
				answer,
				confirmed: this.confirmed ? 'true' : '',
			})
			this.busy = false
			if (res.ok) {
				this.outcome = {
					role: 'status',
					text: this.t(answer === 'sign'
						? 'Thank you. You co-signed the request.'
						: 'You declined to co-sign. The applicant will be told.'),
				}
				this.$emit('done')
				return
			}
			this.outcome = { role: 'alert', text: this.t(cosignRefusal(res.status, res.json?.error)) }
			if (res.status >= 400 && res.status < 500 && res.status !== 401) {
				this.$emit('done')
			}
		},
	},
}
</script>

<style scoped>
.pq-cosign__summary {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: var(--utrecht-space-block-xs, 4px) var(--utrecht-space-inline-md, 16px);
}

.pq-cosign__summary dt {
	font-weight: var(--utrecht-typography-weight-scale-bold-font-weight, bold);
}

.pq-cosign__actions {
	display: flex;
	flex-wrap: wrap;
	gap: var(--utrecht-space-inline-md, 16px);
}
</style>
