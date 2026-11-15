// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

import {
	firstStepWithError,
	flowSteps,
	stepErrors,
	stepIndexById,
	stepTo,
} from './steps.js'

/**
 * @param {object} vm The component running the flow.
 * @return {Array<object>} The fields of the step on screen that show.
 */
function shownFields(vm) {
	return vm.currentStep.fields.filter((field) => vm.isShownField(field))
}

/**
 * @param {object} vm The component running the flow.
 * @return {boolean} Whether the step on screen decides, and the component can ask.
 */
function decidesHere(vm) {
	return vm.currentStep.decides === true && typeof vm.decideStep === 'function'
}

/**
 * The step "Volgende stap" opens: the review when the step was opened from
 * it, else the next shown step, unless a deciding step names another.
 *
 * @param {object} vm The component running the flow.
 * @return {Promise<number|null>} The step's index, or null while the rule engine is down.
 */
async function nextTarget(vm) {
	const planned = vm.backToReview
		? vm.flow.length - 1
		: stepTo(vm.flow, vm.stepIndex, 1, vm.isShownField)
	return decidesHere(vm) ? decidedTarget(vm, planned) : planned
}

/**
 * A step that decides asks the rule engine on the server; the step its
 * outcome names opens next. The engine being down keeps the answers and
 * offers a retry (form-flow-repeating-groups-calculations-and-decisions
 * REQ-FFL-003).
 *
 * @param {object} vm The component running the flow.
 * @param {number} planned The step that opens when the outcome names none.
 * @return {Promise<number|null>} The step's index, or null when the engine is down.
 */
async function decidedTarget(vm, planned) {
	vm.decisionDown = false
	vm.deciding = true
	try {
		const decided = await vm.decideStep(vm.currentStep)
		const named = stepIndexById(vm.flow, decided && decided.nextStep)
		return named >= 0 && !vm.backToReview ? named : planned
	} catch {
		vm.decisionDown = true
		return null
	} finally {
		vm.deciding = false
	}
}

/**
 * The step flow both form renderers share (site-multi-step-forms T6, T7b):
 * one step at a time, "Volgende stap" checks only the step's shown fields,
 * "Vorige stap" never checks, a step whose fields are all hidden is skipped
 * both ways, focus moves to the step heading, and a step opened from the
 * review returns to the review.
 *
 * The component supplies:
 * - `rawSteps` (computed): the steps the server sent, or none;
 * - `stepTitles` (computed): `{other, review}`, the fallback titles;
 * - `isShownField(field)`: whether a field shows;
 * - `checkFields(fields)`: the errors of those fields, `{field: message}`;
 * - `errors` (data) and `focusSummary()`, the error summary's;
 * - `decideStep(step)` (optional): asks the server to decide a step that
 *   `decides`, answering `{nextStep}`; a throw means the rule engine is down.
 * It puts `ref="stepHeading"` on the step heading.
 *
 * @spec openspec/changes/site-multi-step-forms/specs/portal-intake-form/spec.md#requirement-a-published-form-with-steps-must-be-filled-in-one-step-at-a-time-with-visible-progress-req-smf-010
 */
export default {
	data() {
		return {
			stepIndex: 0,
			backToReview: false,
			deciding: false,
			decisionDown: false,
		}
	},

	computed: {
		/**
		 * The steps walked through, a review last; [] for a one-page form.
		 *
		 * @return {Array<object>} The steps.
		 */
		flow() {
			return flowSteps(this.rawSteps, this.stepTitles)
		},

		/**
		 * Whether the form runs in steps.
		 *
		 * @return {boolean} True with steps.
		 */
		hasSteps() {
			return this.flow.length > 0
		},

		/**
		 * The step on screen, or null without steps.
		 *
		 * @return {object|null} The step.
		 */
		currentStep() {
			return this.hasSteps ? this.flow[this.stepIndex] || this.flow[0] : null
		},

		/**
		 * Whether the review is on screen.
		 *
		 * @return {boolean} True on the review.
		 */
		onReview() {
			return this.currentStep !== null && this.currentStep.review === true
		},
	},

	methods: {
		/**
		 * "Volgende stap": check the step's shown fields; stay with the
		 * summary when one fails, else move on (or back to the review).
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/site-multi-step-forms/specs/portal-intake-form/spec.md#requirement-a-published-form-with-steps-must-be-filled-in-one-step-at-a-time-with-visible-progress-req-smf-010
		 */
		async nextStep() {
			this.errors = this.checkFields(shownFields(this))
			if (Object.keys(this.errors).length > 0) {
				this.$nextTick(() => this.focusSummary())
				return
			}
			const target = await nextTarget(this)
			if (target === null) {
				return
			}
			this.backToReview = false
			this.openStep(target)
		},

		/**
		 * "Vorige stap": move back without checking anything.
		 *
		 * @return {void}
		 */
		previousStep() {
			this.errors = {}
			this.backToReview = false
			this.openStep(stepTo(this.flow, this.stepIndex, -1, this.isShownField))
		},

		/**
		 * "Wijzigen" on the review: open that step; its "Volgende stap" comes
		 * back to the review.
		 *
		 * @param {number} index The step's index.
		 * @return {void}
		 *
		 * @spec openspec/changes/site-multi-step-forms/specs/portal-intake-form/spec.md#requirement-a-form-with-steps-must-end-with-a-review-and-a-confirmation-req-smf-011
		 */
		editStep(index) {
			this.errors = {}
			this.backToReview = true
			this.openStep(index)
		},

		/**
		 * Show one step and put focus on its heading.
		 *
		 * @param {number} index The step's index.
		 * @return {void}
		 */
		openStep(index) {
			this.stepIndex = index
			this.$nextTick(() => {
				if (this.$refs.stepHeading) {
					this.$refs.stepHeading.focus()
				}
			})
		},

		/**
		 * After a refusal on send: open the first step that holds an error
		 * and keep only that step's errors, so the summary lists that step.
		 *
		 * @param {Record<string, string>} errors The errors of the whole form.
		 * @return {void}
		 *
		 * @spec openspec/changes/site-multi-step-forms/specs/site-forms/spec.md#requirement-a-failed-submit-must-show-an-error-summary-that-takes-focus-req-smf-002
		 */
		showErrors(errors) {
			if (!this.hasSteps) {
				this.errors = errors
			} else {
				const at = firstStepWithError(this.flow, errors)
				if (at >= 0) {
					this.stepIndex = at
					this.backToReview = true
					this.errors = stepErrors(errors, this.flow[at])
				} else {
					// An error on no step's field is still shown, unlinked.
					this.errors = errors
				}
			}
			this.$nextTick(() => this.focusSummary())
		},
	},
}
