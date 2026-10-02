/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Which questions of an intake form a resident sees right now, and which of
 * their answers go to the server (intake-conditional-questions-and-drafts,
 * REQ-ICQ-001, REQ-ICQ-002).
 *
 * The condition is nextcloud-vue's own local-mode predicate, imported rather
 * than copied, so the site and the server (lib/Service/Intake/VisibleWhenLocal.php,
 * held to the same fixture) answer alike. It lives apart from intakeApi.js
 * because the predicate's imports read browser state when loaded, and the
 * API helpers are imported by node tests that set up no window.
 */

import { evaluateVisibleWhenLocal } from '@conduction/nextcloud-vue/src/utils/visibleWhen.js'

/**
 * Walk the fields in declared order, as PortalFormValidator::validate() does:
 * a hidden field's answer leaves the data, so a field that depends on it
 * hides too, while a condition on a later field reads its answer.
 *
 * @param {Array<object>} fields The rendered fields, in declared order.
 * @param {object} values The answers so far, keyed by field name.
 * @return {{fields: Array<object>, answers: object}} The shown fields and their answers.
 */
function walk(fields, values) {
	const answers = { ...(values || {}) }
	const shown = []
	for (const field of Array.isArray(fields) ? fields : []) {
		if (!field || !field.name) {
			continue
		}
		if (evaluateVisibleWhenLocal(field.visibleWhen ?? null, answers) !== true) {
			delete answers[field.name]
			continue
		}
		shown.push(field)
	}
	return { fields: shown, answers }
}

/**
 * The fields a resident sees for the answers so far.
 *
 * @param {Array<object>} fields The rendered fields, in declared order.
 * @param {object} values The answers so far.
 * @return {Array<object>} The fields to show.
 *
 * @spec openspec/changes/intake-conditional-questions-and-drafts/specs/portal-intake-form/spec.md#requirement-a-fields-condition-decides-whether-the-resident-sees-it-req-icq-001
 */
export function shownFields(fields, values) {
	return walk(fields, values).fields
}

/**
 * The answers to send: those of shown fields only.
 *
 * @param {Array<object>} fields The rendered fields, in declared order.
 * @param {object} values The answers so far.
 * @return {object} The answers of shown fields.
 *
 * @spec openspec/changes/intake-conditional-questions-and-drafts/specs/portal-intake-form/spec.md#requirement-the-server-skips-a-hidden-field-req-icq-002
 */
export function shownAnswers(fields, values) {
	return walk(fields, values).answers
}
