<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	The asker's side of an access request on the site (identity-access-requests
	T04, T05), ported from src/portal/components/AccessRequestsPage.jsx. A
	signed-in user asks for access to the cases of a company or person they act
	for, giving a reason, and follows every request they made: waiting,
	granted, or refused with the reason the organisation gave.
-->
<template>
	<section class="pq-access" aria-labelledby="pq-access-title" data-testid="access-requests">
		<h2 id="pq-access-title" class="utrecht-heading-2">
			{{ t('Access to cases') }}
		</h2>
		<p class="utrecht-paragraph">
			{{ t('Ask for access to the cases of a company or person you act for. The organisation answers your request.') }}
		</p>

		<form class="pq-access__form" novalidate @submit.prevent="send">
			<div class="utrecht-form-field">
				<label for="access-request-party" class="utrecht-form-label">
					{{ t('Whose cases do you need access to?') }}
				</label>
				<input
					id="access-request-party"
					v-model="draft.onBehalfOf"
					class="utrecht-textbox"
					name="onBehalfOf"
					data-testid="access-request-party">
			</div>
			<div class="utrecht-form-field">
				<label for="access-request-reason" class="utrecht-form-label">
					{{ t('Why do you need access?') }}
				</label>
				<textarea
					id="access-request-reason"
					v-model="draft.reason"
					class="utrecht-textarea"
					name="reason"
					data-testid="access-request-reason" />
			</div>

			<p
				v-if="problem !== ''"
				class="utrecht-paragraph pq-e-error"
				role="alert"
				data-testid="access-request-problem">
				{{ t(problem) }}
			</p>
			<p v-if="notice !== ''" class="utrecht-paragraph" role="status" data-testid="access-request-notice">
				{{ t(notice) }}
			</p>

			<button
				type="submit"
				class="utrecht-button utrecht-button--primary-action"
				data-testid="access-request-submit"
				:disabled="busy">
				{{ t('Ask for access') }}
			</button>
		</form>

		<h3 class="utrecht-heading-3">
			{{ t('Your requests') }}
		</h3>
		<p v-if="loading" class="utrecht-paragraph" role="status">
			{{ t('Loading…') }}
		</p>
		<p v-else-if="sorted.length === 0" class="utrecht-paragraph" data-testid="access-requests-empty">
			{{ t('You have not asked for access yet.') }}
		</p>
		<ul v-else class="utrecht-unordered-list pq-access__list" data-testid="access-requests-list">
			<li
				v-for="(request, index) in sorted"
				:key="request.id || request.uuid || `request-${index}`"
				class="utrecht-unordered-list__item"
				data-testid="access-request-row"
				:data-state="request.state || 'pending'">
				<strong>{{ t('For {party}', { party: request.onBehalfOf || '' }) }}</strong> <span>{{ t(stateLabel(request.state)) }}</span> <span v-if="askedOn(request)">{{ t('Asked on {date}', { date: askedOn(request) }) }}</span>
				<p v-if="request.state === 'refused' && request.decisionReason" class="utrecht-paragraph">
					{{ t('Reason: {reason}', { reason: request.decisionReason }) }}
				</p>
			</li>
		</ul>
	</section>
</template>

<script>
import { newestFirst, requestProblem, sendProblem, stateLabel } from './accessRequests.js'
import { longDate, readerLocale } from './format.js'

export default {
	name: 'AccessRequestsPage',

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
		/** The reader's locale; the page's `<html lang>` when empty. */
		locale: { type: String, default: '' },
		/** Requests to show without fetching (test seam). */
		initialRequests: { type: Array, default: null },
	},

	data() {
		return {
			requests: this.initialRequests || [],
			loading: this.initialRequests === null,
			draft: { onBehalfOf: '', reason: '' },
			problem: '',
			notice: '',
			busy: false,
			live: true,
		}
	},

	computed: {
		sorted() {
			return newestFirst(this.requests)
		},
	},

	/**
	 * Read the person's own requests when the screen opens.
	 *
	 * @return {Promise<void>} Resolves when read.
	 *
	 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-resident-must-be-able-to-ask-for-access-to-cases-req-srp-039
	 */
	async mounted() {
		if (this.initialRequests !== null) {
			return
		}
		const mine = await this.api.fetchMyAccessRequests()
		if (this.live) {
			this.requests = mine
			this.loading = false
		}
	},

	beforeUnmount() {
		this.live = false
	},

	methods: {
		stateLabel,

		/**
		 * The date a request was made, in the reader's language, or ''.
		 *
		 * @param {object} request The request.
		 * @return {string} The date.
		 *
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-resident-must-be-able-to-ask-for-access-to-cases-req-srp-039
		 */
		askedOn(request) {
			return request.requestedAt ? longDate(request.requestedAt, readerLocale(this.locale)) : ''
		},

		/**
		 * Send the request, then read the list again so it shows as waiting.
		 *
		 * @return {Promise<void>} Resolves when answered.
		 *
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-resident-must-be-able-to-ask-for-access-to-cases-req-srp-039
		 */
		async send() {
			this.notice = ''
			const missing = requestProblem(this.draft)
			this.problem = missing
			if (missing !== '') {
				return
			}
			this.busy = true
			const outcome = await this.api.requestAccess(this.draft.onBehalfOf.trim(), this.draft.reason.trim())
			this.busy = false
			if (!outcome.ok) {
				this.problem = sendProblem(outcome)
				return
			}
			this.draft = { onBehalfOf: '', reason: '' }
			this.notice = 'Your request has been sent.'
			this.requests = await this.api.fetchMyAccessRequests()
		},
	},
}
</script>

<style scoped>
.pq-access > * + *,
.pq-access__form > * + * {
	margin-block-start: var(--utrecht-space-block-md, 1rem);
}

.pq-e-error {
	color: var(--utrecht-feedback-danger-color, var(--nldesign-color-error, currentcolor));
	font-weight: var(--utrecht-typography-weight-scale-bold-font-weight, bold);
}
</style>
