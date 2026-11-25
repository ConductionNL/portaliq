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
		<!-- "Hulp nodig?": the portal's help details, the form's own over them. -->
		<FormHelp
			v-if="offersHelp"
			:portalHelp="portalHelp"
			:formHelp="formHelp"
			:title="title" />
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
				:help="helpOf(field)"
				:error="errors[field.id] || ''"
				:group="isGroup(field)"
				:errorTestid="`form-field-error-${field.id}`">
				<!--
					A DATE WITH `picker` IS ONE CALENDAR CONTROL (design D1, Date
					Picker): the browser's own date input, bounded by the field's
					earliest and latest date, sending the same `yyyy-mm-dd` the
					day, month and year group sends.
					@spec openspec/changes/site-nlds-widget-palette/specs/portal-page-designer/spec.md#requirement-form-fields-must-be-added-inside-a-form-widget-req-snw-003
				-->
				<input
					v-if="isDatePicker(field)"
					:id="fieldElementId(field)"
					v-model="values[field.id]"
					type="date"
					class="utrecht-textbox utrecht-textbox--html-input pq-form__date-picker"
					:min="dateLimit(field.earliest) || undefined"
					:max="dateLimit(field.latest) || undefined"
					:required="!!field.required"
					:aria-invalid="errors[field.id] ? 'true' : undefined"
					:aria-describedby="describedBy"
					:data-testid="`form-field-${field.id}`" />

				<DateInputGroup
					v-else-if="field.type === 'date'"
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
					:aria-labelledby="`${fieldElementId(field)}-label`"
					:aria-describedby="describedBy"
					:data-testid="`form-field-${field.id}`">
					<option value="" disabled>{{ selectPlaceholder }}</option>
					<option
						v-for="option in field.options || []"
						:key="option"
						:value="option">
						{{ optionLabel(field, option) }}
					</option>
				</select>

				<!--
					A GROUP OF CHOICES IS A FIELDSET (FieldShell's group): the
					legend names the question and each option is its own
					labelled control, so a screen reader reads "Keuze A, 1 of 3"
					rather than a row of unnamed boxes. Radio is one answer,
					checkboxes are a list of answers.
					@spec openspec/changes/site-nlds-widget-palette/specs/portal-page-designer/spec.md#requirement-form-fields-must-be-added-inside-a-form-widget-req-snw-003
				-->
				<div
					v-else-if="field.type === 'radio' || field.type === 'checkboxes'"
					class="pq-form__choices"
					:data-testid="`form-field-${field.id}`">
					<div
						v-for="(option, index) in field.options || []"
						:key="option"
						class="utrecht-form-field pq-form__choice"
						:class="
							field.type === 'radio'
								? 'utrecht-form-field--radio'
								: 'utrecht-form-field--checkbox'
						">
						<input
							:id="`${fieldElementId(field)}-${index}`"
							:type="field.type === 'radio' ? 'radio' : 'checkbox'"
							:class="
								field.type === 'radio'
									? 'utrecht-radio-button'
									: 'utrecht-checkbox utrecht-checkbox--html-input'
							"
							:name="fieldElementId(field)"
							:value="option"
							:checked="isChosen(field, option)"
							:aria-invalid="errors[field.id] ? 'true' : undefined"
							:aria-describedby="describedBy"
							:data-testid="`form-field-${field.id}-${index}`"
							@change="choose(field, option, $event.target.checked)" />
						<label
							:for="`${fieldElementId(field)}-${index}`"
							class="utrecht-form-label pq-form__choice-label">
							{{ optionLabel(field, option) }}
						</label>
					</div>
				</div>

				<!-- One box, one statement: "ja" when ticked, nothing when not, so "required" means "must be ticked". -->
				<input
					v-else-if="field.type === 'checkbox' || field.type === 'switch'"
					:id="fieldElementId(field)"
					type="checkbox"
					:role="field.type === 'switch' ? 'switch' : undefined"
					:class="
						field.type === 'switch'
							? 'pq-form__switch'
							: 'utrecht-checkbox utrecht-checkbox--html-input'
					"
					:checked="values[field.id] === 'ja'"
					:aria-required="field.required ? 'true' : undefined"
					:aria-labelledby="`${fieldElementId(field)}-label`"
					:aria-invalid="errors[field.id] ? 'true' : undefined"
					:aria-describedby="describedBy"
					:data-testid="`form-field-${field.id}`"
					@change="values[field.id] = $event.target.checked ? 'ja' : ''" />

				<!-- A prefix or suffix ("€", "m²") reads with the input: it is in the label's description, not only beside the box. -->
				<div
					v-else-if="field.type === 'textAffixed'"
					class="pq-form__affixed">
					<span v-if="field.prefix" class="pq-form__affix" aria-hidden="true">{{ field.prefix }}</span>
					<input
						:id="fieldElementId(field)"
						v-model="values[field.id]"
						class="utrecht-textbox"
						type="text"
						:aria-required="field.required ? 'true' : undefined"
						:aria-invalid="errors[field.id] ? 'true' : undefined"
						:aria-labelledby="`${fieldElementId(field)}-label`"
						:aria-describedby="describedBy"
						:data-testid="`form-field-${field.id}`" />
					<span v-if="field.suffix" class="pq-form__affix" aria-hidden="true">{{ field.suffix }}</span>
				</div>

				<input
					v-else-if="field.type === 'range'"
					:id="fieldElementId(field)"
					v-model="values[field.id]"
					type="range"
					class="pq-form__range"
					:min="numberOr(field.min, 0)"
					:max="numberOr(field.max, 100)"
					:step="numberOr(field.step, 1)"
					:aria-required="field.required ? 'true' : undefined"
					:aria-labelledby="`${fieldElementId(field)}-label`"
					:aria-describedby="describedBy"
					:data-testid="`form-field-${field.id}`" />

				<textarea
					v-else-if="field.type === 'textarea'"
					:id="fieldElementId(field)"
					v-model="values[field.id]"
					:rows="numberOr(field.rows, undefined)"
					class="utrecht-textarea"
					:aria-required="field.required ? 'true' : undefined"
					:aria-invalid="errors[field.id] ? 'true' : undefined"
					:aria-labelledby="`${fieldElementId(field)}-label`"
					:aria-describedby="describedBy"
					:data-testid="`form-field-${field.id}`" />

				<input
					v-else
					:id="fieldElementId(field)"
					v-model="values[field.id]"
					class="utrecht-textbox"
					:type="inputType(field)"
					:min="field.type === 'number' ? numberOr(field.min, undefined) : undefined"
					:max="field.type === 'number' ? numberOr(field.max, undefined) : undefined"
					:step="field.type === 'number' ? numberOr(field.step, undefined) : undefined"
					:autocomplete="field.autocomplete || undefined"
					:aria-required="field.required ? 'true' : undefined"
					:aria-invalid="errors[field.id] ? 'true' : undefined"
					:aria-labelledby="`${fieldElementId(field)}-label`"
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
import { defineAsyncComponent } from 'vue'
import DateInputGroup from './forms/DateInputGroup.vue'
import ErrorSummary from './forms/ErrorSummary.vue'
import FieldShell from './forms/FieldShell.vue'
import {
	capturedReferrer,
	captureLanding,
	firstTouch,
	lastTouch,
} from '../lib/campaignTracking.js'
import {
	chosenList,
	chosenValue,
	dateLimit,
	dateRangeErrors,
	minimumErrors,
} from '../lib/formFieldValues.js'
import { submitLandingPageForm } from '../lib/formSubmission.js'
import { hasHelp, mergeHelp } from '../lib/help.js'
import {
	DUTCH,
	explainsOptional,
	optionLabel,
	plainFieldErrors,
	summaryEntries,
} from './forms/fields.js'

/**
 * The plain checks for the declared fields.
 *
 * @param {Array<object>} fields The declared form fields.
 * @return {Array<object>} One rule per field: name, label, required, date.
 */
function fieldRules(fields) {
	return fields.map((field) => ({
		name: field.id,
		label: field.label,
		required: !!field.required,
		date: field.type === 'date',
	}))
}

/**
 * The plain errors plus the checkbox groups' minimum (site-nlds-widget-palette
 * D1: a Checkbox Group declares how many must be ticked).
 *
 * @param {Array<object>} fields The declared form fields.
 * @param {object} values The values per field id.
 * @return {Record<string, string>} The message per field id.
 */
function formErrors(fields, values) {
	return {
		...plainFieldErrors(fieldRules(fields), values),
		...minimumErrors(fields, values),
		...dateRangeErrors(fields, values),
	}
}

/**
 * The campaign attribution this visitor arrived with on the portal.
 *
 * @param {string} portal The portal slug.
 * @return {{utmFirstTouch: object|null, utmLastTouch: object|null, referrer: string}} The tracking.
 */
function campaignOf(portal) {
	return {
		utmFirstTouch: firstTouch(portal),
		utmLastTouch: lastTouch(portal),
		referrer: capturedReferrer(portal),
	}
}

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

	components: {
		DateInputGroup,
		ErrorSummary,
		FieldShell,
		FormHelp: defineAsyncComponent(() => import('./FormHelp.vue')),
	},

	props: {
		/** The portal's help details; "Hulp nodig?" shows when there are any. */
		portalHelp: {
			type: Object,
			default: null,
		},

		/** The form's own help details; each key overrides the portal's. */
		formHelp: {
			type: Object,
			default: null,
		},

		/** The form's title, named in the help e-mail and the phone note. */
		title: {
			type: String,
			default: '',
		},

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
		 * @return {boolean} Whether the portal or the form has help details to offer.
		 * @spec openspec/changes/help-texts-and-form-help/specs/site-help-texts/spec.md#requirement-a-form-offers-help-without-losing-the-answers-req-htf-001
		 */
		offersHelp() {
			return hasHelp(mergeHelp(this.portalHelp, this.formHelp))
		},

		/**
		 * The layer's Dutch words.
		 *
		 * @return {object} The words.
		 *
		 * @spec openspec/changes/site-multi-step-forms/specs/site-forms/spec.md#requirement-a-site-form-must-mark-the-fields-that-are-not-required-req-smf-001
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

		optionLabel,

		/**
		 * The field's description: its own, else the date's example.
		 *
		 * @param {object} field One declared form field.
		 * @return {string} The help line.
		 *
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portal-page-designer/spec.md#requirement-form-fields-must-be-added-inside-a-form-widget-req-snw-003
		 */
		helpOf(field) {
			const own = typeof field.description === 'string' ? field.description.trim() : ''
			const affix = field.type === 'textAffixed'
				? [field.prefix, field.suffix].filter(Boolean).join(' – ')
				: ''
			if (own !== '') {
				return affix ? `${own} (${affix})` : own
			}
			if (affix) {
				return affix
			}
			return field.type === 'date' && !this.isDatePicker(field) ? this.text.dateHint : ''
		},

		dateLimit,

		/**
		 * Whether a date field is drawn as one calendar control.
		 *
		 * @param {object} field One declared form field.
		 * @return {boolean} True for a `date` with `picker`.
		 *
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portal-page-designer/spec.md#requirement-form-fields-must-be-added-inside-a-form-widget-req-snw-003
		 */
		isDatePicker(field) {
			return field.type === 'date' && field.picker === true
		},

		/**
		 * Whether the field is a group of inputs under one legend.
		 *
		 * @param {object} field One declared form field.
		 * @return {boolean} True for a date group, a radio group and checkboxes; a
		 *  date picker is one control with its own label.
		 *
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portal-page-designer/spec.md#requirement-form-fields-must-be-added-inside-a-form-widget-req-snw-003
		 */
		isGroup(field) {
			if (this.isDatePicker(field)) {
				return false
			}
			return ['date', 'radio', 'checkboxes'].includes(field.type)
		},

		/**
		 * @param {*} value A declared number.
		 * @param {*} fallback What to use when it is not one.
		 * @return {*} The number, or the fallback.
		 *
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portal-page-designer/spec.md#requirement-form-fields-must-be-added-inside-a-form-widget-req-snw-003
		 */
		numberOr(value, fallback) {
			return typeof value === 'number' && Number.isFinite(value) ? value : fallback
		},

		/**
		 * @param {object} field A radio or checkboxes field.
		 * @param {string} option One of its options.
		 * @return {boolean} Whether the visitor chose it.
		 *
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portal-page-designer/spec.md#requirement-form-fields-must-be-added-inside-a-form-widget-req-snw-003
		 */
		isChosen(field, option) {
			return chosenList(field, this.values[field.id]).includes(option)
		},

		/**
		 * Record a choice: a radio keeps one option, checkboxes a list.
		 *
		 * @param {object} field A radio or checkboxes field.
		 * @param {string} option The option.
		 * @param {boolean} checked Whether it is now ticked.
		 * @return {void}
		 *
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portal-page-designer/spec.md#requirement-form-fields-must-be-added-inside-a-form-widget-req-snw-003
		 */
		choose(field, option, checked) {
			this.values[field.id] = chosenValue(field, this.values[field.id], option, checked)
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
		 * @spec openspec/changes/archive/2026-10-07-create-names-its-action/tasks.md#T3
		 */
		async submit() {
			this.status = null
			this.errors = formErrors(this.fields, this.values)
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
				await submitLandingPageForm(this.values, campaignOf(this.portal), this.formId)
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

.pq-form__choices {
	display: flex;
	flex-direction: column;
	gap: var(--utrecht-space-block-sm, 0.5rem);
}

.pq-form__choice {
	display: flex;
	align-items: center;
	gap: var(--utrecht-space-inline-sm, 0.5rem);
}

.pq-form__date-picker {
	max-inline-size: 12rem;
}

.pq-form__affixed {
	display: flex;
	align-items: center;
	gap: var(--utrecht-space-inline-sm, 0.5rem);
}

.pq-form__consent,
.pq-form__status {
	margin-block-start: var(--utrecht-space-block-md, 1rem);
}
</style>
