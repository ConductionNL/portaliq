// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The embed frame's boot data and its one request (site-reaches-portal-parity
// REQ-SRP-047). Framework-free, so tests/embed-frame.spec.mjs runs it in node.
//
// templates/embed.php owns its whole document (RENDER_AS_BLANK, like
// site.php) and writes the boot data into a JSON block. It used to hand the
// payload over through Nextcloud's initial-state channel, which only a
// Nextcloud LAYOUT prints, and RENDER_AS_BLANK has none: the frame served a
// bare <div> with no script and no data at all.

export const CONFIG_ID = 'portaliq-embed-config'
export const MOUNT_ID = 'portaliq-embed'

/**
 * Read the frame's boot data from the page.
 *
 * Never throws: a missing or broken block gives a frame that says the form
 * cannot be shown, rather than a blank rectangle on somebody's website.
 *
 * @param {Document|null} doc The document.
 * @return {{payload: object, submitUrl: string, locale: string}} The boot data.
 *
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-the-embed-frame-must-render-its-form-from-a-small-entry-req-srp-047
 */
export function readEmbedConfig(doc) {
	const fallback = {
		payload: { refused: 'form_not_found' },
		submitUrl: '',
		locale: 'nl',
	}
	let parsed
	try {
		parsed = JSON.parse(doc?.getElementById(CONFIG_ID)?.textContent || 'null')
	} catch {
		parsed = null
	}

	if (
		parsed === null
		|| typeof parsed !== 'object'
		|| typeof parsed.payload !== 'object'
		|| parsed.payload === null
	) {
		return fallback
	}

	return {
		payload: parsed.payload,
		submitUrl: typeof parsed.submitUrl === 'string' ? parsed.submitUrl : '',
		locale:
			typeof parsed.locale === 'string' && parsed.locale !== ''
				? parsed.locale
				: 'nl',
	}
}

/**
 * The answers a form starts with: each field's preset, so a preset the
 * visitor leaves alone is still sent.
 *
 * @param {Array<object>} fields The form's fields.
 * @return {object} Field name to value.
 *
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-the-embed-frame-must-render-its-form-from-a-small-entry-req-srp-047
 */
export function initialAnswers(fields) {
	const answers = {}
	for (const field of Array.isArray(fields) ? fields : []) {
		if (
			field?.name
			&& field.preset !== undefined
			&& field.preset !== null
			&& field.preset !== ''
		) {
			answers[field.name] = String(field.preset)
		}
	}

	return answers
}

/**
 * Send the answers to the frame's submit route.
 *
 * @param {object} args Arguments.
 * @param {string} args.submitUrl The submit route.
 * @param {string} args.route The form page being answered.
 * @param {object} args.answers The answers.
 * @param {Function} [args.fetchImpl] fetch (test seam).
 * @return {Promise<object>} The server's answer: `{reference, …}` or `{errors}`.
 *
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-the-embed-frame-must-render-its-form-from-a-small-entry-req-srp-047
 */
export async function submitAnswers({
	submitUrl,
	route,
	answers,
	fetchImpl = globalThis.fetch,
}) {
	const response = await fetchImpl(submitUrl, {
		method: 'POST',
		headers: { 'Content-Type': 'application/json' },
		body: JSON.stringify({ route, answers }),
	})

	return await response.json()
}

/**
 * The errors in a refused submission, per field and as one list.
 *
 * The server answers `{errors: {field: message}}` (PortalFormValidator), and
 * the React frame mapped over that object as if it were a list, so a refused
 * submission threw instead of saying why. A list is accepted as well.
 *
 * @param {object} result The server's answer.
 * @return {{fields: object, messages: Array<string>}} Per field, and all of them.
 *
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-the-embed-frame-must-render-its-form-from-a-small-entry-req-srp-047
 */
export function submissionErrors(result) {
	const raw = result?.errors
	const fields = {}
	const messages = []
	if (Array.isArray(raw)) {
		for (const message of raw) {
			messages.push(String(message))
		}
	} else if (raw !== null && typeof raw === 'object') {
		for (const [name, message] of Object.entries(raw)) {
			fields[name] = String(message)
			messages.push(String(message))
		}
	}

	return { fields, messages }
}
