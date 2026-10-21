<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	The asker's side of an access request on the site (identity-access-requests
	T04, T05), ported from the React portal's AccessRequestsPage.jsx. A
	signed-in user asks for access to the cases of a company or person they act
	for, giving a reason, and follows every request they made: waiting,
	granted, or refused with the reason the organisation gave.
-->
<template>
	<section
		class="pq-access"
		aria-labelledby="pq-access-title"
		data-testid="access-requests">
		<!-- The page title: the shell leaves its own h1 out for this page
		     (OWNS_HEADING in pages/registry.js). -->
		<h1 id="pq-access-title" class="utrecht-heading-2">
			{{ t('Access to cases') }}
		</h1>
		<p class="utrecht-paragraph">
			{{
				t(
					'Who may arrange matters for you, and for whom you arrange matters.',
				)
			}}
		</p>

		<!-- Board Machtigingen (site-mandates-the-represented-manage T4): who
		     may act for you, and on whose behalf you work. -->
		<MandatesGiven :api="api" :t="t" :portal="portal" :locale="locale" />
		<MandatesHeld :api="api" :t="t" :portal="portal" :locale="locale" />

		<h2 class="utrecht-heading-3">
			{{ t('Ask for access') }}
		</h2>
		<p class="utrecht-paragraph">
			{{
				t(
					'Ask for access to the cases of a company or person you act for. The organisation answers your request.',
				)
			}}
		</p>

		<form class="pq-access__form" novalidate @submit.prevent="send">
			<div class="utrecht-form-field">
				<label for="access-request-party" class="utrecht-form-label">
					{{ t('Whose cases do you need access to?') }}
				</label>
				<div
					id="access-request-party-help"
					class="utrecht-form-field-description">
					{{ t('For example the name or the KvK number of a company') }}
				</div>
				<input
					id="access-request-party"
					v-model="draft.onBehalfOf"
					class="utrecht-textbox"
					name="onBehalfOf"
					aria-describedby="access-request-party-help"
					data-testid="access-request-party" />
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
					rows="3"
					data-testid="access-request-reason" />
			</div>

			<p
				v-if="problem !== ''"
				class="utrecht-paragraph pq-e-error"
				role="alert"
				data-testid="access-request-problem">
				{{ t(problem) }}
			</p>
			<p
				v-if="notice !== ''"
				class="utrecht-paragraph"
				role="status"
				data-testid="access-request-notice">
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

		<h3 class="utrecht-heading-4">
			{{ t('Your requests') }}
		</h3>
		<p v-if="loading" class="utrecht-paragraph" role="status">
			{{ t('Loading…') }}
		</p>
		<p
			v-else-if="sorted.length === 0"
			class="utrecht-paragraph"
			data-testid="access-requests-empty">
			{{ t('You have not asked for access yet.') }}
		</p>
		<ul v-else class="pq-access__list" data-testid="access-requests-list">
			<li
				v-for="(request, index) in sorted"
				:key="request.id || request.uuid || `request-${index}`"
				class="pq-access__request"
				data-testid="access-request-row"
				:data-state="request.state || 'pending'">
				<strong class="pq-access__party">{{
					t('For {party}', { party: request.onBehalfOf || '' })
				}}</strong>
				<span
					class="pq-access__pill"
					:class="`pq-access__pill--${request.state || 'pending'}`"
					data-testid="access-request-state"
					>{{ t(stateLabel(request.state)) }}</span
				>
				<span v-if="askedOn(request)" class="pq-access__asked">{{
					t('Asked on {date}', { date: askedOn(request) })
				}}</span>
				<p
					v-if="request.state === 'refused' && request.decisionReason"
					class="utrecht-paragraph">
					{{ t('Reason: {reason}', { reason: request.decisionReason }) }}
				</p>
			</li>
		</ul>

		<section
			class="utrecht-alert utrecht-alert--info pq-access__digid"
			aria-labelledby="pq-access-digid"
			data-testid="access-digid-machtigen">
			<svg
				class="pq-access__info"
				viewBox="0 0 20 20"
				aria-hidden="true"
				focusable="false">
				<circle
					cx="10"
					cy="10"
					r="8"
					fill="none"
					stroke="currentColor"
					stroke-width="1.5" />
				<path
					d="M10 9v5M10 6v.5"
					stroke="currentColor"
					stroke-width="1.75"
					stroke-linecap="round" />
			</svg>
			<div class="utrecht-alert__content">
				<h2 id="pq-access-digid" class="pq-access__digid-title">
					{{ t('Mandates through DigiD Machtigen') }}
				</h2>
				<p class="utrecht-paragraph">
					{{
						t(
							'Did you authorise someone through DigiD Machtigen? You do not see that mandate here. You arrange and view it on digid.nl.',
						)
					}}
				</p>
			</div>
		</section>
	</section>
</template>

<script>
import MandatesGiven from '../../components/e/MandatesGiven.vue'
import MandatesHeld from '../../components/e/MandatesHeld.vue'
import {
	newestFirst,
	requestProblem,
	sendProblem,
	stateLabel,
} from './accessRequests.js'
import { longDate, readerLocale } from './format.js'

import '@utrecht/alert-css/dist/index.css'
import '@utrecht/form-field-description-css/dist/index.css'

export default {
	name: 'AccessRequestsPage',

	components: { MandatesGiven, MandatesHeld },

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
			return request.requestedAt
				? longDate(request.requestedAt, readerLocale(this.locale))
				: ''
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
			const outcome = await this.api.requestAccess(
				this.draft.onBehalfOf.trim(),
				this.draft.reason.trim(),
			)
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

.pq-access__form .utrecht-textbox,
.pq-access__form .utrecht-textarea {
	inline-size: 100%;
	max-inline-size: 32.5rem;
}

/* Each request a card: who it is for and its state as a pill on the first
   line, the day it was asked under it. */
.pq-access__list {
	display: grid;
	gap: var(--utrecht-space-block-xs, 0.5rem);
	list-style: none;
	padding: 0;
}

.pq-access__request {
	align-items: center;
	border: 1px solid
		var(
			--nldesign-website-card-border-color,
			var(--nldesign-color-border, #d3d8df)
		);
	border-radius: var(--nldesign-website-border-radius, 4px);
	display: flex;
	flex-wrap: wrap;
	gap: var(--utrecht-space-block-3xs, 0.25rem) var(--utrecht-space-inline-md, 1rem);
	padding: 1rem 1.25rem;
}

.pq-access__party {
	flex: 1 1 auto;
}

.pq-access__asked,
.pq-access__request > .utrecht-paragraph {
	flex-basis: 100%;
	margin: 0;
}

.pq-access__pill {
	border-radius: var(--nldesign-website-badge-border-radius, 14px);
	font-size: 0.9375rem;
	font-weight: var(--utrecht-typography-weight-scale-bold, 600);
	line-height: 1.75rem;
	padding-inline: 0.75rem;
	white-space: nowrap;
}

.pq-access__pill--pending {
	background: var(
		--nldesign-component-status-badge-warning-background-color,
		#fff4de
	);
	color: var(--nldesign-component-status-badge-warning-color, #7a4f00);
}

.pq-access__pill--granted {
	background: var(
		--nldesign-component-status-badge-success-background-color,
		#e7f3ea
	);
	color: var(--nldesign-component-status-badge-success-color, #1e6b2a);
}

.pq-access__pill--refused {
	background: var(
		--nldesign-component-status-badge-error-background-color,
		#fcedec
	);
	color: var(--nldesign-component-status-badge-error-color, #a30000);
}

/* The note about DigiD Machtigen: an info alert with its icon, the title in
   the body size. */
.pq-access__digid {
	border: var(--nldesign-website-notice-border-width, 1px) solid
		var(--nldesign-website-notice-border-color, #b9cbe2);
	border-radius: var(--nldesign-website-border-radius, 4px);
	display: flex;
	gap: var(--utrecht-space-inline-sm, 0.75rem);
	margin-block-start: var(--utrecht-space-block-xl, 2rem);
}

.pq-access__info {
	block-size: 1.25rem;
	flex: none;
	inline-size: 1.25rem;
	margin-block-start: 0.125rem;
}

.pq-access__digid-title {
	font-size: 1rem;
	font-weight: var(--utrecht-typography-weight-scale-bold, 600);
	margin: 0;
}

.pq-e-error {
	color: var(
		--utrecht-feedback-danger-color,
		var(--nldesign-color-error, currentcolor)
	);
	font-weight: var(--utrecht-typography-weight-scale-bold-font-weight, bold);
}
</style>
