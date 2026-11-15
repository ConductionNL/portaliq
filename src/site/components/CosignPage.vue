<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<section
		class="container pq-cosign"
		:aria-busy="busy ? 'true' : undefined"
		data-testid="cosign-page">
		<template v-if="step === 'review'">
			<h1 class="utrecht-heading-1">
				{{ t('Check and co-sign') }}
			</h1>
			<p v-if="request.reference" class="utrecht-paragraph" data-testid="cosign-reference">
				{{ t('Request {reference}', { reference: request.reference }) }}
			</p>
			<dl class="pq-cosign__summary" data-testid="cosign-summary">
				<div v-for="line in request.lines" :key="line.label" class="pq-cosign__line">
					<dt>{{ line.label }}</dt>
					<dd>{{ line.value }}</dd>
				</div>
			</dl>
			<p class="utrecht-paragraph">
				{{ t('You cannot change the request.') }}
			</p>
			<p v-if="request.deadline" class="utrecht-paragraph" data-testid="cosign-deadline">
				{{ t('Do this before {date}.', { date: request.deadline }) }}
			</p>
			<p class="pq-cosign__check">
				<input
					id="pq-cosign-confirm"
					v-model="confirmed"
					type="checkbox"
					data-testid="cosign-confirm">
				<label for="pq-cosign-confirm">{{ t('I have read the request and co-sign it.') }}</label>
			</p>
			<p v-if="problem" class="utrecht-paragraph pq-cosign__problem" role="alert" data-testid="cosign-problem">
				{{ problem }}
			</p>
			<div class="pq-cosign__actions">
				<button
					type="button"
					class="utrecht-button utrecht-button--primary-action"
					:disabled="busy"
					data-testid="cosign-sign"
					@click="answer('sign')">
					{{ t('Co-sign') }}
				</button>
				<button
					type="button"
					class="utrecht-button utrecht-button--secondary-action"
					:disabled="busy"
					data-testid="cosign-refuse"
					@click="answer('refuse')">
					{{ t('Refuse') }}
				</button>
			</div>
		</template>

		<div v-else-if="step === 'done'" role="status" data-testid="cosign-done">
			<h1 class="utrecht-heading-1">
				{{ t('Co-sign a request') }}
			</h1>
			<p class="utrecht-paragraph">
				{{ outcome === 'signed'
					? t('Thank you. You co-signed the request.')
					: t('You refused to co-sign. The applicant is told.') }}
			</p>
		</div>

		<form v-else data-testid="cosign-start" @submit.prevent="start">
			<h1 class="utrecht-heading-1">
				{{ t('Co-sign a request') }}
			</h1>
			<p class="utrecht-paragraph">
				{{ t('Enter the code from the e-mail. You then sign in with your own DigiD and read the request before you decide.') }}
			</p>
			<p class="pq-cosign__field">
				<label class="utrecht-form-label" for="pq-cosign-code">{{ t('Your code') }}</label>
				<input
					id="pq-cosign-code"
					v-model="typed"
					class="utrecht-textbox"
					type="text"
					autocomplete="one-time-code"
					autocapitalize="characters"
					spellcheck="false"
					:aria-invalid="problem ? 'true' : undefined"
					:aria-describedby="problem ? 'pq-cosign-problem' : undefined"
					data-testid="cosign-code">
			</p>
			<p
				v-if="problem"
				id="pq-cosign-problem"
				class="utrecht-paragraph pq-cosign__problem"
				role="alert"
				data-testid="cosign-problem">
				{{ problem }}
			</p>
			<button
				type="submit"
				class="utrecht-button utrecht-button--primary-action"
				:disabled="busy"
				data-testid="cosign-start-button">
				{{ t('Log in with DigiD and check') }}
			</button>
		</form>
	</section>
</template>

<script>
import {
	answerCosign,
	digidHref,
	forgetCode,
	normaliseCode,
	openCosign,
	recallCode,
	rememberCode,
} from '../lib/cosign.js'

/**
 * The co-signer's page (resident-identity-in-forms, board MedeOndertekenen):
 * the code from the invitation, the DigiD sign-in, the request read-only, and
 * "Mede-ondertekenen" or "Weigeren".
 *
 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003
 */
export default {
	name: 'CosignPage',
	props: {
		/** The translator. */
		t: { type: Function, required: true },
		/** The co-signer's bearer, '' before they signed in. */
		token: { type: String, default: '' },
		/** The portal API base (`…/portal/api`). */
		authBase: { type: String, default: '' },
		/** The portal slug. */
		portal: { type: String, default: '' },
		/** `fetch`, or a stand-in in a test. */
		fetchImpl: { type: Function, default: null },
		/** The session storage, or a stand-in in a test. */
		storage: { type: Object, default: null },
		/** Where the page starts, for a server render or a test. */
		initialState: { type: Object, default: null },
	},

	data() {
		const initial = this.initialState || {}
		return {
			step: initial.step || 'code',
			typed: '',
			code: initial.code || '',
			request: initial.request || { reference: '', deadline: '', lines: [] },
			confirmed: false,
			busy: false,
			problem: initial.problem || '',
			outcome: initial.outcome || '',
		}
	},

	computed: {
		/**
		 * The connection to the portal API.
		 *
		 * @return {object}
		 */
		conn() {
			return { base: this.authBase, portal: this.portal, token: this.token, fetchImpl: this.fetchImpl }
		},
	},

	watch: {
		/**
		 * The session became known after the page mounted: open what the code names.
		 *
		 * @return {void}
		 */
		token() {
			this.resume()
		},
	},

	mounted() {
		if (this.initialState) {
			return
		}
		this.resume()
	},

	methods: {
		/**
		 * Back from DigiD with a kept code: open the request.
		 *
		 * @return {void}
		 */
		resume() {
			const kept = recallCode(this.storage)
			if (kept && this.token && this.step === 'code' && !this.busy) {
				this.code = kept
				this.open()
			}
		},

		/**
		 * The co-signer gave the code: keep it and sign in, or open at once when signed in.
		 *
		 * @return {void}
		 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003
		 */
		start() {
			const code = normaliseCode(this.typed)
			if (!code) {
				this.problem = this.t('Enter the code from the e-mail, for example 7RQ-K2M.')
				return
			}
			this.code = code
			this.problem = ''
			rememberCode(code, this.storage)
			if (this.token) {
				this.open()
				return
			}
			window.location.assign(digidHref(this.authBase, this.portal))
		},

		/**
		 * Open the request the code names.
		 *
		 * @return {Promise<void>}
		 */
		async open() {
			this.busy = true
			const opened = await openCosign(this.conn, this.code)
			this.busy = false
			if (opened.status === 'ok') {
				this.request = opened.submission
				this.step = 'review'
				return
			}
			this.step = 'code'
			this.problem = this.problemFor(opened.status)
		},

		/**
		 * Co-sign or refuse.
		 *
		 * @param {'sign'|'refuse'} action The choice.
		 * @return {Promise<void>}
		 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003
		 */
		async answer(action) {
			if (action === 'sign' && this.confirmed !== true) {
				this.problem = this.problemFor('notConfirmed')
				return
			}
			this.busy = true
			const answered = await answerCosign(this.conn, { code: this.code, action, confirmed: this.confirmed })
			this.busy = false
			if (answered.status === 'signed' || answered.status === 'refused') {
				forgetCode(this.storage)
				this.outcome = answered.status
				this.step = 'done'
				return
			}
			this.problem = this.problemFor(answered.status)
		},

		/**
		 * What a refusal tells the co-signer.
		 *
		 * @param {string} status The refusal.
		 * @return {string}
		 */
		problemFor(status) {
			const problems = {
				signIn: this.t('Log in with your own DigiD to co-sign.'),
				notFound: this.t('This code is unknown. Check the code in the e-mail.'),
				expired: this.t('The time to co-sign has passed.'),
				sameSubject: this.t('You cannot co-sign your own request.'),
				notConfirmed: this.t('Tick that you have read the request.'),
			}
			return problems[status] || this.t('Something went wrong. Try again later.')
		},
	},
}
</script>

<style scoped>
.pq-cosign__summary {
	display: grid;
	gap: var(--utrecht-space-row-sm, 8px);
	margin: var(--utrecht-space-block-md, 16px) 0;
}

.pq-cosign__line {
	display: grid;
	grid-template-columns: minmax(8rem, 1fr) 2fr;
	gap: var(--utrecht-space-column-md, 16px);
}

.pq-cosign__line dt {
	font-weight: var(--utrecht-typography-weight-scale-bold, 700);
}

.pq-cosign__line dd {
	margin: 0;
	overflow-wrap: anywhere;
}

.pq-cosign__check,
.pq-cosign__field {
	display: flex;
	flex-wrap: wrap;
	gap: var(--utrecht-space-column-sm, 8px);
	align-items: center;
}

.pq-cosign__problem {
	color: var(--utrecht-feedback-danger-color, var(--color-error));
}

.pq-cosign__actions {
	display: flex;
	flex-wrap: wrap;
	gap: var(--utrecht-space-column-md, 16px);
}
</style>
