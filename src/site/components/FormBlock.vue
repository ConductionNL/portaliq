<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<form class="pq-form" data-testid="site-form" @submit.prevent="submit">
		<p v-if="!fields.length" class="utrecht-paragraph pq-form__empty">
			{{ emptyLabel }}
		</p>

		<template v-else>
			<div v-for="field in fields" :key="field.id" class="pq-form__field">
				<label :for="fieldElementId(field)" class="utrecht-form-label">
					{{ field.label }}
					<span v-if="field.required" aria-hidden="true">*</span>
				</label>

				<select
					v-if="field.type === 'select'"
					:id="fieldElementId(field)"
					v-model="values[field.id]"
					class="utrecht-select"
					:required="!!field.required"
					:data-testid="`form-field-${field.id}`">
					<option value="" disabled>{{ selectPlaceholder }}</option>
					<option
						v-for="option in field.options || []"
						:key="option"
						:value="option">
						{{ option }}
					</option>
				</select>

				<textarea
					v-else-if="field.type === 'textarea'"
					:id="fieldElementId(field)"
					v-model="values[field.id]"
					class="utrecht-textarea"
					:required="!!field.required"
					:data-testid="`form-field-${field.id}`" />

				<input
					v-else
					:id="fieldElementId(field)"
					v-model="values[field.id]"
					class="utrecht-textbox"
					:type="inputType(field)"
					:required="!!field.required"
					:data-testid="`form-field-${field.id}`" />
			</div>

			<p v-if="consentText" class="utrecht-paragraph pq-form__consent">
				{{ consentText }}
			</p>

			<button
				type="submit"
				class="utrecht-button utrecht-button--primary-action"
				:disabled="submitting"
				data-testid="form-submit">
				{{ submitLabel }}
			</button>

			<p
				v-if="status === 'success'"
				class="utrecht-paragraph pq-form__status pq-form__status--success"
				data-testid="form-status-success"
				role="status">
				{{ successLabel }}
			</p>
			<p
				v-if="status === 'error'"
				class="utrecht-paragraph pq-form__status pq-form__status--error"
				data-testid="form-status-error"
				role="alert">
				{{ errorLabel }}
			</p>
		</template>
	</form>
</template>

<script>
import {
	capturedReferrer,
	captureLanding,
	firstTouch,
	lastTouch,
} from '../lib/campaignTracking.js'
import { submitLandingPageForm } from '../lib/formSubmission.js'

/**
 * Renders a landing page's bound lead-capture form and submits it through
 * Portaliq's EXISTING anonymous contribution-create endpoint — no new HTTP
 * route (landing-page-provisioning).
 *
 * WHY THIS BLOCK KNOWS NOTHING ABOUT THE FORM'S OWN FIELDS UNTIL RENDER TIME.
 *
 * The `fields`/`submitLabel`/`consentText` props are the AUTHORED shape the
 * `form` object created by `LandingPageRequestedEvent` carries — the host
 * (`WidgetGrid`/`App.vue`) resolves the bound `form` object by the widget's
 * `formId` prop and hands its declared shape down, the same "host supplies
 * the data, author supplies the wording" split `ContributionsBlock`/
 * `PublicationDetailBlock` already establish in this file's sibling
 * components. This block itself never fetches.
 *
 * UTM CAPTURE runs on mount (`captureLanding`), scoped to THIS portal, so a
 * visitor arriving with `?utm_campaign=...` gets it recorded even if they
 * never touch the form — first/last touch and referrer are only READ back
 * at submit time.
 */
export default {
	name: 'FormBlock',

	props: {
		/** The bound form's own id (for diagnostics; not sent to the server — the anonymous action's server-stamped `defaults` are the source of truth for `formId`). */
		formId: {
			type: String,
			default: '',
		},

		/** The serving portal's slug — scopes the UTM capture to this portal. */
		portal: {
			type: String,
			default: '',
		},

		/** The bound form's declared fields, in submission order. */
		fields: {
			type: Array,
			default: () => [],
		},

		/** The submit button's label. */
		submitLabel: {
			type: String,
			default: 'Versturen',
		},

		/** Shown above the submit button. */
		consentText: {
			type: String,
			default: '',
		},

		/** Shown when the bound form declares no fields (a misconfigured placement, not a hidden block — see ContributionsBlock's own "always render" rule). */
		emptyLabel: {
			type: String,
			default: 'Dit formulier is niet beschikbaar.',
		},

		/** Shown after a successful submission. */
		successLabel: {
			type: String,
			default: 'Bedankt, uw inzending is ontvangen.',
		},

		/** Shown after a failed submission. */
		errorLabel: {
			type: String,
			default: 'Versturen is niet gelukt. Probeer het later opnieuw.',
		},

		/** The select field's initial (unselected) option label. */
		selectPlaceholder: {
			type: String,
			default: 'Maak een keuze',
		},
	},

	data() {
		return {
			values: {},
			submitting: false,
			status: null,
		}
	},

	created() {
		captureLanding(this.portal)
	},

	methods: {
		/**
		 * @param {object} field One declared form field.
		 * @return {string} A stable element id for its label association.
		 */
		fieldElementId(field) {
			return `pq-form-field-${this.formId || 'x'}-${field.id}`
		},

		/**
		 * @param {object} field One declared form field.
		 * @return {string} The `<input type>` to use — text-family types pass
		 *  through, anything else (e.g. an author typo) degrades to `text`
		 *  rather than rendering a browser-native control nobody asked for.
		 */
		inputType(field) {
			const KNOWN = ['text', 'email', 'tel', 'number', 'date', 'url']
			return KNOWN.includes(field.type) ? field.type : 'text'
		},

		/**
		 * Submit the collected values plus the client-observed UTM/referrer
		 * attribution.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/landing-page-provisioning/spec.md#requirement-a-landing-pages-form-is-submittable-with-no-portal-session
		 */
		async submit() {
			this.submitting = true
			this.status = null

			try {
				await submitLandingPageForm(this.values, {
					utmFirstTouch: firstTouch(this.portal),
					utmLastTouch: lastTouch(this.portal),
					referrer: capturedReferrer(this.portal),
				})
				this.status = 'success'
				this.values = {}
			} catch {
				this.status = 'error'
			} finally {
				this.submitting = false
			}
		},
	},
}
</script>

<style scoped>
.pq-form__field + .pq-form__field {
	margin-block-start: var(--utrecht-space-block-md, 1rem);
}

.pq-form__consent,
.pq-form__status {
	margin-block-start: var(--utrecht-space-block-md, 1rem);
}
</style>
