<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<section
		class="container pq-guest"
		data-testid="guest-action-page"
		:aria-busy="phase === 'loading' ? 'true' : 'false'">
		<h1 class="utrecht-heading-1">
			{{ strings.title }}
		</h1>

		<p v-if="phase === 'loading'" role="status" data-testid="guest-loading">
			{{ strings.loading }}
		</p>

		<template v-else>
			<p
				v-if="state.summary"
				class="utrecht-paragraph"
				data-testid="guest-summary">
				{{ state.summary }}
			</p>

			<div
				v-if="outcome"
				:role="outcome.kind === 'refused' ? 'alert' : 'status'"
				data-testid="guest-outcome">
				<p class="utrecht-paragraph">
					{{ outcome.message }}
				</p>
			</div>

			<div v-else-if="!state.usable" role="alert" data-testid="guest-reason">
				<p class="utrecht-paragraph">
					{{ state.reason }}
				</p>
			</div>

			<form v-else data-testid="guest-form" @submit.prevent="confirming = true">
				<div
					v-for="field in state.fields"
					:key="field"
					class="utrecht-form-field">
					<label class="utrecht-form-label" :for="`pq-guest-${field}`">
						{{ labelOf(field) }}
					</label>
					<input
						:id="`pq-guest-${field}`"
						v-model="values[field]"
						type="text"
						class="utrecht-textbox"
						:data-testid="`guest-field-${field}`">
				</div>

				<button
					v-if="!confirming"
					type="submit"
					class="utrecht-button utrecht-button--primary-action"
					data-testid="guest-act">
					{{ state.label }}
				</button>

				<div v-else role="group" data-testid="guest-confirm">
					<p class="utrecht-paragraph">
						{{ state.confirmText }}
					</p>
					<button
						type="button"
						class="utrecht-button utrecht-button--primary-action"
						:disabled="busy"
						data-testid="guest-confirm-yes"
						@click="act">
						{{ strings.confirmYes }}
					</button>
					<button
						type="button"
						class="utrecht-button utrecht-button--subtle"
						:disabled="busy"
						@click="confirming = false">
						{{ strings.cancel }}
					</button>
				</div>
			</form>

			<p class="utrecht-paragraph" aria-live="polite" data-testid="guest-busy">
				{{ busy ? strings.busy : '' }}
			</p>
		</template>
	</section>
</template>

<script>
import {
	actOutcome,
	guestAct,
	guestPreview,
	guestStrings,
	previewState,
	takeGuestLink,
} from '../lib/guestAction.js'

/**
 * The page a signed link opens (identity-guest-page-for-signed-links T04):
 * the contributing app's summary, its reason and no button when the act is
 * no longer possible, otherwise the declared fields, the declared button, a
 * confirmation, and the answer. No account and no session.
 *
 * @spec openspec/changes/identity-guest-page-for-signed-links/specs/portal-guest-actions/spec.md#requirement-the-page-shows-the-apps-answer-req-gst-004
 */
export default {
	name: 'GuestActionPage',

	props: {
		/** The portal API base (`.../portal/api`). */
		authBase: {
			type: String,
			required: true,
		},

		/** The serving portal's slug, or ''. */
		portal: {
			type: String,
			default: '',
		},
	},

	data() {
		const strings = guestStrings(document.documentElement.lang)
		return {
			strings,
			link: null,
			phase: 'loading',
			state: previewState({ ok: false, status: 0, body: {} }, strings),
			declaration: {},
			values: {},
			confirming: false,
			busy: false,
			outcome: null,
		}
	},

	/**
	 * Read the link once, then ask what it is for.
	 *
	 * @spec openspec/changes/identity-guest-page-for-signed-links/tasks.md#T03
	 */
	async mounted() {
		this.link = takeGuestLink(window.location, window.history)
		if (this.link === null) {
			this.phase = 'ready'
			return
		}

		const answer = await guestPreview(this.authBase, this.link, this.portal)
		this.state = previewState(answer, this.strings)
		this.declaration = (answer.ok && answer.body && answer.body.action) || {}
		this.phase = 'ready'
	},

	methods: {
		/**
		 * The label of a declared field.
		 *
		 * @param {string} field The field.
		 * @return {string} The declared label, else the field name.
		 */
		labelOf(field) {
			const config = this.state.fieldConfigs[field]
			return (config && typeof config.label === 'string' && config.label) || field
		},

		/**
		 * Do the confirmed act and show, or follow, the answer.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/identity-guest-page-for-signed-links/specs/portal-guest-actions/spec.md#requirement-the-page-shows-the-apps-answer-req-gst-004
		 */
		async act() {
			this.busy = true
			const answer = await guestAct(this.authBase, this.link, this.values, this.portal)
			this.outcome = actOutcome(answer, this.declaration, this.strings)
			this.busy = false
			if (this.outcome.kind === 'redirect') {
				window.location.assign(this.outcome.url)
			}
		},
	},
}
</script>
