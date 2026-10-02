<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<section class="pq-intake-form" data-testid="intake-form">
		<p v-if="state === 'loading'" class="utrecht-paragraph" role="status">
			{{ loadingLabel }}
		</p>

		<p
			v-else-if="state === 'notFound'"
			class="utrecht-paragraph"
			data-testid="intake-form-not-found">
			{{ notFoundLabel }}
		</p>

		<p
			v-else-if="state === 'noForm'"
			class="utrecht-paragraph"
			data-testid="intake-form-no-form">
			{{ noFormLabel }}
		</p>

		<p
			v-else-if="state === 'signIn' && fee"
			class="utrecht-paragraph"
			data-testid="intake-form-sign-in-fee">
			{{ fee.signIn }}
		</p>

		<p
			v-else-if="state === 'signIn'"
			class="utrecht-paragraph"
			data-testid="intake-form-sign-in">
			{{ signInLabel }}
		</p>

		<div v-else-if="state === 'external'" data-testid="intake-form-external">
			<p class="utrecht-paragraph">
				{{ externalLabel }}
				<strong>{{ render.destination }}</strong>
			</p>
			<a
				class="utrecht-button-link utrecht-button-link--primary-action"
				:href="render.externalUrl"
				rel="noopener"
				data-testid="intake-form-external-start">
				{{ startLabel }}
			</a>
		</div>

		<div
			v-else-if="state === 'done'"
			data-testid="intake-form-done"
			role="status">
			<p class="utrecht-paragraph">
				{{ confirmationText || doneLabel }}
			</p>
			<p class="utrecht-paragraph">
				{{ referenceLabel }}
				<strong data-testid="intake-form-reference">{{ reference }}</strong>
			</p>
			<p class="utrecht-paragraph">
				{{ keepReferenceLabel }}
			</p>
			<template v-if="fee">
				<p class="utrecht-paragraph" data-testid="intake-form-fee">
					{{ fee.costs }}
				</p>
				<button
					type="button"
					class="utrecht-button utrecht-button--primary-action"
					:disabled="paying"
					data-testid="intake-form-pay"
					@click="pay">
					{{ fee.pay }}
				</button>
				<p
					v-if="payFailed"
					class="utrecht-paragraph pq-intake-form__error"
					data-testid="intake-form-pay-error"
					role="alert">
					{{ payFailedLabel }}
				</p>
			</template>
		</div>

		<form
			v-else-if="state === 'form'"
			class="pq-intake-form__form"
			novalidate
			@submit.prevent="submit">
			<h2 v-if="render.formName && !hideFormName" class="utrecht-heading-2">
				{{ render.formName }}
			</h2>

			<div
				v-for="field in fields"
				:key="field.name"
				class="pq-intake-form__field">
				<label :for="elementId(field)" class="utrecht-form-label">
					{{ field.label || field.name }}
					<span v-if="field.required" aria-hidden="true">*</span>
				</label>

				<select
					v-if="Array.isArray(field.options) && field.options.length"
					:id="elementId(field)"
					v-model="values[field.name]"
					class="utrecht-select"
					:required="field.required === true"
					:aria-invalid="errors[field.name] ? 'true' : 'false'"
					:aria-describedby="
						errors[field.name] ? `${elementId(field)}-error` : undefined
					"
					:data-testid="`intake-field-${field.name}`">
					<option value="" disabled>
						{{ selectPlaceholder }}
					</option>
					<option
						v-for="option in field.options"
						:key="String(option.value ?? option)"
						:value="String(option.value ?? option)">
						{{ option.label ?? option }}
					</option>
				</select>

				<textarea
					v-else-if="field.type === 'textarea'"
					:id="elementId(field)"
					v-model="values[field.name]"
					class="utrecht-textarea"
					:required="field.required === true"
					:aria-invalid="errors[field.name] ? 'true' : 'false'"
					:aria-describedby="
						errors[field.name] ? `${elementId(field)}-error` : undefined
					"
					:data-testid="`intake-field-${field.name}`" />

				<input
					v-else
					:id="elementId(field)"
					v-model="values[field.name]"
					class="utrecht-textbox"
					:type="inputType(field)"
					:required="field.required === true"
					:aria-invalid="errors[field.name] ? 'true' : 'false'"
					:aria-describedby="
						errors[field.name] ? `${elementId(field)}-error` : undefined
					"
					:data-testid="`intake-field-${field.name}`" />

				<p
					v-if="errors[field.name]"
					:id="`${elementId(field)}-error`"
					class="utrecht-form-field-error-message"
					:data-testid="`intake-field-error-${field.name}`">
					{{ errors[field.name] }}
				</p>
			</div>

			<button
				type="submit"
				class="utrecht-button utrecht-button--primary-action"
				:disabled="submitting"
				data-testid="intake-form-submit">
				{{ submitLabel }}
			</button>

			<p
				v-if="sendFailed"
				class="utrecht-paragraph pq-intake-form__error"
				data-testid="intake-form-error"
				role="alert">
				{{ errorLabel }}
			</p>
		</form>
	</section>
</template>

<script>
import { adoptSessionToken, authBaseFrom } from '../lib/authApi.js'
import { resolveApiBase } from '../lib/contentApi.js'
import {
	bindingRouteFrom,
	challengeProof,
	feeText,
	initialValues,
	loadForm,
	payIntake,
	submitIntake,
} from '../lib/intakeApi.js'
import { shownAnswers, shownFields } from '../lib/intakeVisibility.js'

/**
 * The form a catalogue entry starts, rendered on a portal page
 * (portal-intake-form-as-an-object, REQ-PIFO-003, -005, -006).
 *
 * The binding route comes from the catalogue link (the trailing segment the
 * host hands the page as `routeParam`) or from the author, who may pin one
 * form to its own page with `route`. The form is fetched with the visitor's
 * bearer when they are signed in, so their own details arrive prefilled; an
 * anonymous visitor gets no prefill. A valid submission answers with a
 * reference at once, before the case exists.
 */
export default {
	name: 'IntakeFormBlock',

	props: {
		/** The serving portal's slug. Supplied by the host, never authored. */
		portal: {
			type: String,
			default: '',
		},

		/** The trailing route segment the host hands the page. Host supplied. */
		routeParam: {
			type: String,
			default: '',
		},

		/** A binding route to pin this block to, instead of the catalogue link. */
		route: {
			type: String,
			default: '',
		},

		/** Leave out the form's own name as the heading. */
		hideFormName: {
			type: Boolean,
			default: false,
		},

		/** The submit button's label. */
		submitLabel: {
			type: String,
			default: 'Versturen',
		},

		/** Shown while the form loads. */
		loadingLabel: {
			type: String,
			default: 'Formulier laden…',
		},

		/** Shown when no form is bound to the route. */
		notFoundLabel: {
			type: String,
			default:
				'Dit formulier bestaat niet (meer). Kies een aanvraag uit de lijst.',
		},

		/** Shown when the binding resolves to no published form. */
		noFormLabel: {
			type: String,
			default:
				'Dit formulier is nu niet beschikbaar. Probeer het later opnieuw.',
		},

		/** Shown when the form asks for a sign-in the visitor does not have. */
		signInLabel: {
			type: String,
			default:
				'Voor dit formulier moet u eerst inloggen. Log in en open het formulier opnieuw.',
		},

		/** Shown before the destination of an externally hosted form. */
		externalLabel: {
			type: String,
			default: 'U vult dit formulier in op de website van',
		},

		/** The button to an externally hosted form. */
		startLabel: {
			type: String,
			default: 'Naar het formulier',
		},

		/** Shown after sending when the form carries no confirmation of its own. */
		doneLabel: {
			type: String,
			default: 'Bedankt, wij hebben uw aanvraag ontvangen.',
		},

		/** Shown before the reference. */
		referenceLabel: {
			type: String,
			default: 'Uw kenmerk:',
		},

		/** Shown after the reference. */
		keepReferenceLabel: {
			type: String,
			default:
				'Bewaar dit kenmerk. Hiermee kunt u later zien hoe het met uw aanvraag staat.',
		},

		/** Shown when sending failed. */
		errorLabel: {
			type: String,
			default: 'Versturen is niet gelukt. Probeer het later opnieuw.',
		},

		/** The first, empty choice of a list. */
		selectPlaceholder: {
			type: String,
			default: 'Maak een keuze',
		},

		/** Shown when the payment could not start. */
		payFailedLabel: {
			type: String,
			default: 'U kunt nu niet betalen. Probeer het later opnieuw.',
		},
	},

	data() {
		return {
			state: 'loading',
			render: {},
			values: {},
			errors: {},
			submitting: false,
			sendFailed: false,
			reference: '',
			confirmationText: '',
			paying: false,
			payFailed: false,
		}
	},

	computed: {
		/**
		 * The binding route this block renders: the author's, else the link's.
		 *
		 * @return {string} The binding route, or ''.
		 *
		 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md#requirement-the-citizens-entry-point-is-composed-content-listing-the-published-catalogue-req-pifo-006
		 */
		bindingRoute() {
			return bindingRouteFrom(this.routeParam, this.route)
		},

		/**
		 * The sentences of the fee the case type declares, or null.
		 *
		 * @return {{costs: string, pay: string, signIn: string}|null} The sentences.
		 *
		 * @spec openspec/changes/intake-pay-on-submit/specs/portal-intake-payment/spec.md#requirement-a-fee-bearing-form-asks-the-visitor-to-sign-in-first-req-ips-002
		 */
		fee() {
			return feeText(this.render.fee)
		},

		/**
		 * The rendered fields a resident sees for the answers so far: a field
		 * shows only while its condition holds.
		 *
		 * @return {Array<object>} The fields.
		 *
		 * @spec openspec/changes/intake-conditional-questions-and-drafts/specs/portal-intake-form/spec.md#requirement-a-fields-condition-decides-whether-the-resident-sees-it-req-icq-001
		 */
		fields() {
			return shownFields(this.render.fields, this.values)
		},
	},

	watch: {
		/**
		 * Load the other form when the catalogue link changes under the page.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md#requirement-the-citizens-entry-point-is-composed-content-listing-the-published-catalogue-req-pifo-006
		 */
		bindingRoute() {
			this.load()
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Load the form the binding route resolves to right now.
		 *
		 * @return {Promise<void>} Resolves when loaded.
		 *
		 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md#requirement-the-applicant-block-is-prefilled-from-the-signed-in-identity-only-req-pifo-003
		 */
		async load() {
			this.state = 'loading'
			this.reference = ''
			this.errors = {}
			this.sendFailed = false
			if (this.bindingRoute === '') {
				this.state = 'notFound'
				return
			}

			try {
				const view = await loadForm(
					authBaseFrom(resolveApiBase()),
					this.bindingRoute,
					this.portal,
					adoptSessionToken(),
				)
				this.render = view.render
				this.values = initialValues(view.render.fields, view.render.prefill)
				this.state = view.state
			} catch {
				this.state = 'notFound'
			}
		},

		/**
		 * Send the answers of the shown fields, with the solved challenge when
		 * the form carries one, and show the reference.
		 *
		 * @return {Promise<void>} Resolves when answered.
		 *
		 * @spec openspec/changes/intake-conditional-questions-and-drafts/specs/portal-intake-form/spec.md#requirement-a-bound-form-can-be-filled-in-on-a-site-page-req-icq-004
		 */
		async submit() {
			this.submitting = true
			this.sendFailed = false
			this.errors = {}
			try {
				const outcome = await submitIntake(
					authBaseFrom(resolveApiBase()),
					this.bindingRoute,
					shownAnswers(this.render.fields, this.values),
					this.portal,
					adoptSessionToken(),
					null,
					await challengeProof(this.render.challenge),
				)
				if (outcome.reference === '') {
					this.errors = outcome.errors
					return
				}

				this.reference = outcome.reference
				this.confirmationText = outcome.confirmationText
				this.state = 'done'
			} catch {
				this.sendFailed = true
			} finally {
				this.submitting = false
			}
		},

		/**
		 * Start paying the fee and leave for the checkout. The top window, so
		 * an embedded form does not open a payment page inside a frame.
		 *
		 * @return {Promise<void>} Resolves when answered.
		 *
		 * @spec openspec/changes/intake-pay-on-submit/specs/portal-intake-payment/spec.md#requirement-the-portal-redirects-only-to-a-declared-payment-host-req-ips-004
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

		/**
		 * The element id of a field's control.
		 *
		 * @param {object} field The field.
		 * @return {string} The id.
		 *
		 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md#requirement-the-citizens-entry-point-is-composed-content-listing-the-published-catalogue-req-pifo-006
		 */
		elementId(field) {
			return `pq-intake-field-${field.name}`
		},

		/**
		 * The input type for a field, text when the declared type is not a known one.
		 *
		 * @param {object} field The field.
		 * @return {string} The input type.
		 *
		 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md#requirement-the-citizens-entry-point-is-composed-content-listing-the-published-catalogue-req-pifo-006
		 */
		inputType(field) {
			const KNOWN = ['text', 'email', 'tel', 'number', 'date', 'url']
			return KNOWN.includes(field.type) ? field.type : 'text'
		},
	},
}
</script>

<style scoped>
.pq-intake-form__field + .pq-intake-form__field,
.pq-intake-form__form > button,
.pq-intake-form__error {
	margin-block-start: var(--utrecht-space-block-md, 1rem);
}
</style>
