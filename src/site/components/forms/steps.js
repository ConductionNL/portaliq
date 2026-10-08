// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * The decisions behind a form in steps, without Vue, so `node --test`
 * asserts them on their own (tests/site-form-steps.spec.mjs). Published
 * forms (IntakeFormBlock) and create and endpoint actions (SchemaForm) run
 * the same flow: one step at a time, a review last, then a confirmation.
 *
 * The server already kept only steps that name known fields and put loose
 * fields in a step of their own (FormStepsNormaliser); these helpers only
 * shape what the screen shows.
 *
 * @spec openspec/changes/site-multi-step-forms/specs/portal-intake-form/spec.md#requirement-a-published-form-with-steps-must-be-filled-in-one-step-at-a-time-with-visible-progress-req-smf-010
 */

/**
 * The steps the screen walks through: the declared ones, the loose step
 * titled, and a review step last when none is declared.
 *
 * @param {Array<object>|undefined} steps The steps from the server.
 * @param {{other: string, review: string}} titles The fallback titles.
 * @return {Array<{id: string, title: string, description?: string, fields: string[], review: boolean}>} The steps, or [] for a one-page form.
 *
 * @spec openspec/changes/site-multi-step-forms/specs/portal-intake-form/spec.md#requirement-a-form-with-steps-must-end-with-a-review-and-a-confirmation-req-smf-011
 */
export function flowSteps(steps, titles) {
	const list = (Array.isArray(steps) ? steps : []).filter(
		(step) => step && typeof step === 'object',
	)
	const withFields = list.filter(
		(step) => step.review !== true && Array.isArray(step.fields),
	)
	if (withFields.length === 0) {
		return []
	}
	const out = withFields.map((step) => ({
		id: String(step.id || ''),
		title:
			typeof step.title === 'string' && step.title !== ''
				? step.title
				: titles.other,
		...(typeof step.description === 'string' && step.description !== ''
			? { description: step.description }
			: {}),
		fields: step.fields.filter((field) => typeof field === 'string'),
		review: false,
	}))
	const review = list.find((step) => step.review === true)
	out.push({
		id: review && review.id ? String(review.id) : 'review',
		title:
			review && typeof review.title === 'string' && review.title !== ''
				? review.title
				: titles.review,
		fields: [],
		review: true,
	})
	return out
}

/**
 * The step a move lands on: the next or previous step that shows at least
 * one field (a review always shows), skipping steps whose fields are all
 * hidden.
 *
 * @param {Array<object>} steps The flow's steps.
 * @param {number} from The current step's index.
 * @param {number} direction 1 forward, -1 back.
 * @param {(field: string) => boolean} isShown Whether a field shows.
 * @return {number} The index to move to; `from` when there is none.
 *
 * @spec openspec/changes/site-multi-step-forms/specs/portal-intake-form/spec.md#requirement-a-published-form-with-steps-must-be-filled-in-one-step-at-a-time-with-visible-progress-req-smf-010
 */
export function stepTo(steps, from, direction, isShown) {
	for (let at = from + direction; at >= 0 && at < steps.length; at += direction) {
		const step = steps[at]
		if (step.review || step.fields.some((field) => isShown(field))) {
			return at
		}
	}
	return from
}

/**
 * The step heading, "Stap 2 van 4: periode en documenten": the title's first
 * letter lowered, unless the title starts with an abbreviation.
 *
 * @param {string} pattern The words with `{n}`, `{m}` and `{title}`.
 * @param {number} number The step's number, from 1.
 * @param {number} total How many steps.
 * @param {string} title The step's title.
 * @return {string} The heading.
 *
 * @spec openspec/changes/site-multi-step-forms/specs/portal-intake-form/spec.md#requirement-a-published-form-with-steps-must-be-filled-in-one-step-at-a-time-with-visible-progress-req-smf-010
 */
export function stepHeading(pattern, number, total, title) {
	const text = String(title || '')
	const lowered =
		text.length > 1 && text[1] === text[1].toLowerCase()
			? text.charAt(0).toLowerCase() + text.slice(1)
			: text
	return String(pattern)
		.split('{n}')
		.join(String(number))
		.split('{m}')
		.join(String(total))
		.split('{title}')
		.join(lowered)
}

/**
 * Only the errors of one step's fields, so the summary lists that step.
 *
 * @param {Record<string, string>} errors The errors of the whole form.
 * @param {object|undefined} step The step.
 * @return {Record<string, string>} The step's errors.
 *
 * @spec openspec/changes/site-multi-step-forms/specs/portal-intake-form/spec.md#requirement-a-published-form-with-steps-must-be-filled-in-one-step-at-a-time-with-visible-progress-req-smf-010
 */
export function stepErrors(errors, step) {
	const fields = step && Array.isArray(step.fields) ? step.fields : []
	return Object.fromEntries(
		Object.entries(errors || {}).filter(([field]) => fields.includes(field)),
	)
}

/**
 * The first step that holds one of the errors, for a refusal on send.
 *
 * @param {Array<object>} steps The flow's steps.
 * @param {Record<string, string>} errors The errors.
 * @return {number} The step's index, or -1.
 */
export function firstStepWithError(steps, errors) {
	const names = Object.keys(errors || {})
	return steps.findIndex(
		(step) => !step.review && step.fields.some((field) => names.includes(field)),
	)
}

/**
 * A confirmation text with `{identifier}`, `{deadline}` and any other
 * `{name}` filled from the answer. A sentence whose placeholder has no value
 * is left out whole, so the resident never reads "uiterlijk  antwoord".
 *
 * @param {string} text The text.
 * @param {object|null} answer What the app answered.
 * @return {string} The filled text.
 *
 * @spec openspec/changes/site-multi-step-forms/specs/portal-contribution-contract/spec.md#requirement-a-create-or-endpoint-action-may-word-its-own-confirmation-req-smf-022
 */
export function confirmationText(text, answer) {
	const values = answer && typeof answer === 'object' ? answer : {}
	const filled = (name) => {
		const value = values[name]
		return value === undefined || value === null ? '' : String(value).trim()
	}
	const sentences = String(text || '').match(/[^.!?]+[.!?]*\s*/g) || []
	return sentences
		.filter((sentence) =>
			[...sentence.matchAll(/\{([A-Za-z][A-Za-z0-9_]*)\}/g)].every(
				(match) => filled(match[1]) !== '',
			),
		)
		.map((sentence) =>
			sentence.replace(/\{([A-Za-z][A-Za-z0-9_]*)\}/g, (all, name) =>
				filled(name),
			),
		)
		.join('')
		.trim()
}

/**
 * Where a resumed draft opens: the first step with a missing required answer,
 * else the review (REQ-SMF-012).
 *
 * @param {Array<object>} steps The flow's steps, a review last.
 * @param {(fields: string[]) => Record<string, string>} check The errors of some fields.
 * @param {(field: string) => boolean} isShown Whether a field shows.
 * @return {number} The step's index.
 *
 * @spec openspec/changes/site-multi-step-forms/specs/portal-intake-form/spec.md#requirement-save-and-resume-must-sit-in-the-step-navigation-req-smf-012
 */
export function landingStep(steps, check, isShown) {
	const at = steps.findIndex(
		(step) =>
			!step.review
			&& Object.keys(check(step.fields.filter((field) => isShown(field))))
				.length > 0,
	)
	return at >= 0 ? at : Math.max(0, steps.length - 1)
}

/**
 * The date a draft is kept until, in the page's language.
 *
 * @param {string} iso The draft's `expiresAt`.
 * @param {string} locale The page's language.
 * @return {string} The date, or '' when it cannot be read.
 */
export function retentionDate(iso, locale) {
	const date = new Date(iso)
	if (Number.isNaN(date.getTime())) {
		return ''
	}
	return date.toLocaleDateString(locale || 'nl', {
		day: 'numeric',
		month: 'long',
		year: 'numeric',
	})
}
