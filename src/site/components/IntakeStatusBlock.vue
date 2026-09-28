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
	</section>
</template>

<script>
import { authBaseFrom } from '../lib/authApi.js'
import { resolveApiBase } from '../lib/contentApi.js'
import { lookUpStatus, statusView } from '../lib/intakeApi.js'

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
	},

	data() {
		return {
			reference: '',
			view: null,
			busy: false,
			inputId: 'pq-intake-status-reference',
		}
	},

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
			} catch {
				this.view = statusView(null)
			} finally {
				this.busy = false
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
