<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<form
		class="pq-form"
		data-testid="site-form"
		:data-portaliq-form="formId || 'form'"
		novalidate
		@submit.prevent="submit">
		<p v-if="!fields.length" class="utrecht-paragraph pq-form__empty">
			{{ emptyLabel }}
		</p>

		<template v-else>
			<p
				v-if="explainOptional"
				class="utrecht-paragraph pq-form__note"
				data-testid="form-optional-note">
				{{ text.optionalNote }}
			</p>

			<ErrorSummary
				ref="summary"
				:entries="summary"
				:idBase="`pq-form-${formId || 'x'}-summary`"
				:heading="text.summaryHeading"
				:intro="text.summaryIntro"
				:titlePrefix="text.titlePrefix" />

			<FieldShell
				v-for="field in fields"
				:id="fieldElementId(field)"
				:key="field.id"
				v-slot="{ describedBy }"
				class="pq-form__field"
				:label="field.label"
				:required="!!field.required"
				:optionalLabel="text.optional"
				:help="field.type === 'date' ? text.dateHint : ''"
				:error="errors[field.id] || ''"
				:group="field.type === 'date'"
				:errorTestid="`form-field-error-${field.id}`">
				<DateInputGroup
					v-if="field.type === 'date'"
					:id="fieldElementId(field)"
					v-model="values[field.id]"
					:required="!!field.required"
					:invalid="!!errors[field.id]"
					:testid="`form-field-${field.id}`" />

				<select
					v-else-if="field.type === 'select'"
					:id="fieldElementId(field)"
					v-model="values[field.id]"
					class="utrecht-select"
					:aria-required="field.required ? 'true' : undefined"
					:aria-invalid="errors[field.id] ? 'true' : undefined"
					:aria-describedby="describedBy"
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
					:aria-required="field.required ? 'true' : undefined"
					:aria-invalid="errors[field.id] ? 'true' : undefined"
					:aria-describedby="describedBy"
					:data-testid="`form-field-${field.id}`" />

				<input
					v-else
					:id="fieldElementId(field)"
					v-model="values[field.id]"
					class="utrecht-textbox"
					:type="inputType(field)"
					:aria-required="field.required ? 'true' : undefined"
					:aria-invalid="errors[field.id] ? 'true' : undefined"
					:aria-describedby="describedBy"
					:data-testid="`form-field-${field.id}`" />
			</FieldShell>

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
import DateInputGroup from './forms/DateInputGroup.vue'
import ErrorSummary from './forms/ErrorSummary.vue'
import FieldShell from './forms/FieldShell.vue'
import {
	capturedReferrer,
	captureLanding,
	firstTouch,
	lastTouch,
} from '../lib/campaignTracking.js'
import { submitLandingPageForm } from '../lib/formSubmission.js'
import {
	DUTCH,
	explainsOptional,
	plainFieldErrors,
	summaryEntries,
} from './forms/fields.js'

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

	components: { DateInputGroup, ErrorSummary, FieldShell },

	props: {
		/** The bound form's own id. Not sent as a value (the anonymous action's server-stamped `defaults` are the source of truth for `formId`), but it names the form's create action (`?actionId=submit-{formId}`), and it is what the traffic client reports form analytics under, through `data-portaliq-form` (portal-traffic-outcomes). */
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
			errors: {},
			submitting: false,
			status: null,
		}
	},

	computed: {
		/**
		 * The layer's Dutch words.
		 *
		 * @return {object} The words.
		 */
		text() {
			return DUTCH
		},

		/**
		 * Whether the form explains "(niet verplicht)": only when it mixes
		 * required and optional fields.
		 *
		 * @return {boolean} True to show the sentence.
		 *
		 * @spec openspec/changes/site-multi-step-forms/specs/site-forms/spec.md#requirement-a-site-form-must-mark-the-fields-that-are-not-required-req-smf-001
		 */
		explainOptional() {
			return explainsOptional(this.fields.map((field) => !!field.required))
		},

		/**
		 * The error summary's lines, in field order.
		 *
		 * @return {Array<{field: string, target: string, message: string}>} The lines.
		 *
		 * @spec openspec/changes/site-multi-step-forms/specs/site-forms/spec.md#requirement-a-failed-submit-must-show-an-error-summary-that-takes-focus-req-smf-002
		 */
		summary() {
			return summaryEntries(
				this.fields.map((field) => field.id),
				this.errors,
				(id) => this.fieldElementId({ id }),
			)
		},
	},

	created() {
		captureLanding(this.portal)
	},

	methods: {
		/**
		 * @param {object} field One declared form field.
		 * @return {string} A stable element id for its label association.
		 *
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-public-page-may-embed-a-lead-capture-form-widget
		 */
		fieldElementId(field) {
			return `pq-form-field-${this.formId || 'x'}-${field.id}`
		},

		/**
		 * @param {object} field One declared form field.
		 * @return {string} The `<input type>` to use — text-family types pass
		 *  through, anything else (e.g. an author typo) degrades to `text`
		 *  rather than rendering a browser-native control nobody asked for.
		 *
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-public-page-may-embed-a-lead-capture-form-widget
		 */
		inputType(field) {
			const KNOWN = ['text', 'email', 'tel', 'number', 'date', 'url']
			return KNOWN.includes(field.type) ? field.type : 'text'
		},

		/**
		 * Submit the collected values plus the client-observed UTM/referrer
		 * attribution, through this form's own create action.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/landing-page-provisioning/spec.md#requirement-a-landing-pages-form-is-submittable-with-no-portal-session
		 * @spec openspec/changes/create-names-its-action/tasks.md#T3
		 */
		async submit() {
			this.status = null
			this.errors = plainFieldErrors(
				this.fields.map((field) => ({
					name: field.id,
					label: field.label,
					required: !!field.required,
					date: field.type === 'date',
				})),
				this.values,
			)
			if (Object.keys(this.errors).length > 0) {
				this.$nextTick(() => {
					if (this.$refs.summary) {
						this.$refs.summary.focus()
					}
				})
				return
			}

			this.submitting = true

			try {
				await submitLandingPageForm(
					this.values,
					{
						utmFirstTouch: firstTouch(this.portal),
						utmLastTouch: lastTouch(this.portal),
						referrer: capturedReferrer(this.portal),
					},
					this.formId,
				)
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
.pq-form__note {
	margin-block-end: var(--utrecht-space-block-md, 1rem);
}

.pq-form__field + .pq-form__field {
	margin-block-start: var(--utrecht-space-block-md, 1rem);
}

.pq-form__consent,
.pq-form__status {
	margin-block-start: var(--utrecht-space-block-md, 1rem);
}
</style>
