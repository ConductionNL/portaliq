// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// Where an unfinished form opens again (form-save-and-resume-dialogs).

import { ACCOUNT_ROUTE } from '../../../shared/portalNav.js'

/**
 * The route of an action's form in the own area.
 *
 * @param {{app: string, action: string}} draft A live draft.
 * @return {string}
 * @spec openspec/changes/form-save-and-resume-dialogs/specs/site-forms/spec.md#requirement-the-own-area-lists-unfinished-forms-req-fsr-003
 */
export function formRoute(draft) {
	return `${ACCOUNT_ROUTE}/${encodeURIComponent(draft.app)}/${encodeURIComponent(draft.action)}`
}
