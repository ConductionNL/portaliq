// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// An action field that declares `prefill: name` or `prefill: email` starts
// from the signed-in resident's own name or e-mail address. Only an empty
// field is filled, so a saved draft, a preset or what the resident typed
// stays. Pure functions, so a node test pins each rule.
//
// @spec openspec/specs/portal-contribution-contract/spec.md#requirement-an-action-field-may-start-from-the-signed-in-residents-name-or-e-mail-address

/** The profile value each prefill source reads (`identity/details`). */
const SOURCES = { name: 'displayName', email: 'email' }

/**
 * The fields of an action that declare a known prefill source.
 *
 * @param {object} action The normalised action.
 * @return {Array<[string, string]>} `[field, profile key]` pairs.
 */
export function prefillFields(action) {
	const configs = (action && action.fieldConfigs) || {}
	return Object.keys(configs)
		.filter((field) => Object.hasOwn(SOURCES, configs[field]?.prefill))
		.map((field) => [field, SOURCES[configs[field].prefill]])
}

/**
 * The fields the profile filled: empty before, with a value after.
 *
 * @param {Record<string, string>} before The values before the prefill.
 * @param {Record<string, string>} after The values after it.
 * @param {object} action The normalised action.
 * @return {string[]} The fields, in the action's prefill order.
 */
export function filledFields(before, after, action) {
	return prefillFields(action)
		.map(([field]) => field)
		.filter(
			(field) =>
				String(before[field] ?? '').trim() === ''
				&& String(after[field] ?? '').trim() !== '',
		)
}

/**
 * The values with each empty prefill field taken from the profile.
 *
 * @param {Record<string, string>} values The form's values.
 * @param {object} action The normalised action.
 * @param {object|null} profile The resident's details, or null.
 * @return {Record<string, string>} New values; the input is not changed.
 */
export function withProfile(values, action, profile) {
	const out = { ...values }
	for (const [field, key] of prefillFields(action)) {
		const value =
			profile && typeof profile[key] === 'string' ? profile[key].trim() : ''
		if (value !== '' && String(out[field] ?? '').trim() === '') {
			out[field] = value
		}
	}
	return out
}
