<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<section class="pq-intake-status" data-testid="intake-status">
		<h2 v-if="heading" class="utrecht-heading-2">
			{{ heading }}
		</h2>

		<form class="pq-intake-status__form" @submit.prevent="lookUp">
			<label :for="inputId" class="utrecht-form-label">
				{{ inputLabel }}
			</label>
			<input
				:id="inputId"
				v-model="reference"
				class="utrecht-textbox"
				type="text"
				autocomplete="off"
				required
				data-testid="intake-status-reference" />
			<button
				type="submit"
				class="utrecht-button utrecht-button--primary-action"
				:disabled="busy"
				data-testid="intake-status-submit">
				{{ submitLabel }}
			</button>
		</form>

		<p
			v-if="view"
			:class="`utrecht-paragraph pq-intake-status__result pq-intake-status__result--${view.tone}`"
			data-testid="intake-status-result"
			:role="view.tone === 'error' ? 'alert' : 'status'">
			{{ view.sentence }}
		</p>

		<div v-if="payment" data-testid="intake-status-payment">
			<p class="utrecht-paragraph">
				{{ payment.sentence }}
			</p>
			<button
				v-if="payment.canPay"
				type="button"
				class="utrecht-button utrecht-button--primary-action"
				:disabled="paying"
				data-testid="intake-status-pay"
				@click="pay">
				{{ payLabel }}
			</button>
			<p
				v-if="payFailed"
				class="utrecht-paragraph"
				data-testid="intake-status-pay-error"
				role="alert">
				{{ payFailedLabel }}
			</p>
		</div>
	</section>
</template>

<script>
import { adoptSessionToken, authBaseFrom } from '../lib/authApi.js'
import { resolveApiBase } from '../lib/contentApi.js'
import {
	lookUpStatus,
	payIntake,
	paymentView,
	statusView,
} from '../lib/intakeApi.js'

/**
 * Look up what became of a request by its reference
 * (portal-intake-form-as-an-object, REQ-PIFO-005).
 *
 * The page reads the real state: queued, registered, or a create that failed,
 * which it says in words with what to do next. A reference in the page's own
 * `reference` query parameter is looked up at once, which is where the
 * embedded form's follow link points.
 */
export default {
	name: 'IntakeStatusBlock',

	props: {
		/** The serving portal's slug. Supplied by the host, never authored. */
		portal: {
			type: String,
			default: '',
		},

		/** Shown above the lookup. */
		heading: {
			type: String,
			default: 'Hoe staat het met mijn aanvraag?',
		},

		/** The label of the reference field. */
		inputLabel: {
			type: String,
			default: 'Uw kenmerk',
		},

		/** The lookup button's label. */
		submitLabel: {
			type: String,
			default: 'Bekijk de status',
		},

		/** The button that pays a fee not paid yet, or paid in vain. */
		payLabel: {
			type: String,
			default: 'Nu betalen',
		},

		/** Shown when the payment could not start. */
		payFailedLabel: {
			type: String,
			default: 'U kunt nu niet betalen. Probeer het later opnieuw.',
		},
	},

	data() {
		return {
			reference: '',
			view: null,
			payment: null,
			busy: false,
			paying: false,
			payFailed: false,
			inputId: 'pq-intake-status-reference',
		}
	},

	/**
	 * Look up a reference handed in the page's own query string.
	 *
	 * @return {void}
	 *
	 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md#requirement-the-case-is-created-asynchronously-and-the-citizen-gets-a-reference-at-once-req-pifo-005
	 */
	mounted() {
		const given = new URLSearchParams(window.location.search).get('reference')
		if (given) {
			this.reference = given
			this.lookUp()
		}
	},

	methods: {
		/**
		 * Read the state behind the entered reference.
		 *
		 * @return {Promise<void>} Resolves when answered.
		 *
		 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md#requirement-the-case-is-created-asynchronously-and-the-citizen-gets-a-reference-at-once-req-pifo-005
		 */
		async lookUp() {
			this.busy = true
			try {
				const status = await lookUpStatus(
					authBaseFrom(resolveApiBase()),
					this.reference,
					this.portal,
				)
				this.view = statusView(status)
				this.payment = paymentView(status)
			} catch {
				this.view = statusView(null)
				this.payment = null
			} finally {
				this.busy = false
			}
		},

		/**
		 * Pay a fee not paid yet, or paid in vain, and leave for the checkout
		 * in the top window. Paying needs the visitor's own session: the
		 * server refuses a reference that is not theirs.
		 *
		 * @return {Promise<void>} Resolves when answered.
		 *
		 * @spec openspec/changes/intake-pay-on-submit/specs/portal-intake-payment/spec.md#requirement-the-result-is-read-from-the-payment-record-req-ips-005
		 */
		async pay() {
			this.paying = true
			this.payFailed = false
			try {
				const { checkoutUrl } = await payIntake(
					authBaseFrom(resolveApiBase()),
					this.reference,
					this.portal,
					adoptSessionToken(),
				)
				window.top.location.assign(checkoutUrl)
			} catch {
				this.payFailed = true
			} finally {
				this.paying = false
			}
		},
	},
}
</script>

<style scoped>
.pq-intake-status__form > * + *,
.pq-intake-status__result {
	margin-block-start: var(--utrecht-space-block-md, 1rem);
}
</style>
