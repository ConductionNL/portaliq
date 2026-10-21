// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import { translate as t } from '@nextcloud/l10n'

/**
 * The audiences a clerk picks from most when inviting someone or issuing an
 * account. The audience set is open, so a value outside this list is kept
 * as its own option.
 *
 * @return {Array<{id: string, label: string}>} The options.
 * @spec openspec/specs/portal-account-administration/spec.md#requirement-staff-issue-and-withdraw-accounts-through-the-validated-actions-req-isa-003
 */
export function audienceOptions() {
	return [
		{ id: 'citizen', label: t('portaliq', 'Resident') },
		{ id: 'client', label: t('portaliq', 'Client') },
		{ id: 'organisation', label: t('portaliq', 'Business') },
		{ id: 'supplier', label: t('portaliq', 'Supplier') },
	]
}

/**
 * The option for an audience value, or an option of its own for a value the
 * list does not know.
 *
 * @param {string} id The audience.
 * @return {{id: string, label: string}} The option.
 * @spec openspec/specs/portal-account-administration/spec.md#requirement-staff-issue-and-withdraw-accounts-through-the-validated-actions-req-isa-003
 */
export function audienceOption(id) {
	return audienceOptions().find((option) => option.id === id) || { id, label: id }
}

/**
 * The audience value an NcSelect hands back: an option, or a typed string.
 *
 * @param {object|string|null} option The selected option.
 * @return {string} The audience.
 * @spec openspec/specs/portal-account-administration/spec.md#requirement-staff-issue-and-withdraw-accounts-through-the-validated-actions-req-isa-003
 */
export function audienceValue(option) {
	return typeof option === 'string' ? option : option?.id || ''
}
