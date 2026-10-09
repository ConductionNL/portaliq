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
			v-else-if="state === 'signIn'"
			class="utrecht-paragraph"
			data-testid="intake-form-sign-in">
			{{
				feeAmountText
					? text.signInFee.split('{amount}').join(feeAmountText)
					: signInLabel
			}}
		</p>

		<div v-else-if="state === 'external'" data-testid="intake-form-external">
			<p class="utrecht-paragraph">
				{{ externalLabel }}
				<strong>{{ render.destination }}</strong>
			</p>
			<a
				class="utrecht-button-link utrecht-button-link--html-a utrecht-button-link--primary-action"
				:href="render.externalUrl"
				rel="noopener"
				data-testid="intake-form-external-start">
				{{ startLabel }}
			</a>
		</div>

		<FormIntro
			v-else-if="state === 'intro'"
			:intro="render.settings && render.settings.intro"
			:formName="hideFormName ? '' : render.formName || ''"
			@start="state = 'form'" />

		<div v-else-if="state === 'done'" data-testid="intake-form-done">
			<h2
				ref="doneHeading"
				class="utrecht-heading-2"
				tabindex="-1"
				data-testid="intake-form-done-heading">
				{{ confirmationPage.title }}
			</h2>
			<p
				v-if="confirmationPage.body"
				class="utrecht-paragraph"
				data-testid="intake-form-done-body">
				{{ confirmationPage.body }}
			</p>
			<p class="utrecht-paragraph" role="status">
				{{ referenceLabel }}
				<strong data-testid="intake-form-reference">{{ reference }}</strong>
			</p>
			<p class="utrecht-paragraph">
				{{ keepReferenceLabel }}
			</p>
			<p
				v-if="feeAmountText"
				class="utrecht-paragraph"
				data-testid="intake-form-fee">
				{{ text.feeLine.split('{amount}').join(feeAmountText) }}
			</p>
			<p
				v-if="paymentWords"
				class="utrecht-paragraph"
				role="status"
				data-testid="intake-form-payment">
				{{ text[paymentWords.key] }}
			</p>
			<p
				v-if="payFailed"
				class="utrecht-paragraph"
				role="alert"
				data-testid="intake-form-pay-error">
				{{ text.payUnavailable }}
			</p>
			<p v-if="canPay">
				<button
					type="button"
					class="utrecht-button utrecht-button--primary-action"
					:disabled="paying"
					data-testid="intake-form-pay"
					@click="pay">
					{{ text.payNow.split('{amount}').join(feeAmountText) }}
				</button>
			</p>
			<p
				v-if="mailedTo"
				class="utrecht-paragraph"
				data-testid="intake-form-mailed">
				{{ text.mailedTo.split('{email}').join(mailedTo) }}
			</p>
			<template v-if="confirmationPage.next.length > 0">
				<h3 class="utrecht-heading-3">
					{{ text.whatNow }}
				</h3>
				<ol data-testid="intake-form-next-steps">
					<li v-for="(step, index) in confirmationPage.next" :key="index">
						<strong v-if="step.title">{{ step.title }}</strong>
						{{ step.text }}
					</li>
				</ol>
			</template>
			<p>
				<button
					type="button"
					class="utrecht-button utrecht-button--secondary-action"
					data-testid="intake-form-print"
					@click="print">
					{{ text.print }}
				</button>
			</p>
		</div>

		<form
			v-else-if="state === 'form'"
			class="pq-intake-form__form"
			novalidate
			@submit.prevent="onSubmit">
			<h2 v-if="render.formName && !hideFormName" class="utrecht-heading-2">
				{{ render.formName }}
			</h2>

			<template v-if="hasSteps">
				<FormProgress
					:steps="flow"
					:current="stepIndex"
					idBase="pq-intake-progress"
					:label="text.progress"
					:shortPattern="text.stepOf"
					:showLabel="text.showSteps"
					:hideLabel="text.hideSteps"
					:doneLabel="text.stepDone"
					:currentLabel="text.stepCurrent"
					:todoLabel="text.stepTodo" />
				<h3
					ref="stepHeading"
					class="utrecht-heading-3 pq-intake-form__step-heading"
					tabindex="-1"
					data-testid="intake-form-step-heading">
					{{ stepHeadingText }}
				</h3>
				<p v-if="currentStep.description" class="utrecht-paragraph">
					{{ currentStep.description }}
				</p>
			</template>

			<p
				v-if="explainOptional"
				class="utrecht-paragraph pq-intake-form__note"
				data-testid="intake-form-optional-note">
				{{ text.optionalNote }}
			</p>

			<ErrorSummary
				ref="summary"
				:entries="summary"
				idBase="pq-intake-summary"
				:heading="text.summaryHeading"
				:intro="text.summaryIntro"
				:titlePrefix="text.titlePrefix" />

			<ReviewList
				v-if="onReview"
				:sections="reviewSections"
				:editLabel="text.change"
				:editPattern="text.changeStep"
				:emptyLabel="text.notAnswered"
				@edit="editStep" />

			<FieldShell
				v-for="field in shownFields"
				:id="elementId(field)"
				:key="field.name"
				v-slot="{ describedBy }"
				class="pq-intake-form__field"
				:label="field.label || field.name"
				:required="field.required === true"
				:optionalLabel="text.optional"
				:help="helpOf(field)"
				:error="errors[field.name] || ''"
				:group="isDate(field)"
				:errorTestid="`intake-field-error-${field.name}`">
				<RepeatingGroup
					v-if="field.type === 'group'"
					:id="elementId(field)"
					v-model="values[field.name]"
					:field="field"
					:invalid="!!errors[field.name]"
					:testid="`intake-field-${field.name}`" />
				<output
					v-else-if="isComputedField(field)"
					:id="elementId(field)"
					class="utrecht-paragraph pq-intake-form__output"
					:data-testid="`intake-field-${field.name}`">
					{{ computedText(field) }}
				</output>
				<AddressNL
					v-else-if="field.type === 'addressNL'"
					:id="elementId(field)"
					v-model="values[field.name]"
					:houseLetter="field.houseLetter === true"
					:lookup="
						render.settings && render.settings.addressLookup === true
					"
					:base="apiBase"
					:invalid="!!errors[field.name]"
					:testid="`intake-field-${field.name}`" />
				<FamilyMembers
					v-else-if="field.type === 'familyMembers'"
					v-model="values[field.name]"
					:base="apiBase"
					:sameAddressOnly="field.sameAddressOnly !== false"
					:testid="`intake-field-${field.name}`" />
				<SignatureField
					v-else-if="field.type === 'signature'"
					v-model="values[field.name]"
					:invalid="!!errors[field.name]"
					:testid="`intake-field-${field.name}`" />
				<EmailCodeField
					v-else-if="field.type === 'email' && field.verify === true"
					:id="elementId(field)"
					v-model="values[field.name]"
					:base="apiBase"
					:route="bindingRoute"
					:portal="portal"
					:invalid="!!errors[field.name]"
					:testid="`intake-field-${field.name}`"
					@verified="onVerified" />
				<DateInputGroup
					v-else-if="isDate(field)"
					:id="elementId(field)"
					v-model="values[field.name]"
					:required="field.required === true"
					:invalid="!!errors[field.name]"
					:testid="`intake-field-${field.name}`" />

				<select
					v-else-if="Array.isArray(field.options) && field.options.length"
					:id="elementId(field)"
					v-model="values[field.name]"
					class="utrecht-select"
					:aria-required="field.required === true ? 'true' : undefined"
					:aria-invalid="errors[field.name] ? 'true' : 'false'"
					:aria-labelledby="`${elementId(field)}-label`"
					:aria-describedby="describedBy"
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
					:aria-required="field.required === true ? 'true' : undefined"
					:aria-invalid="errors[field.name] ? 'true' : 'false'"
					:aria-labelledby="`${elementId(field)}-label`"
					:aria-describedby="describedBy"
					:data-testid="`intake-field-${field.name}`" />

				<input
					v-else
					:id="elementId(field)"
					v-model="values[field.name]"
					class="utrecht-textbox"
					:type="inputType(field)"
					:aria-required="field.required === true ? 'true' : undefined"
					:aria-invalid="errors[field.name] ? 'true' : 'false'"
					:aria-labelledby="`${elementId(field)}-label`"
					:aria-describedby="describedBy"
					:data-testid="`intake-field-${field.name}`" />
			</FieldShell>

			<StatementsBlock
				v-if="statementsShown"
				v-model="accepted"
				:statements="render.statements"
				:errors="statementErrors" />

			<div class="pq-intake-form__buttons">
				<button
					v-if="hasSteps && stepIndex > 0"
					type="button"
					class="utrecht-button utrecht-button--secondary-action"
					data-testid="intake-form-previous"
					@click="previousStep">
					{{ text.previousStep }}
				</button>
				<button
					v-if="hasSteps && !onReview"
					type="submit"
					class="utrecht-button utrecht-button--primary-action"
					data-testid="intake-form-next">
					{{ text.nextStep }}
				</button>
				<button
					v-else
					type="submit"
					class="utrecht-button utrecht-button--primary-action"
					:disabled="submitting"
					data-testid="intake-form-submit">
					{{ submitLabel }}
				</button>
			</div>

			<p
				v-if="decisionDown"
				class="utrecht-paragraph pq-intake-form__error"
				role="alert"
				data-testid="intake-form-decision-down">
				{{ text.decisionDown }}
				<button
					type="button"
					class="utrecht-button utrecht-button--subtle"
					:disabled="deciding"
					data-testid="intake-form-decision-retry"
					@click="nextStep">
					{{ text.retry }}
				</button>
			</p>

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
import { evaluateVisibleWhenLocal } from '@conduction/nextcloud-vue/src/utils/visibleWhen.js'
import AddressNL from './forms/AddressNL.vue'
import DateInputGroup from './forms/DateInputGroup.vue'
import EmailCodeField from './forms/EmailCodeField.vue'
import ErrorSummary from './forms/ErrorSummary.vue'
import FamilyMembers from './forms/FamilyMembers.vue'
import FieldShell from './forms/FieldShell.vue'
import FormIntro from './forms/FormIntro.vue'
import FormProgress from './forms/FormProgress.vue'
import RepeatingGroup from './forms/RepeatingGroup.vue'
import ReviewList from './forms/ReviewList.vue'
import SignatureField from './forms/SignatureField.vue'
import StatementsBlock from './forms/StatementsBlock.vue'
import { adoptSessionToken, authBaseFrom } from '../lib/authApi.js'
import { resolveApiBase } from '../lib/contentApi.js'
import {
	bindingRouteFrom,
	decideStep as decideStepOnServer,
	initialValues,
	loadForm,
	lookUpStatus,
	payIntake,
	submitIntake,
} from '../lib/intakeApi.js'
import { addressLine, addressProblem } from './forms/address.js'
import { calculatedValues } from './forms/calculate.js'
import {
	confirmationView,
	introView,
	missingStatements,
} from './forms/confirmation.js'
import {
	DUTCH,
	explainsOptional,
	plainFieldErrors,
	summaryEntries,
} from './forms/fields.js'
import { groupCountErrors, itemLines } from './forms/group.js'
import { identityWords } from './forms/identityWords.js'
import { feeAmount, paymentView, returnedReference } from './forms/payment.js'
import stepFlow from './forms/stepFlow.js'
import { stepHeading } from './forms/steps.js'

// The start link of an external form is a button link.
import '@utrecht/button-link-css/dist/index.css'

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
 *
 * The fields follow the NL Design System form guidance (site-multi-step-forms
 * REQ-SMF-001 to 003): "(niet verplicht)" on optional fields and
 * `aria-required` on required ones, a date as day, month and year, and an
 * error summary above the fields whose heading takes focus. The client
 * checks only empty required fields and impossible dates; the server's
 * refusal per field lands in the same summary.
 *
 * @spec openspec/changes/site-multi-step-forms/specs/site-forms/spec.md#requirement-a-failed-submit-must-show-an-error-summary-that-takes-focus-req-smf-002
 */
export default {
	name: 'IntakeFormBlock',

	components: {
		AddressNL,
		DateInputGroup,
		FamilyMembers,
		FormIntro,
		StatementsBlock,
		ErrorSummary,
		FieldShell,
		FormProgress,
		RepeatingGroup,
		ReviewList,
		SignatureField,
		EmailCodeField,
	},

	mixins: [stepFlow],

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
			accepted: [],
			verifiedEmails: {},
			statementErrors: {},
			confirmation: null,
			mailedTo: '',
			payment: null,
			paying: false,
			payFailed: false,
		}
	},

	computed: {
		/**
		 * The values of the calculated fields, worked out from the answers so far.
		 * For display only: the server works them out again on submit.
		 *
		 * @return {Record<string, number|string>} The value per calculated field.
		 *
		 * @spec openspec/changes/form-flow-repeating-groups-calculations-and-decisions/tasks.md#t04
		 */
		calculated() {
			return calculatedValues(this.fields, this.values)
		},

		/**
		 * The fee in words, from the form's render or the sign-in refusal.
		 *
		 * @return {string} "€ 45,00", or '' for a free request.
		 *
		 * @spec openspec/changes/intake-pay-on-submit/tasks.md#t06
		 */
		feeAmountText() {
			return feeAmount(
				this.render.fee,
				typeof document === 'undefined'
					? 'nl'
					: document.documentElement?.lang,
			)
		},

		/**
		 * What the page says about the payment.
		 *
		 * @return {object|null} `{key, again}`.
		 *
		 * @spec openspec/changes/intake-pay-on-submit/tasks.md#t06
		 */
		paymentWords() {
			return paymentView(this.payment)
		},

		/**
		 * Whether "Pay now" shows: a fee, and no payment that went through.
		 *
		 * @return {boolean}
		 *
		 * @spec openspec/changes/intake-pay-on-submit/tasks.md#t06
		 */
		canPay() {
			return (
				this.feeAmountText !== ''
				&& this.reference !== ''
				&& (this.paymentWords === null || this.paymentWords.again === true)
			)
		},

		/**
		 * The statements show on the review step, or at the end of a one-page form.
		 *
		 * @return {boolean}
		 *
		 * @spec openspec/changes/form-statements-intro-and-confirmation-mail/tasks.md#t03
		 */
		statementsShown() {
			return (
				Array.isArray(this.render.statements)
				&& this.render.statements.length > 0
				&& (!this.hasSteps || this.onReview)
			)
		},

		/**
		 * What the confirmation page says.
		 *
		 * @return {object} `{title, body, next}`.
		 *
		 * @spec openspec/changes/form-statements-intro-and-confirmation-mail/tasks.md#t04
		 */
		confirmationPage() {
			return confirmationView(
				this.confirmation,
				this.confirmationText,
				{ reference: this.reference, deadline: '' },
				this.doneLabel,
			)
		},

		/** The portal api base the address lookup asks. */
		/**
		 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t01
		 */
		apiBase() {
			return authBaseFrom(resolveApiBase())
		},

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
		 * The rendered fields that carry a name.
		 *
		 * @return {Array<object>} The fields.
		 *
		 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md#requirement-the-citizens-entry-point-is-composed-content-listing-the-published-catalogue-req-pifo-006
		 */
		fields() {
			return (
				Array.isArray(this.render.fields) ? this.render.fields : []
			).filter((field) => field && field.name)
		},

		/**
		 * The fields on screen: the step's, or every field of a one-page form.
		 *
		 * @return {Array<object>} The fields.
		 *
		 * @spec openspec/changes/site-multi-step-forms/specs/portal-intake-form/spec.md#requirement-a-published-form-with-steps-must-be-filled-in-one-step-at-a-time-with-visible-progress-req-smf-010
		 */
		shownFields() {
			const shown = this.fields.filter((field) =>
				this.isShownField(field.name),
			)
			if (!this.hasSteps) {
				return shown
			}
			if (this.onReview) {
				return []
			}
			return shown.filter((field) =>
				this.currentStep.fields.includes(field.name),
			)
		},

		/**
		 * The steps the form render carries.
		 *
		 * @return {Array<object>|undefined} The steps.
		 *
		 * @spec openspec/changes/site-multi-step-forms/specs/portal-intake-form/spec.md#requirement-a-published-form-with-steps-must-be-filled-in-one-step-at-a-time-with-visible-progress-req-smf-010
		 */
		rawSteps() {
			return this.render.steps
		},

		/**
		 * The titles of the loose step and of an undeclared review.
		 *
		 * @return {{other: string, review: string}} The titles.
		 *
		 * @spec openspec/changes/site-multi-step-forms/specs/portal-intake-form/spec.md#requirement-a-form-with-steps-must-end-with-a-review-and-a-confirmation-req-smf-011
		 */
		stepTitles() {
			return {
				other: this.text.otherQuestions,
				review: this.text.checkAndSend,
			}
		},

		/**
		 * "Stap 2 van 4: periode en documenten".
		 *
		 * @return {string} The heading.
		 *
		 * @spec openspec/changes/site-multi-step-forms/specs/portal-intake-form/spec.md#requirement-a-published-form-with-steps-must-be-filled-in-one-step-at-a-time-with-visible-progress-req-smf-010
		 */
		stepHeadingText() {
			return stepHeading(
				this.text.stepHeading,
				this.stepIndex + 1,
				this.flow.length,
				this.currentStep.title,
			)
		},

		/**
		 * The review: each answer under its question, per step.
		 *
		 * @return {Array<object>} The sections.
		 *
		 * @spec openspec/changes/site-multi-step-forms/specs/portal-intake-form/spec.md#requirement-a-form-with-steps-must-end-with-a-review-and-a-confirmation-req-smf-011
		 */
		reviewSections() {
			const byName = Object.fromEntries(
				this.fields.map((field) => [field.name, field]),
			)
			return this.flow
				.map((step, index) => ({
					index,
					title: step.title,
					rows: step.fields
						.filter((name) => byName[name] && this.isShownField(name))
						.map((name) => ({
							field: name,
							label: byName[name].label || name,
							value: this.answerText(byName[name]),
						})),
				}))
				.filter((section) => section.rows.length > 0)
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
			return (
				!this.onReview
				&& explainsOptional(
					this.shownFields.map((field) => field.required === true),
				)
			)
		},

		/**
		 * The error summary's lines: client and server errors, in field order.
		 *
		 * @return {Array<{field: string, target: string, message: string}>} The lines.
		 *
		 * @spec openspec/changes/site-multi-step-forms/specs/site-forms/spec.md#requirement-a-failed-submit-must-show-an-error-summary-that-takes-focus-req-smf-002
		 */
		summary() {
			return summaryEntries(
				this.fields.map((field) => field.name),
				this.errors,
				(name) => this.elementId({ name }),
			)
		},
	},

	watch: {
		/**
		 * Keep the calculated fields' values in step with the answers, so the
		 * review shows them.
		 *
		 * @param {Record<string, number|string>} now The calculated values.
		 * @return {void}
		 *
		 * @spec openspec/changes/form-flow-repeating-groups-calculations-and-decisions/tasks.md#t04
		 */
		calculated(now) {
			for (const field of this.fields) {
				if (field.calculate) {
					this.values[field.name] = Object.hasOwn(now, field.name)
						? String(now[field.name])
						: ''
				}
			}
		},

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
				this.state =
					view.state === 'form' && introView(view.render.settings?.intro)
						? 'intro'
						: view.state
				await this.showReturnedPayment()
			} catch {
				this.state = 'notFound'
			}
		},

		/**
		 * Send the answers and show the reference.
		 *
		 * @return {Promise<void>} Resolves when answered.
		 *
		 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md#requirement-the-case-is-created-asynchronously-and-the-citizen-gets-a-reference-at-once-req-pifo-005
		 */
		async submit() {
			this.sendFailed = false
			const wrong = this.checkFields(
				this.fields
					.filter((field) => this.isShownField(field.name))
					.map((field) => field.name),
			)
			if (Object.keys(wrong).length > 0) {
				this.showErrors(wrong)
				return
			}
			this.errors = {}
			const missing = missingStatements(this.render.statements, this.accepted)
			this.statementErrors = Object.fromEntries(
				missing.map((key) => [key, this.text.statementRequired]),
			)
			if (missing.length > 0) {
				return
			}

			this.submitting = true
			try {
				const outcome = await submitIntake(
					authBaseFrom(resolveApiBase()),
					this.bindingRoute,
					this.values,
					this.portal,
					adoptSessionToken(),
					null,
					this.accepted,
					this.verifiedEmails,
				)
				if (outcome.reference === '') {
					const messages = this.messagesOf(outcome.errors)
					this.statementErrors = {}
					for (const key of Object.keys(messages)) {
						if (key.startsWith('statement-')) {
							this.statementErrors[key.slice(10)] = messages[key]
							delete messages[key]
						}
					}
					this.showErrors(messages)
					return
				}

				this.reference = outcome.reference
				this.confirmationText = outcome.confirmationText
				this.confirmation = outcome.confirmation
				this.mailedTo = outcome.mailedTo
				this.state = 'done'
				this.$nextTick(() => {
					if (this.$refs.doneHeading) {
						this.$refs.doneHeading.focus()
					}
				})
			} catch {
				this.sendFailed = true
			} finally {
				this.submitting = false
			}
		},

		/**
		 * When the resident comes back from the payment page, show their
		 * reference and what the payment record says, not what the address says.
		 *
		 * @return {Promise<void>} Resolves when shown or when there is nothing to show.
		 *
		 * @spec openspec/changes/intake-pay-on-submit/tasks.md#t05
		 */
		async showReturnedPayment() {
			const reference = returnedReference(
				typeof window === 'undefined' ? '' : window.location.search,
			)
			if (this.state !== 'form' || reference === '' || !this.render.fee) {
				return
			}
			const status = await lookUpStatus(
				authBaseFrom(resolveApiBase()),
				reference,
				this.portal,
			)
			if (!status || !status.reference) {
				return
			}
			this.reference = status.reference
			this.payment = status.payment || null
			this.state = 'done'
		},

		/**
		 * Take the resident to the payment page. The browser sends no amount.
		 * The top window navigates, because a payment page in an iframe is
		 * refused by most providers.
		 *
		 * @return {Promise<void>} Resolves when navigating or when it failed.
		 *
		 * @spec openspec/changes/intake-pay-on-submit/tasks.md#t06
		 */
		async pay() {
			this.payFailed = false
			this.paying = true
			const result = await payIntake(
				authBaseFrom(resolveApiBase()),
				this.reference,
				this.portal,
				adoptSessionToken(),
			)
			this.paying = false
			if (!result.ok) {
				this.payFailed = true
				return
			}
			window.top.location.assign(result.checkoutUrl)
		},

		/**
		 * Print the confirmation page.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/form-statements-intro-and-confirmation-mail/tasks.md#t04
		 */
		print() {
			window.print()
		},

		/**
		 * The server's refusal per field as one message each: a list of
		 * messages is joined, anything that is not text is left out.
		 *
		 * @param {object} errors The server's `errors`.
		 * @return {Record<string, string>} The message per field.
		 *
		 * @spec openspec/changes/site-multi-step-forms/specs/site-forms/spec.md#requirement-a-failed-submit-must-show-an-error-summary-that-takes-focus-req-smf-002
		 */
		messagesOf(errors) {
			const out = {}
			for (const [name, message] of Object.entries(errors || {})) {
				const text = Array.isArray(message)
					? message.filter((m) => typeof m === 'string').join(' ')
					: typeof message === 'string'
						? message
						: ''
				if (text !== '') {
					out[name] = text
				}
			}
			return out
		},

		/**
		 * Whether a field shows: its `visibleWhen`, a local condition over the
		 * answers so far, decides. The server repeats the same check on submit.
		 *
		 * @param {string} name The field's name.
		 * @return {boolean} True to show it.
		 *
		 * @spec openspec/changes/intake-conditional-questions-and-drafts/specs/portal-intake-form/spec.md#requirement-a-fields-condition-decides-whether-the-resident-sees-it-req-icq-001
		 */
		isShownField(name) {
			const field = this.fields.find((entry) => entry.name === name)
			if (!field || !field.visibleWhen) {
				return true
			}
			return evaluateVisibleWhenLocal(field.visibleWhen, this.values)
		},

		/**
		 * The client errors of some fields: empty required ones and
		 * impossible dates.
		 *
		 * @param {string[]} names The field names.
		 * @return {Record<string, string>} The errors.
		 *
		 * @spec openspec/changes/site-multi-step-forms/specs/portal-intake-form/spec.md#requirement-a-published-form-with-steps-must-be-filled-in-one-step-at-a-time-with-visible-progress-req-smf-010
		 */
		checkFields(names) {
			const asked = this.fields.filter((field) => names.includes(field.name))
			const addresses = asked.filter((field) => field.type === 'addressNL')
			const errors = this.checkPlainFields(names)
			Object.assign(errors, groupCountErrors(asked, this.values))
			for (const field of addresses) {
				const block = this.values[field.name]
				const problem = addressProblem(block)
				if (
					problem !== ''
					&& (field.required === true || addressLine(block) !== '')
				) {
					errors[field.name] = problem
				}
			}
			for (const field of asked) {
				const address = String(this.values[field.name] ?? '')
					.trim()
					.toLowerCase()
				if (
					field.type === 'email'
					&& field.verify === true
					&& address !== ''
					&& !errors[field.name]
					&& !this.verifiedEmails[address]
				) {
					errors[field.name] = identityWords(
						document.documentElement?.lang,
					).emailVerifyFirst
				}
			}
			return errors
		},

		/**
		 * An address was verified, or its proof was dropped because it changed.
		 *
		 * @param {{address: string, proof: string}} verified The address and its proof, '' to drop.
		 * @return {void}
		 *
		 * @spec openspec/changes/resident-identity-in-forms/tasks.md#t03
		 */
		onVerified(verified) {
			const address = String(verified?.address ?? '')
				.trim()
				.toLowerCase()
			if (address === '') {
				return
			}
			if (verified.proof) {
				this.verifiedEmails = {
					...this.verifiedEmails,
					[address]: verified.proof,
				}
				return
			}
			const rest = { ...this.verifiedEmails }
			delete rest[address]
			this.verifiedEmails = rest
		},

		/**
		 * The plain checks (required, date, format) over the names given.
		 *
		 * @param {string[]} names The field names.
		 * @return {Record<string, string>} The errors.
		 *
		 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t02
		 */
		checkPlainFields(names) {
			const values = { ...this.values }
			for (const field of this.fields) {
				if (field.type === 'addressNL') {
					values[field.name] = addressLine(values[field.name])
				}
				if (field.type === 'familyMembers') {
					values[field.name] = (values[field.name] || []).join(',')
				}
			}
			return plainFieldErrors(
				this.fields
					.filter((field) => names.includes(field.name))
					.filter(
						(field) =>
							field.type !== 'group' && !this.isComputedField(field),
					)
					.map((field) => ({
						name: field.name,
						label: field.label || field.name,
						required: field.required === true,
						date: this.isDate(field),
						format: typeof field.format === 'string' ? field.format : '',
					})),
				values,
			)
		},

		/**
		 * An answer as the review shows it: the option's label, a date as
		 * written in Dutch, or the text.
		 *
		 * @param {object} field The field.
		 * @return {string} The answer.
		 *
		 * @spec openspec/changes/site-multi-step-forms/specs/portal-intake-form/spec.md#requirement-a-form-with-steps-must-end-with-a-review-and-a-confirmation-req-smf-011
		 */
		answerText(field) {
			if (field.type === 'signature') {
				return this.values[field.name]
					? identityWords(document.documentElement?.lang).signatureSet
					: ''
			}
			if (field.type === 'addressNL') {
				return addressLine(this.values[field.name])
			}
			if (field.type === 'familyMembers') {
				return (this.values[field.name] || []).length + ''
			}
			if (field.type === 'group') {
				return (this.values[field.name] || [])
					.map((item) => {
						const lines = itemLines(field, item)
						return [lines.first, lines.rest].filter(Boolean).join(', ')
					})
					.join('; ')
			}
			const value = String(this.values[field.name] ?? '')
			const option = (Array.isArray(field.options) ? field.options : []).find(
				(entry) => String(entry?.value ?? entry) === value,
			)
			if (option !== undefined) {
				return String(option?.label ?? option)
			}
			if (this.isDate(field) && /^\d{4}-\d{2}-\d{2}$/.test(value)) {
				const [year, month, day] = value.split('-').map(Number)
				return new Intl.DateTimeFormat('nl', {
					day: 'numeric',
					month: 'long',
					year: 'numeric',
				}).format(new Date(year, month - 1, day))
			}
			return value
		},

		/**
		 * Whether the server fills a field: a calculation or a decision's output.
		 *
		 * @param {object} field The field.
		 * @return {boolean} True for a read-only, computed line.
		 *
		 * @spec openspec/changes/form-flow-repeating-groups-calculations-and-decisions/tasks.md#t04
		 */
		isComputedField(field) {
			return Boolean(field.calculate) || field.computed === true
		},

		/**
		 * What a computed line says: its value, or that it cannot be worked out yet.
		 *
		 * @param {object} field The field.
		 * @return {string} The text.
		 *
		 * @spec openspec/changes/form-flow-repeating-groups-calculations-and-decisions/tasks.md#t04
		 */
		computedText(field) {
			const text = this.answerText(field)
			return text === '' ? this.text.notCalculated : text
		},

		/**
		 * Ask the server to decide a step that decides, and keep the outcome in
		 * the field the decision fills. A throw means the rule engine is down.
		 *
		 * @param {object} step The step.
		 * @return {Promise<{nextStep: string}>} The decision.
		 *
		 * @spec openspec/changes/form-flow-repeating-groups-calculations-and-decisions/tasks.md#t06
		 */
		async decideStep(step) {
			const decided = await decideStepOnServer(
				authBaseFrom(resolveApiBase()),
				this.bindingRoute,
				step.id,
				this.values,
				this.portal,
				adoptSessionToken(),
			)
			if (decided.output !== '' && decided.outcome !== '') {
				this.values[decided.output] = decided.outcome
			}
			return decided
		},

		/**
		 * The form's submit: "Volgende stap" on a step, sending on the review
		 * or on a one-page form.
		 *
		 * @return {Promise<void>|void}
		 *
		 * @spec openspec/changes/site-multi-step-forms/specs/portal-intake-form/spec.md#requirement-a-published-form-with-steps-must-be-filled-in-one-step-at-a-time-with-visible-progress-req-smf-010
		 */
		onSubmit() {
			if (this.hasSteps && !this.onReview) {
				return this.nextStep()
			}
			return this.submit()
		},

		/**
		 * Move focus to the error summary's heading.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/site-multi-step-forms/specs/site-forms/spec.md#requirement-a-failed-submit-must-show-an-error-summary-that-takes-focus-req-smf-002
		 */
		focusSummary() {
			if (this.$refs.summary) {
				this.$refs.summary.focus()
			}
		},

		/**
		 * Whether a field is a date, asked as day, month and year.
		 *
		 * @param {object} field The field.
		 * @return {boolean} True for `type: date`.
		 *
		 * @spec openspec/changes/site-multi-step-forms/specs/site-forms/spec.md#requirement-a-date-field-must-be-asked-as-day-month-and-year-req-smf-003
		 */
		isDate(field) {
			return field.type === 'date'
		},

		/**
		 * The description under a field's label: its own, else a date's example.
		 *
		 * @param {object} field The field.
		 * @return {string} The description, or ''.
		 *
		 * @spec openspec/changes/site-multi-step-forms/specs/site-forms/spec.md#requirement-a-date-field-must-be-asked-as-day-month-and-year-req-smf-003
		 */
		helpOf(field) {
			if (typeof field.description === 'string' && field.description !== '') {
				return field.description
			}
			return this.isDate(field) ? this.text.dateHint : ''
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
.pq-intake-form__note {
	margin-block-end: var(--utrecht-space-block-md, 1rem);
}

.pq-intake-form__field + .pq-intake-form__field,
.pq-intake-form__buttons,
.pq-intake-form__error {
	margin-block-start: var(--utrecht-space-block-md, 1rem);
}
</style>
